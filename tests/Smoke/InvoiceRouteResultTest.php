<?php
use PHPUnit\Framework\TestCase;

/**
 * Приём результата воркера prx397 (счета) и prx398 (маршрутизация) на
 * настоящих PostgreSQL и prx120: PHP — граница доверия. Неизвестный код
 * отказа не попадает ни в БД, ни клиенту; битый UTF-8 от OCR/LLM не роняет
 * запись jsonb; тип/маршрут вне словаря — на ручную проверку; смерть
 * воркера клиент видит как failed/worker_lost, а не «отменено».
 *
 * Без внешней транзакции (сервисы пишут сами) — уборка в finally.
 */
class InvoiceRouteResultTest extends TestCase
{
    private array $jobs = [];

    private function processing_job(string $channel, string $module): int
    {
        $job_id = (int) Service_Prx120_JobQueue::push($channel, [], ['module' => $module, 'max_attempts' => 2]);
        $this->jobs[] = $job_id;
        Prx_Db::execute("UPDATE prx_120_jobs SET status = 'processing', attempts = 1, claimed_by = 'smoke',
            claimed_at = now(), heartbeat_at = now() WHERE id = :id", [':id' => $job_id]);

        return $job_id;
    }

    private function register(string $table, int $job_id): void
    {
        Prx_Db::execute('INSERT INTO '.$table.' (job_id, owner_id, file_id, request_key, file_sha256, status, expires_at)
            VALUES (:j, -999999, 0, :k, :s, \'queued\', now() + interval \'1 hour\')',
            [':j' => $job_id, ':k' => hash('sha256', 'k'.$job_id), ':s' => hash('sha256', 's'.$job_id)]);
    }

    private static function public_status_of(object $service, string $db_status, ?array $job): string
    {
        $method = new ReflectionMethod($service, 'public_status');
        $method->setAccessible(TRUE);

        return $method->invoke($service, $db_status, $job);
    }

    public function test_invoice_amounts_follow_russian_decimal_comma(): void
    {
        $n = static fn ($v) => Service_Prx397_Extraction::normalize_fields(['total' => $v])['total'];
        $this->assertSame(1.5, $n('1,500'));             // 1,5 кг — не полторы тысячи
        $this->assertSame(1500.0, $n('1 500,00 ₽'));
        $this->assertSame(1000000.0, $n('1.000.000,00'));
        $this->assertSame(1000000.0, $n('1,000,000'));
        $this->assertSame(1000.5, $n('1,000.50'));
        $this->assertSame(-12.0, $n('−12'));
        $this->assertNull($n('двенадцать'));
    }

    public function test_invoice_result_boundary_and_worker_death(): void
    {
        try
        {
            $service = new Service_Prx397_Extraction;
            $job_id = $this->processing_job(Service_Prx397_Extraction::CHANNEL, Service_Prx397_Extraction::MODULE);
            $this->register('prx_397_invoices', $job_id);

            // Битый UTF-8 из OCR/LLM — запись проходит, символ заменён.
            $stored = $service->store_result($job_id, 1, ['fields' => [
                'number' => "СЧ-1\xFF", 'date' => '27.11.2026', 'total' => '1,500',
                'seller' => ['name' => 'ООО Ромашка', 'inn' => '7707083893'], 'buyer' => ['name' => 'АО Покупатель'],
                'lines' => [['name' => 'Болт', 'qty' => '1,500', 'sum' => '1,5']],
            ], 'confidence' => 0.9]);
            $this->assertContains($stored['status'], ['done', 'review']);
            $row = Prx_Db::one('SELECT fields::text AS f, error_code FROM prx_397_invoices WHERE job_id = :j', [':j' => $job_id]);
            $fields = json_decode($row['f'], TRUE);
            $this->assertSame("СЧ-1\u{FFFD}", $fields['number']);
            $this->assertEquals(1.5, $fields['total']);

            // Произвольная строка воркера не становится кодом ошибки.
            $failed = $service->store_result($job_id, 1, ['error_code' => str_repeat('x', 500).' /etc/passwd']);
            $this->assertSame('job_failed', $failed['error_code']);
            $this->assertSame('job_failed', Prx_Db::one('SELECT error_code FROM prx_397_invoices WHERE job_id = :j', [':j' => $job_id])['error_code']);
            $this->assertSame('no_text', $service->store_result($job_id, 1, ['error_code' => 'no_text'])['error_code']);

            // Чужая попытка — lease_lost.
            try {
                $service->store_result($job_id, 2, ['error_code' => 'no_text']);
                $this->fail('ожидался lease_lost');
            } catch (Prx397_Error $e) {
                $this->assertSame('lease_lost', $e->getMessage());
            }

            // Обслуживание остановило задачу умершего воркера.
            $lost = ['status' => 'cancelled', 'error' => 'Воркер не отвечал больше 240 с'];
            $this->assertSame('failed', self::public_status_of($service, 'queued', $lost));
            $this->assertSame('worker_lost', Service_Prx397_Extraction::error_code($lost));
            $this->assertSame('canceled', self::public_status_of($service, 'queued', ['status' => 'cancelled', 'error' => 'Отменено']));
        }
        finally
        {
            $this->cleanup('prx_397_invoices');
        }
    }

    public function test_route_result_accepts_only_known_types_and_routes(): void
    {
        try
        {
            $service = new Service_Prx398_Route;
            $job_id = $this->processing_job(Service_Prx398_Route::CHANNEL, Service_Prx398_Route::MODULE);
            $this->register('prx_398_routes', $job_id);
            $row = static fn () => Prx_Db::one('SELECT status, doc_type, route, attempts, error_code FROM prx_398_routes WHERE job_id = :j', [':j' => $job_id]);

            $this->assertSame(['status' => 'done'], $service->store_result($job_id, 1,
                ['status' => 'done', 'doc_type' => 'invoice', 'route' => 'erp', 'confidence' => 0.97, 'attempts' => 1]));
            $this->assertSame(['done', 'invoice', 'erp'], array_values(array_slice($row(), 0, 3)));

            // Тип вне словаря (длиннее VARCHAR(32)) и маршрут не по типу — на ручную проверку, без SQL-ошибки.
            $this->assertSame(['status' => 'review'], $service->store_result($job_id, 1,
                ['status' => 'done', 'doc_type' => str_repeat('malware', 10), 'route' => 'erp', 'attempts' => 99999]));
            $r = $row();
            $this->assertSame(['review', NULL, NULL, 1000], [$r['status'], $r['doc_type'], $r['route'], (int) $r['attempts']]);
            $this->assertSame(['status' => 'review'], $service->store_result($job_id, 1,
                ['status' => 'done', 'doc_type' => 'contract', 'route' => 'erp', 'state' => ['text' => "битый \xC3"]]));
            $this->assertSame(['review', 'contract', NULL], array_values(array_slice($row(), 0, 3)));

            $this->assertSame('job_failed', $service->store_result($job_id, 1, ['error_code' => 'DROP TABLE'])['error_code']);
            $this->assertSame('graph_unavailable', $service->store_result($job_id, 1, ['error_code' => 'graph_unavailable'])['error_code']);

            $this->assertSame('failed', self::public_status_of($service, 'running',
                ['status' => 'failed', 'error' => 'Воркер перезапускался на этой задаче 3 раза']));
            $this->assertSame('worker_crash_loop', Service_Prx398_Route::error_code(
                ['status' => 'failed', 'error' => 'Воркер перезапускался на этой задаче 3 раза']));
        }
        finally
        {
            $this->cleanup('prx_398_routes');
        }
    }

    private function cleanup(string $table): void
    {
        foreach ($this->jobs as $job_id)
        {
            Prx_Db::execute('DELETE FROM '.$table.' WHERE job_id = :j', [':j' => $job_id]);
            Prx_Db::execute('DELETE FROM prx_120_jobs WHERE id = :j', [':j' => $job_id]);
        }
        $this->jobs = [];
    }
}
