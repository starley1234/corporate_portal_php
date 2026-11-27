<?php
use PHPUnit\Framework\TestCase;

/**
 * prx399 на настоящих PostgreSQL, prx120 и prx212: сценарии пакета
 * сериализованы локом (399, id), опоздавший воркер не переписывает новый
 * цикл, файлы живут в Storage::, а самолечение работает по расписанию.
 *
 * Без внешней транзакции (сервисы открывают свои) — уборка в finally.
 */
class DocMergeLifecycleTest extends TestCase
{
    private array $contracts = [];
    private array $files = [];

    private function actor(): Prx_Actor
    {
        return new Prx_Actor(1, ['admin']);
    }

    /** Пакет: base (с AST) + одна ДС; файлы — настоящие записи prx212. */
    private function contract(string $status, string $file_status = 'parsed'): array
    {
        $contract = Model_Prx399_Contract::insert([
            'title' => 'smoke '.bin2hex(random_bytes(3)), 'focus' => 'balanced',
            'policy' => '{"policy":""}', 'created_by' => 1,
        ]);
        $this->contracts[] = (int) $contract['id'];
        Model_Prx399_Contract::set_status($contract['id'], $status);

        $ids = [];
        foreach ([['base', 0], ['ds', 1]] as [$kind, $ordinal])
        {
            $file_id = Storage::put("%PDF-1.4 smoke\n", $kind.'.pdf', Service_Prx399_Contracts::MODULE, NULL, FALSE, 'application/pdf');
            $this->files[] = $file_id;
            $row = Model_Prx399_File::insert(['contract_id' => $contract['id'], 'kind' => $kind,
                'ordinal' => $ordinal, 'original_name' => $kind.'.pdf', 'file_id' => $file_id]);
            Model_Prx399_File::set_status($row['id'], $file_status);
            $ids[$kind] = (int) $row['id'];
        }
        Model_Prx399_File::set_base_result($ids['base'], 1, str_repeat('a', 64), ['nodes' => [
            ['path' => '1', 'title' => 'ПРЕДМЕТ', 'text' => 'Поставщик передаёт товар.', 'status' => 'base'],
            ['path' => '1.1', 'title' => '', 'text' => 'Срок поставки — 10 дней, см. п. 1.', 'status' => 'base'],
        ]]);
        if ($file_status !== 'parsed') Model_Prx399_File::set_status($ids['base'], $file_status);

        return ['id' => (int) $contract['id'], 'base' => $ids['base'], 'ds' => $ids['ds']];
    }

    private function contract_status(int $id): string
    {
        return (string) Model_Prx399_Contract::get($id)['status'];
    }

    public function test_maintain_builds_package_whose_last_result_was_lost(): void
    {
        try
        {
            // Все файлы разобраны, а сборки не было (результат последнего файла
            // записан, но _maybe_build упал) — раньше лечилось только открытием карточки.
            $c = $this->contract('parsing');
            $report = (new Service_Prx399_Contracts)->maintain();
            $this->assertSame(0, $report['errors']);
            $this->assertGreaterThanOrEqual(1, $report['reconciled']);
            $contract = Model_Prx399_Contract::get($c['id']);
            $this->assertSame('auditing', $contract['status']);
            $this->assertNotEmpty($contract['doc_tree']);
            $this->assertGreaterThan(0, (int) $contract['audit_job_id']);
        }
        finally
        {
            $this->cleanup();
        }
    }

    public function test_late_audit_result_does_not_overwrite_new_cycle(): void
    {
        try
        {
            $pipeline = new Service_Prx399_Pipeline;
            $c = $this->contract('failed');
            Model_Prx399_Risk::insert(['contract_id' => $c['id'], 'severity' => 'low', 'area' => NULL, 'chunk_path' => NULL,
                'title' => 'Риск текущей редакции', 'detail' => NULL, 'recommendation' => NULL, 'source' => 'llm']);

            $late = ['summary' => 'Старый паспорт', 'risks' => [['severity' => 'high', 'title' => 'Риск старой редакции']]];
            $this->assertSame(['ok' => TRUE, 'stale' => TRUE], $pipeline->audit_result($c['id'], $late));
            $this->assertSame('failed', $this->contract_status($c['id']));
            $this->assertSame(['Риск текущей редакции'], array_column(Model_Prx399_Risk::for_contract($c['id']), 'title'));

            Model_Prx399_Contract::set_status($c['id'], 'auditing');
            $this->assertSame(['ok' => TRUE, 'risks' => 1], $pipeline->audit_result($c['id'], $late));
            $this->assertSame('audited', $this->contract_status($c['id']));
            $this->assertSame(['Риск старой редакции'], array_column(Model_Prx399_Risk::for_contract($c['id']), 'title'));

            // risks не массив — честный 400, а не warning foreach → 503.
            $other = $this->contract('auditing');
            try {
                $pipeline->audit_result($other['id'], ['summary' => 'x', 'risks' => 'не массив']);
                $this->fail('ожидался invalid_risks');
            } catch (Prx399_Error $e) {
                $this->assertSame('invalid_risks', $e->getMessage());
            }
        }
        finally
        {
            $this->cleanup();
        }
    }

    public function test_late_delta_result_for_failed_file_is_ignored(): void
    {
        try
        {
            $c = $this->contract('failed', 'failed');
            $result = (new Service_Prx399_Pipeline)->delta_result($c['ds'], ['pages' => 1, 'ops' => []]);
            $this->assertSame(['ok' => TRUE, 'stale' => TRUE], $result);
            $this->assertSame('failed', (string) Model_Prx399_File::get($c['ds'])['status']);
        }
        finally
        {
            $this->cleanup();
        }
    }

    public function test_retry_only_failed_file_and_launch_is_not_doubled(): void
    {
        try
        {
            $service = new Service_Prx399_Contracts;
            $c = $this->contract('failed');
            try {
                $service->retry_file($this->actor(), $c['ds']);
                $this->fail('повтор разобранного файла затёр бы дельты');
            } catch (Prx399_Error $e) {
                $this->assertSame('wrong_state', $e->getMessage());
            }

            Model_Prx399_File::set_status($c['ds'], 'failed', 'сбой');
            $service->retry_file($this->actor(), $c['ds']);
            $file = Model_Prx399_File::get($c['ds']);
            $this->assertSame('queued', (string) $file['status']);
            $this->assertGreaterThan(0, (int) $file['job_id']);
            $this->assertSame('parsing', $this->contract_status($c['id']));

            Model_Prx399_Contract::set_status($c['id'], 'draft');
            $service->launch($this->actor(), $c['id']);
            try {
                $service->launch($this->actor(), $c['id']);
                $this->fail('второй запуск поставил бы задачи дважды');
            } catch (Prx399_Error $e) {
                $this->assertSame('wrong_state', $e->getMessage());
            }
            $jobs = Prx_Db::one("SELECT count(*) AS n FROM prx_120_jobs WHERE channel = 'doc.merge.delta'
                AND (payload->>'contract_id')::bigint = :id", [':id' => $c['id']]);
            $this->assertSame(3, (int) $jobs['n']); // retry + launch на 2 файла
        }
        finally
        {
            $this->cleanup();
        }
    }

    public function test_delete_removes_storage_files_after_commit(): void
    {
        try
        {
            $c = $this->contract('audited');
            $file_ids = array_map('intval', array_column(Model_Prx399_File::for_contract($c['id']), 'file_id'));
            $this->assertCount(2, $file_ids);
            $referenced = Model_Prx399_File::referenced_files($file_ids);
            sort($referenced);
            sort($file_ids);
            $this->assertSame($file_ids, $referenced); // порядок строк без ORDER BY не гарантирован

            (new Service_Prx399_Contracts)->delete($this->actor(), $c['id']);
            $this->assertNull(Model_Prx399_Contract::get($c['id']));
            foreach ($file_ids as $file_id)
            {
                $this->assertNull(Storage::bytes($file_id), 'файл prx212 #'.$file_id.' остался');
            }
            $this->assertSame([], Model_Prx399_File::referenced_files($file_ids));
        }
        finally
        {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        foreach ($this->contracts as $id)
        {
            Prx_Db::execute("DELETE FROM prx_120_jobs WHERE channel IN ('doc.merge.delta', 'doc.merge.audit')
                AND (payload->>'contract_id')::bigint = :id", [':id' => $id]);
            Model_Prx399_Contract::delete($id);
        }
        foreach ($this->files as $file_id)
        {
            try { Storage::delete_owned($file_id, Service_Prx399_Contracts::MODULE); } catch (Throwable $e) {}
        }
        $this->contracts = $this->files = [];
    }
}
