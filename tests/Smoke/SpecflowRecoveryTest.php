<?php
use PHPUnit\Framework\TestCase;

/**
 * prx400 после падения воркера, на настоящих PostgreSQL, prx120 и prx212:
 * обслуживание очереди останавливает замолчавшую задачу → prx400:purge сам
 * перезапускает разбор (PRX400_CRASH_RETRIES), затем честно падает с
 * worker_lost; «падает на этом файле» не повторяется; поздний результат
 * мёртвой попытки не записывается; ручной запуск обнуляет счётчик.
 *
 * Без внешней транзакции (сервис сам открывает Prx_Db::transaction) — уборка в finally.
 */
class SpecflowRecoveryTest extends TestCase
{
    private array $spec_ids = [];
    private array $file_ids = [];
    private ?int $user_id = NULL;

    private function queued_spec(int $owner): array
    {
        $pdf = "%PDF-1.4\n% recovery ".bin2hex(random_bytes(6))."\n%%EOF\n";
        $file_id = Storage::put($pdf, 'spec.pdf', Service_Prx400_Specs::MODULE, NULL, FALSE, 'application/pdf');
        $this->file_ids[] = $file_id;
        $spec_id = (int) Model_Prx400_Spec::insert([
            'owner_id' => $owner, 'original_name' => 'spec.pdf', 'file_id' => $file_id,
            'file_sha256' => hash('sha256', $pdf), 'parse_mode' => 'ocr',
        ])['id'];
        $this->spec_ids[] = $spec_id;
        $job_id = (int) Service_Prx120_JobQueue::push(Service_Prx400_Specs::CHANNEL,
            ['spec_id' => $spec_id, 'parse_mode' => 'ocr_vision', 'template_id' => 'spec_generic_v1', 'templates' => []],
            ['module' => Service_Prx400_Specs::MODULE, 'max_attempts' => 2]);
        Model_Prx400_Spec::queue($spec_id, $job_id, 'ocr_vision');

        return [$spec_id, $job_id];
    }

    /** Воркер взял задачу и умер: пульса нет 10 минут. */
    private function worker_died(int $job_id): void
    {
        Prx_Db::execute("UPDATE prx_120_jobs SET status = 'processing', attempts = 1, claimed_by = 'smoke-dead',
            claimed_at = now() - interval '10 minutes', heartbeat_at = now() - interval '10 minutes' WHERE id = :id", [':id' => $job_id]);
        $this->assertSame(1, Service_Prx120_JobQueue::instance()->auto_stop_silent($job_id));
    }

    private function job(int $job_id): array
    {
        return Service_Prx120_JobQueue::instance()->get($job_id);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_worker_crash_is_retried_then_reported_and_crash_loop_is_not(): void
    {
        $previous_token = getenv('WORKER_API_TOKEN');
        if (trim((string) $previous_token) === '') putenv('WORKER_API_TOKEN=smoke-token');
        $job_ids = [];
        try
        {
            $user = ORM::factory('User');
            $suffix = bin2hex(random_bytes(6));
            $user->username = 'specflow-rec-'.$suffix;
            $user->email = $suffix.'@example.test';
            $user->password = bin2hex(random_bytes(16));
            $user->create();
            $this->user_id = (int) $user->id;
            $admin = new Prx_Actor($this->user_id, ['admin']);
            $pipeline = new Service_Prx400_Pipeline;
            $service = new Service_Prx400_Specs;

            // ---- 1. Воркер умер посреди разбора: очередь остановила задачу, это не «отменено»
            [$spec_id, $lost_job] = $this->queued_spec(-$this->user_id);
            $job_ids[] = $lost_job;
            $this->worker_died($lost_job);
            $this->assertSame(Service_Prx120_JobQueue::STOP_WORKER_LOST, Service_Prx120_JobQueue::stop_reason($this->job($lost_job)));
            $this->assertSame('failed', $service->card($admin, $spec_id)['spec']['status']);
            $this->assertSame('queued', Model_Prx400_Spec::get($spec_id)['status'], 'GET не пишет');

            // ---- 2. prx400:purge перезапускает: новая задача с тем же режимом и шаблоном, за владельцем
            $report = $pipeline->maintain();
            $this->assertGreaterThanOrEqual(1, $report['recovered']);
            $spec = Model_Prx400_Spec::get($spec_id);
            $this->assertSame('queued', $spec['status']);
            $this->assertSame(1, (int) $spec['recoveries']);
            $new_job = (int) $spec['job_id'];
            $job_ids[] = $new_job;
            $this->assertNotSame($lost_job, $new_job);
            $job = $this->job($new_job);
            $payload = is_array($job['payload']) ? $job['payload'] : json_decode((string) $job['payload'], TRUE);
            $this->assertSame('pending', $job['status']);
            $this->assertSame(Service_Prx400_Specs::CHANNEL, $job['channel']);
            $this->assertSame(['spec_id' => $spec_id, 'parse_mode' => 'ocr_vision', 'template_id' => 'spec_generic_v1'],
                array_intersect_key($payload, ['spec_id' => 1, 'parse_mode' => 1, 'template_id' => 1]));
            $this->assertSame($this->user_id, (int) $job['created_by']);

            // Поздний результат мёртвой попытки не записывается.
            try {
                $pipeline->store_result($lost_job, 1, ['rows' => []]);
                $this->fail('результат потерянной задачи принят');
            } catch (Prx400_Error $e) {
                $this->assertSame('lease_lost', $e->getMessage());
            }

            // Повторный проход ничего не делает: запись ждёт живую задачу.
            $pipeline->maintain();
            $this->assertSame($new_job, (int) Model_Prx400_Spec::get($spec_id)['job_id']);

            // ---- 3. Воркер умер снова: лимит автоповторов (1) исчерпан — ошибка worker_lost
            $this->worker_died($new_job);
            $report = $pipeline->maintain();
            $this->assertGreaterThanOrEqual(1, $report['reconciled']);
            $spec = Model_Prx400_Spec::get($spec_id);
            $this->assertSame(['failed', 'worker_lost', $new_job], [$spec['status'], $spec['error'], (int) $spec['job_id']]);
            $this->assertStringContainsString('Воркер перестал отвечать', Prx400_Messages::text('worker_lost'));

            // ---- 4. Ручной запуск оператора обнуляет счётчик автоповторов
            $service->launch($admin, $spec_id);
            $spec = Model_Prx400_Spec::get($spec_id);
            $job_ids[] = (int) $spec['job_id'];
            $this->assertSame(['queued', 0], [$spec['status'], (int) $spec['recoveries']]);

            // ---- 5. Воркер падает на этом файле (python: перезапуски исчерпаны) — без автоповтора
            [$loop_spec, $loop_job] = $this->queued_spec(-$this->user_id);
            $job_ids[] = $loop_job;
            Prx_Db::execute("UPDATE prx_120_jobs SET status = 'failed', attempts = 2, finished_at = now(),
                error = 'Воркер перезапускался на этой задаче, попытки исчерпаны. Проверьте входные данные.' WHERE id = :id", [':id' => $loop_job]);
            $pipeline->maintain();
            $spec = Model_Prx400_Spec::get($loop_spec);
            $this->assertSame(['failed', 'worker_crash_loop', $loop_job, 0], [$spec['status'], $spec['error'], (int) $spec['job_id'], (int) $spec['recoveries']]);

            // ---- 6. Отмена человеком остаётся «отменено» и не повторяется
            [$cancel_spec, $cancel_job] = $this->queued_spec(-$this->user_id);
            $job_ids[] = $cancel_job;
            Service_Prx120_JobQueue::instance()->cancel($cancel_job, TRUE);
            $pipeline->maintain();
            $spec = Model_Prx400_Spec::get($cancel_spec);
            $this->assertSame(['failed', 'job_canceled', 0], [$spec['status'], $spec['error'], (int) $spec['recoveries']]);
        }
        finally
        {
            foreach ($this->spec_ids as $id) Prx_Db::execute('DELETE FROM prx_400_specs WHERE id = :id', [':id' => $id]);
            foreach ($this->file_ids as $id) { try { Storage::delete_owned($id, Service_Prx400_Specs::MODULE); } catch (Throwable $e) {} }
            foreach ($this->spec_ids as $id) {
                Prx_Db::execute("DELETE FROM prx_120_jobs WHERE channel = :c AND payload::jsonb ->> 'spec_id' = :id",
                    [':c' => Service_Prx400_Specs::CHANNEL, ':id' => (string) $id]);
            }
            foreach ($job_ids as $id) Prx_Db::execute('DELETE FROM prx_120_jobs WHERE id = :id', [':id' => $id]);
            if ($this->user_id !== NULL) Prx_Db::execute('DELETE FROM users WHERE id = :id', [':id' => $this->user_id]);
            putenv($previous_token === FALSE ? 'WORKER_API_TOKEN' : 'WORKER_API_TOKEN='.$previous_token);
        }
    }
}
