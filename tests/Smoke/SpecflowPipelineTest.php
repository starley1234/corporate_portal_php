<?php
use PHPUnit\Framework\TestCase;

/**
 * prx400_specflow на настоящей PostgreSQL и prx212: владельцы и видимость
 * (UI / ключ prx150), lease воркера, приём результата, матчинг, правки
 * оператора, завершение проверки, сверка с очередью и удаление.
 *
 * Без внешней транзакции: сервис сам открывает Prx_Db::transaction (вложенные
 * запрещены), поэтому тест убирает за собой в finally.
 */
class SpecflowPipelineTest extends TestCase
{
    private array $spec_ids = [];
    private array $job_ids = [];
    private array $key_ids = [];
    private array $catalog_ids = [];
    private array $file_ids = [];
    private ?int $user_id = NULL;

    private function api(string $uri, ?string $token): Response
    {
        $request = Request::factory($uri)->method(Request::GET);
        if ($token !== NULL) $request->headers('Authorization', 'Bearer '.$token);
        $response = $request->execute();
        $this->assertSame('no-store', $response->headers('Cache-Control'));

        return $response;
    }

    private function expect_error(callable $fn, string $code, int $status): Prx400_Error
    {
        try {
            $fn();
        } catch (Prx400_Error $e) {
            $this->assertSame($code, $e->getMessage());
            $this->assertSame($status, $e->getCode());
            return $e;
        }
        $this->fail('Ожидалась ошибка '.$code);
    }

    /** Спецификация «как после submit»: артефакт prx212 без пользователя + задача в очереди. */
    private function queued_spec(int $owner, string $pdf): array
    {
        $file_id = Storage::put($pdf, 'spec.pdf', Service_Prx400_Specs::MODULE, NULL, FALSE, 'application/pdf');
        $this->file_ids[] = $file_id;
        $spec = Model_Prx400_Spec::insert([
            'owner_id' => $owner, 'original_name' => 'spec.pdf', 'file_id' => $file_id,
            'file_sha256' => hash('sha256', $pdf), 'parse_mode' => 'ocr',
        ]);
        $spec_id = (int) $spec['id'];
        $this->spec_ids[] = $spec_id;
        $job_id = (int) Service_Prx120_JobQueue::push(Service_Prx400_Specs::CHANNEL, ['spec_id' => $spec_id], ['module' => 'prx400']);
        $this->job_ids[] = $job_id;
        Model_Prx400_Spec::queue($spec_id, $job_id, 'ocr');

        return [$spec_id, $job_id, $file_id];
    }

    /** Задача «у воркера»: processing, попытка 1. */
    private function claim(int $job_id): void
    {
        Prx_Db::execute('UPDATE prx_120_jobs SET status = \'processing\', attempts = 1, claimed_by = \'smoke\', claimed_at = now() WHERE id = :id', [':id' => $job_id]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_owners_worker_lease_operator_review_and_maintenance(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $config = Kohana::$config->load('prx150_api_hub');
        $previous_key = $config->get('ip_hash_key');
        $config->set('ip_hash_key', 'test-only-hmac-key');
        $previous_token = getenv('WORKER_API_TOKEN');
        if (trim((string) $previous_token) === '') putenv('WORKER_API_TOKEN=smoke-token');

        try
        {
            $user = ORM::factory('User');
            $suffix = bin2hex(random_bytes(6));
            $user->username = 'specflow-'.$suffix;
            $user->email = $suffix.'@example.test';
            $user->password = bin2hex(random_bytes(16));
            $user->create();
            $this->user_id = (int) $user->id;
            $admin = new Prx_Actor($this->user_id, ['admin']);
            $plain = new Prx_Actor($this->user_id, ['login']);

            [$key, $token] = Service_Prx150_Keys::create('specflow owner', [Service_Prx400_Specs::SCOPE], 600, NULL, $this->user_id);
            [$other, $other_token] = Service_Prx150_Keys::create('specflow other', [Service_Prx400_Specs::SCOPE], 600, NULL, $this->user_id);
            [$wrong, $wrong_token] = Service_Prx150_Keys::create('specflow wrong', ['prx450.parse'], 600, NULL, $this->user_id);
            $this->key_ids = [$key, $other, $wrong];

            $code = 'SMOKE-'.$suffix;
            $item = Model_Prx400_Catalog::insert(['code' => $code, 'name' => 'Подшипник роликовый конический 7508 smoke'.$suffix,
                'unit' => 'шт.', 'okei_code' => '796', 'keywords' => '']);
            $this->catalog_ids[] = (int) $item['id'];

            $pdf = "%PDF-1.4\n% smoke ".$suffix."\n%%EOF\n";
            [$spec_id, $job_id, $file_id] = $this->queued_spec($key, $pdf);
            $service = new Service_Prx400_Specs;
            $pipeline = new Service_Prx400_Pipeline;

            // ---- Видимость: ключ — только своё; UI с prx400.view — всё; без права — 403
            $uri = 'api/v1/spec-extractions/'.$spec_id;
            $this->assertSame(401, $this->api($uri, NULL)->status());
            $this->assertSame(403, $this->api($uri, $wrong_token)->status());
            $this->assertSame(404, $this->api($uri, $other_token)->status());
            $response = $this->api($uri, $token);
            $this->assertSame(200, $response->status(), $response->body());
            $this->assertSame('queued', json_decode($response->body(), TRUE)['status']);
            $this->assertSame('queued', $service->card($admin, $spec_id)['spec']['status']);
            $this->expect_error(static fn () => $service->card($plain, $spec_id), 'forbidden', 403);
            $listed = array_column($service->listing($admin), 'owner_name', 'id');
            $this->assertSame('API-ключ #'.$key, $listed[$spec_id] ?? NULL);

            // ---- GET не пишет: отменённая задача видна как failed/job_canceled, запись не тронута
            // ---- Lease: чужая попытка и незахваченная задача — 409
            $this->expect_error(static fn () => $pipeline->read_pdf($job_id, 1), 'lease_lost', 409);
            $this->claim($job_id);
            $this->expect_error(static fn () => $pipeline->read_pdf($job_id, 2), 'lease_lost', 409);
            $this->assertSame($pdf, $pipeline->read_pdf($job_id, 1));
            $this->assertSame('running', Model_Prx400_Spec::get($spec_id)['status']);

            // ---- Результат: матчинг, флаги сервера, статус review
            $out = $pipeline->store_result($job_id, 1, [
                'template_id' => 'spec_generic_v1', 'template_title' => 'Спецификация', 'pages' => 1,
                'rows' => [
                    ['pos' => 1, 'raw_name' => 'Подшипник роликовый конический 7508 smoke'.$suffix, 'qty' => 2, 'unit' => 'шт', 'unit_price' => 5, 'total_price' => 10],
                    ['pos' => 2, 'raw_name' => 'Втулка', 'qty' => 2, 'unit' => 'шт', 'unit_price' => 5, 'total_price' => 11],
                    ['pos' => 4, 'raw_name' => 'Совершенно неизвестная деталь', 'qty' => 1, 'unit' => 'шт',
                     'flags' => [['type' => 'gap', 'after_pos' => 2]]],
                ],
                'math' => ['row_errors' => [], 'gaps' => [['after_pos' => 2, 'next_pos' => 4]], 'totals' => [], 'summary' => ''],
                'warnings' => ['empty_page'], 'stats' => ['via' => 'ocr', 'pages_processed' => 1],
            ]);
            $this->assertSame(['status' => 'review', 'rows' => 3, 'issues' => 2], $out);
            $this->expect_error(static fn () => $pipeline->store_result($job_id, 1, ['rows' => [['raw_name' => 'x']]]), 'wrong_state', 409);
            $rows = Model_Prx400_Row::for_spec($spec_id);
            $this->assertSame(['ok', 'error', 'new'], array_column($rows, 'status'));
            $this->assertSame($code, $rows[0]['match']['code']);
            $this->assertSame('math_row', $rows[1]['flags'][0]['type']);
            $this->assertSame(['empty_page'], $service->card($admin, $spec_id)['math']['warnings']);

            // ---- Оператор: ключ API не правит; правка пишет журнал и пересчитывает статус
            $principal = Service_Prx150_Keys::principal(Service_Prx150_Keys::authenticate(
                Request::factory($uri)->headers('Authorization', 'Bearer '.$token)));
            $this->expect_error(static fn () => $service->verify_row($principal, (int) $rows[2]['id']), 'forbidden', 403);
            $error = $this->expect_error(static fn () => $service->finish($admin, $spec_id, FALSE), 'issues_pending', 409);
            $this->assertSame(['issues' => 2], $error->details());

            $this->assertSame($spec_id, $service->row_edit($admin, (int) $rows[1]['id'], ['qty' => '2,2']));
            $edited = Model_Prx400_Row::get((int) $rows[1]['id']);
            $this->assertSame(['unit_fractional'], array_column($edited['flags'], 'type'), 'арифметика сошлась, но 2,2 шт — дробные штуки');
            $this->assertSame($spec_id, $service->row_edit($admin, (int) $rows[1]['id'], ['qty' => '2.2', 'unit' => 'кг']));
            $edited = Model_Prx400_Row::get((int) $rows[1]['id']);
            $this->assertSame([], $edited['flags']);
            $this->assertNotSame('error', $edited['status']);
            $edits = Model_Prx400_Edit::for_spec($spec_id);
            $this->assertCount(2, $edits, 'неизменённое qty не журналируется повторно');
            $fields = array_column($edits, 'field');
            sort($fields);
            $this->assertSame(['qty', 'unit'], $fields);
            $this->expect_error(static fn () => $service->row_edit($admin, (int) $rows[1]['id'], ['qty' => 'abc']), 'qty_invalid', 400);

            $service->verify_row($admin, (int) $rows[2]['id'], $code);
            $manual = Model_Prx400_Row::get((int) $rows[2]['id']);
            $this->assertTrue($manual['verified']);
            $this->assertTrue($manual['match']['manual']);
            $service->verify_row($admin, (int) $rows[1]['id']);
            $this->assertTrue(Model_Prx400_Row::get((int) $rows[1]['id'])['verified']);
            $service->finish($admin, $spec_id, FALSE);

            $done = json_decode($this->api($uri, $token)->body(), TRUE);
            $this->assertSame('done', $done['status']);
            $this->assertFalse($done['awaiting_operator']);
            $this->assertCount(3, $done['result']['positions']);
            $this->assertSame(1, $done['result']['audit']['confirmed_by_operator']);

            // ---- Отменённая задача: GET показывает canceled, пишет только maintain()
            [$spec2, $job2] = $this->queued_spec($key, $pdf);
            Service_Prx120_JobQueue::instance()->cancel($job2, TRUE);
            $status = json_decode($this->api('api/v1/spec-extractions/'.$spec2, $token)->body(), TRUE);
            $this->assertSame('canceled', $status['status']);
            $this->assertSame('queued', Model_Prx400_Spec::get($spec2)['status'], 'GET ничего не пишет');
            $report = $pipeline->maintain();
            $this->assertGreaterThanOrEqual(1, $report['reconciled']);
            $this->assertSame(['failed', 'job_canceled'], [Model_Prx400_Spec::get($spec2)['status'], Model_Prx400_Spec::get($spec2)['error']]);

            // ---- Удаление: запись, строки и артефакт prx212
            $service->delete($admin, $spec_id);
            $this->assertNull(Model_Prx400_Spec::get($spec_id));
            $this->assertSame([], Model_Prx400_Row::for_spec($spec_id));
            $this->assertNull(Storage::metadata($file_id));
        }
        finally
        {
            foreach ($this->spec_ids as $id) Prx_Db::execute('DELETE FROM prx_400_specs WHERE id = :id', [':id' => $id]);
            foreach ($this->file_ids as $id) { try { Storage::delete_owned($id, Service_Prx400_Specs::MODULE); } catch (Throwable $e) {} }
            foreach ($this->catalog_ids as $id) Model_Prx400_Catalog::delete($id);
            foreach ($this->job_ids as $id) Prx_Db::execute('DELETE FROM prx_120_jobs WHERE id = :id', [':id' => $id]);
            foreach ($this->key_ids as $id) {
                Prx_Db::execute('DELETE FROM prx_150_api_usage WHERE client_id = :id', [':id' => $id]);
                Prx_Db::execute('DELETE FROM prx_150_api_clients WHERE id = :id', [':id' => $id]);
            }
            if ($this->user_id !== NULL) Prx_Db::execute('DELETE FROM users WHERE id = :id', [':id' => $this->user_id]);
            $config->set('ip_hash_key', $previous_key);
            putenv($previous_token === FALSE ? 'WORKER_API_TOKEN' : 'WORKER_API_TOKEN='.$previous_token);
        }
    }
}
