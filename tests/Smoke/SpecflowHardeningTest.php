<?php
use PHPUnit\Framework\TestCase;

/**
 * prx400_specflow, устойчивость на настоящей PostgreSQL:
 *  - срок хранения не удаляет спецификацию, которую правят (журнал правок),
 *    и не удаляет ожившую между выборкой и DELETE;
 *  - вход API с массивом вместо строки — 400 invalid_field, а не 500;
 *  - webhook: сбой одной доставки не роняет пачку; служебные сети и IPv4
 *    внутри IPv6 — отказ;
 *  - Matcher: повторы позиций — один запрос к источнику НСИ.
 */
class SpecflowHardeningTest extends TestCase
{
    private array $spec_ids = [];
    private array $file_ids = [];
    private array $owner_ids = [];
    private array $key_ids = [];
    private array $job_ids = [];
    private ?int $user_id = NULL;

    protected function tearDown(): void
    {
        Service_Prx400_Hooks::use_transport(NULL);
        Service_Prx400_Catalog::use_provider(NULL);
        foreach ($this->job_ids as $id) Prx_Db::execute('DELETE FROM prx_120_jobs WHERE id = :id', [':id' => $id]);
        foreach ($this->owner_ids as $id) Prx_Db::execute('DELETE FROM prx_400_deliveries WHERE owner_id = :id', [':id' => $id]);
        foreach ($this->owner_ids as $id) Prx_Db::execute('DELETE FROM prx_400_hooks WHERE owner_id = :id', [':id' => $id]);
        foreach ($this->spec_ids as $id) Prx_Db::execute('DELETE FROM prx_400_specs WHERE id = :id', [':id' => $id]);
        foreach ($this->file_ids as $id) { try { Storage::delete_owned($id, Service_Prx400_Specs::MODULE); } catch (Throwable $e) {} }
        foreach ($this->key_ids as $id) {
            Prx_Db::execute('DELETE FROM prx_150_api_usage WHERE client_id = :id', [':id' => $id]);
            Prx_Db::execute('DELETE FROM prx_150_api_clients WHERE id = :id', [':id' => $id]);
        }
        if ($this->user_id !== NULL) Prx_Db::execute('DELETE FROM users WHERE id = :id', [':id' => $this->user_id]);
    }

    private function expect_error(callable $fn, string $code, int $status): void
    {
        try {
            $fn();
        } catch (Prx400_Error $e) {
            $this->assertSame($code, $e->getMessage());
            $this->assertSame($status, $e->getCode());
            return;
        }
        $this->fail('Ожидалась ошибка '.$code);
    }

    /** Завершённая спецификация, статус которой не менялся $age_days дней. */
    private function finished_spec(string $status, int $age_days, int $owner = -1): int
    {
        $pdf = "%PDF-1.4\n% hardening ".bin2hex(random_bytes(6))."\n";
        $file_id = Storage::put($pdf, 'old.pdf', Service_Prx400_Specs::MODULE, NULL, FALSE, 'application/pdf');
        $this->file_ids[] = $file_id;
        $spec_id = (int) Model_Prx400_Spec::insert([
            'owner_id' => $owner, 'original_name' => 'old.pdf', 'file_id' => $file_id,
            'file_sha256' => hash('sha256', $pdf), 'parse_mode' => 'ocr',
        ])['id'];
        $this->spec_ids[] = $spec_id;
        Model_Prx400_File::add($spec_id, 1, $file_id, hash('sha256', $pdf), 'old.pdf', strlen($pdf));
        Prx_Db::execute('UPDATE prx_400_specs SET status = :st, updated_at = now() - (CAST(:d AS int) * interval \'1 day\') WHERE id = :id',
            [':id' => $spec_id, ':st' => $status, ':d' => $age_days]);

        return $spec_id;
    }

    private function exists(int $spec_id): bool
    {
        return Model_Prx400_Spec::get($spec_id) !== NULL;
    }

    public function test_retention_spares_specs_that_are_edited_or_revived(): void
    {
        $idle = $this->finished_spec('done', 40);
        $failed = $this->finished_spec('failed', 40);
        $edited = $this->finished_spec('done', 40);
        $fresh = $this->finished_spec('done', 5);
        $revived = $this->finished_spec('done', 40);
        $files = [];
        foreach ([$idle, $failed, $edited] as $id) $files[$id] = (int) Model_Prx400_Spec::get($id)['file_id'];
        // Оператор правил строки вчера: статус старый, работа — свежая.
        Model_Prx400_Edit::add(NULL, $edited, 'bulk_verify', NULL, '3 ≥ 0.9', -1, 'строк: 3');
        // Давняя правка срок не продлевает.
        Model_Prx400_Edit::add(NULL, $idle, 'bulk_verify', NULL, '1 ≥ 0.9', -1, 'строк: 1');
        Prx_Db::execute('UPDATE prx_400_edits SET created_at = now() - interval \'45 days\' WHERE spec_id = :id', [':id' => $idle]);

        $expired = array_map('intval', array_column(Model_Prx400_Spec::expired(30, 1000), 'id'));
        $this->assertContains($idle, $expired);
        $this->assertContains($failed, $expired);
        $this->assertContains($revived, $expired);
        $this->assertNotContains($edited, $expired, 'правка строк продлевает срок хранения');
        $this->assertNotContains($fresh, $expired);

        // Между выборкой и удалением запись перезапустили — DELETE её не трогает.
        Prx_Db::execute('UPDATE prx_400_specs SET status = \'queued\', updated_at = now() WHERE id = :id', [':id' => $revived]);
        $this->assertFalse(Model_Prx400_Spec::delete_expired($revived, 30));
        $this->assertFalse(Model_Prx400_Spec::delete_expired($edited, 30));
        $this->assertTrue($this->exists($revived));

        // prx400:purge целиком: срок хранения 30 дней.
        $config = Kohana::$config->load('prx400_specflow');
        $previous = $config->get('retention_days');
        $config->set('retention_days', 30);
        try {
            $report = (new Service_Prx400_Pipeline)->maintain();
        } finally {
            $config->set('retention_days', $previous);
        }
        $this->assertSame(0, $report['errors']);
        $this->assertGreaterThanOrEqual(2, $report['purged']);
        $this->assertFalse($this->exists($idle));
        $this->assertFalse($this->exists($failed));
        $this->assertTrue($this->exists($edited));
        $this->assertTrue($this->exists($fresh));
        $this->assertTrue($this->exists($revived));
        // Файлы удалённых записей убраны, живых — на месте.
        $this->assertNull(Storage::metadata($files[$idle]));
        $this->assertNull(Storage::metadata($files[$failed]));
        $this->assertNotNull(Storage::metadata($files[$edited]));
    }

    public function test_input_shape_is_validated_before_services(): void
    {
        $this->assertSame(['a' => '1', 'quantities' => [1, '2']], Prx400_Input::scalars(['a' => '1', 'quantities' => [1, '2']], ['quantities']));
        $this->expect_error(static fn () => Prx400_Input::scalars(['code' => ['x']]), 'invalid_field', 400);
        $this->expect_error(static fn () => Prx400_Input::scalars(['quantities' => [[1]]], ['quantities']), 'invalid_field', 400);
        $this->assertSame('', Prx400_Input::text(NULL));
        $this->assertSame('12', Prx400_Input::text(12));
        $this->expect_error(static fn () => Prx400_Input::text(['x']), 'invalid_field', 400);

        // Поля строки: массив — 400, логическое значение — не число.
        $this->expect_error(static fn () => Service_Prx400_Rows::fields(['raw_name' => ['x']]), 'invalid_field', 400);
        $this->expect_error(static fn () => Service_Prx400_Rows::fields(['qty' => TRUE]), 'qty_invalid', 400);
        $this->expect_error(static fn () => Service_Prx400_Rows::fields(['unit_price' => ['1']]), 'price_invalid', 400);
        $this->assertSame(['unit' => 'шт', 'qty' => 2.5], Service_Prx400_Rows::fields(['qty' => '2,5', 'unit' => ' шт ']));
        $this->assertSame(['qty' => 3.0], Service_Prx400_Rows::fields(['qty' => 3]));
        $this->assertSame(['qty' => NULL], Service_Prx400_Rows::fields(['qty' => '']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_api_answers_400_for_array_parameters(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $config = Kohana::$config->load('prx150_api_hub');
        $previous_key = $config->get('ip_hash_key');
        $config->set('ip_hash_key', 'test-only-hmac-key');
        try {
            $user = ORM::factory('User');
            $suffix = bin2hex(random_bytes(6));
            $user->username = 'specflow-input-'.$suffix;
            $user->email = $suffix.'@example.test';
            $user->password = bin2hex(random_bytes(16));
            $user->create();
            $this->user_id = (int) $user->id;
            [$key, $token] = Service_Prx150_Keys::create('specflow input', [Service_Prx400_Specs::SCOPE, 'prx400.review'], 600, NULL, $this->user_id);
            $this->key_ids[] = $key;
            $this->owner_ids[] = $key;

            $call = static function (string $method, string $uri, ?string $json = NULL, array $query = []) use ($token): array {
                $request = Request::factory($uri)->method($method)->headers('Authorization', 'Bearer '.$token)->query($query);
                if ($json !== NULL) $request->headers('Content-Type', 'application/json')->body($json);
                $response = $request->execute();
                return [$response->status(), (array) json_decode($response->body(), TRUE)];
            };

            [$status, $body] = $call('GET', 'api/v1/spec-catalog', NULL, ['q' => ['bolt']]);
            $this->assertSame(400, $status);
            $this->assertSame('invalid_field', $body['error']['code'] ?? NULL);

            [$status, $body] = $call('GET', 'api/v1/spec-extractions', NULL, ['status' => ['done']]);
            $this->assertSame(400, $status);
            $this->assertSame('invalid_field', $body['error']['code'] ?? NULL);

            [$status, $body] = $call('PUT', 'api/v1/spec-extractions/webhook', '{"url": {"x": 1}}');
            $this->assertSame(400, $status);
            $this->assertSame('invalid_field', $body['error']['code'] ?? NULL);

            // Список событий — допустимая форма.
            [$status, $body] = $call('PUT', 'api/v1/spec-extractions/webhook', '{"url": "https://93.184.216.34/hook", "events": ["spec.done"]}');
            $this->assertSame(200, $status);
            $this->assertSame(['spec.done'], $body['events']);

            // Обычные запросы не задеты.
            [$status, $body] = $call('GET', 'api/v1/spec-extractions', NULL, ['status' => 'done', 'q' => 'x']);
            $this->assertSame(200, $status);
            $this->assertArrayHasKey('items', $body);
        } finally {
            $config->set('ip_hash_key', $previous_key);
        }
    }

    public function test_webhook_batch_survives_a_failing_delivery_and_rejects_special_networks(): void
    {
        foreach (['100.64.0.1', '0.0.0.0', '127.0.0.1', '169.254.169.254', '10.1.2.3', '192.168.1.1', '198.18.0.1',
                  '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::127.0.0.1', '::ffff:7f00:1'] as $ip)
        {
            $this->assertFalse(Service_Prx400_Hooks::ip_allowed($ip), $ip);
        }
        foreach (['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'] as $ip)
        {
            $this->assertTrue(Service_Prx400_Hooks::ip_allowed($ip), $ip);
        }
        $this->expect_error(static fn () => Service_Prx400_Hooks::check_url('https://[::ffff:127.0.0.1]/hook'), 'webhook_url_invalid', 400);
        $this->expect_error(static fn () => Service_Prx400_Hooks::check_url('https://100.64.0.1/hook'), 'webhook_url_invalid', 400);

        $owner = random_int(900000000, 999999999);
        $this->owner_ids[] = $owner;
        Model_Prx400_Hook::save($owner, 'https://93.184.216.34/hook', 'secret', implode(',', Service_Prx400_Hooks::EVENTS));
        Service_Prx400_Hooks::enqueue($owner, 1, 'review');
        Service_Prx400_Hooks::enqueue($owner, 2, 'done');
        Service_Prx400_Hooks::enqueue($owner, 3, 'failed');

        $delivered = [];
        Service_Prx400_Hooks::use_transport(static function (string $url, string $ip, array $headers, string $body) use (&$delivered): int {
            $data = json_decode($body, TRUE);
            if ($data['spec_id'] === 2) throw new RuntimeException('transport exploded');
            $delivered[] = $data['spec_id'];
            return 204;
        });
        Service_Prx400_Hooks::deliver_due(50);

        $rows = Prx_Db::select('SELECT spec_id, status, attempts, last_error FROM prx_400_deliveries WHERE owner_id = :o ORDER BY spec_id', [':o' => $owner]);
        $this->assertSame([1, 3], array_values(array_intersect([1, 3], $delivered)), 'пачка доставлена мимо сбойной');
        $this->assertSame(['sent', 'pending', 'sent'], array_column($rows, 'status'));
        $this->assertSame('internal', $rows[1]['last_error']);
        $this->assertSame(1, (int) $rows[1]['attempts']);
    }

    public function test_matcher_queries_the_source_once_per_distinct_position(): void
    {
        $provider = new class implements Prx400_Catalog_Provider {
            public int $queries = 0;
            public function name(): string { return 'counting'; }
            public function writable(): bool { return FALSE; }
            public function count(): ?int { return 1; }
            public function batch(): callable
            {
                return function (string $query): array {
                    $this->queries++;
                    return [['id' => 0, 'code' => 'B-16', 'name' => 'Болт М16х60 ГОСТ 7798', 'unit' => 'шт', 'okei_code' => '796', 'keywords' => '']];
                };
            }
            public function search(string $query, int $limit): array { return []; }
            public function by_code(string $code): ?array { return NULL; }
        };
        $matcher = new Service_Prx400_Matcher($provider);
        $rows = [];
        foreach ([1, 2, 3] as $i) $rows[] = ['raw_name' => 'Болт М16х60 ГОСТ 7798', 'code_doc' => '', 'qty' => $i, 'unit' => 'шт', 'flags' => []];
        $rows[] = ['raw_name' => 'Гайка М16 ГОСТ 5915', 'code_doc' => '', 'qty' => 4, 'unit' => 'шт', 'flags' => []];
        $matcher->prime($rows);
        $results = array_map([$matcher, 'assess'], $rows);

        $this->assertSame(2, $provider->queries, 'три одинаковых болта — один запрос');
        $this->assertSame('B-16', $results[0]['match']['code'] ?? NULL);
        $this->assertSame($results[0]['match'], $results[2]['match']);
        $this->assertNotSame($results[0]['status'], '');
    }

    /**
     * НСИ лёг во время приёма результата: строки приняты без сопоставления с
     * предупреждением (а не 503 → повтор всего OCR/vision); «Пересопоставить
     * с НСИ» после возврата источника кодирует нерешённые строки и не трогает
     * решения оператора.
     */
    public function test_catalog_outage_keeps_parse_and_rematch_fills_codes_later(): void
    {
        $provider = $this->flaky_catalog();
        Service_Prx400_Catalog::use_provider($provider);

        $user = ORM::factory('User');
        $suffix = bin2hex(random_bytes(6));
        $user->username = 'specflow-rematch-'.$suffix;
        $user->email = $suffix.'@example.test';
        $user->password = bin2hex(random_bytes(16));
        $user->create();
        $this->user_id = (int) $user->id;
        $admin = new Prx_Actor($this->user_id, ['admin']);

        $spec_id = $this->finished_spec('done', 0, -$this->user_id);
        $job_id = (int) Service_Prx120_JobQueue::push(Service_Prx400_Specs::CHANNEL, ['spec_id' => $spec_id], ['module' => 'prx400']);
        $this->job_ids[] = $job_id;
        Model_Prx400_Spec::queue($spec_id, $job_id, 'ocr');
        Prx_Db::execute('UPDATE prx_120_jobs SET status = \'processing\', attempts = 1, claimed_by = \'smoke\', claimed_at = now() WHERE id = :id', [':id' => $job_id]);

        $rows = (new Service_Prx400_Rows);
        $this->expect_error(static fn () => $rows->rematch($admin, $spec_id), 'wrong_state', 409);

        $out = (new Service_Prx400_Pipeline)->store_result($job_id, 1, [
            'template_id' => 'spec_generic_v1', 'template_title' => 'Спецификация', 'pages' => 1,
            'rows' => [
                ['pos_path' => '1', 'raw_name' => 'Болт М16х60 ГОСТ 7798', 'qty' => 4, 'unit' => 'шт', 'file_no' => 1, 'page' => 1],
                ['pos_path' => '2', 'raw_name' => 'Гайка М16 ГОСТ 5915', 'qty' => 4, 'unit' => 'шт', 'file_no' => 1, 'page' => 1],
                ['pos_path' => '3', 'raw_name' => 'Шайба 16', 'qty' => 8, 'unit' => 'шт', 'file_no' => 1, 'page' => 1],
            ],
            'math' => ['row_errors' => [], 'gaps' => [], 'totals' => [], 'summary' => ''],
            'warnings' => [], 'stats' => ['via' => 'ocr', 'pages_processed' => 1],
        ]);
        $this->assertSame('review', $out['status'], 'разбор не потерян из-за НСИ');
        $spec = Model_Prx400_Spec::get($spec_id);
        $math = is_array($spec['math']) ? $spec['math'] : json_decode((string) $spec['math'], TRUE);
        $this->assertContains('catalog_unavailable', $math['warnings']);
        $before = Model_Prx400_Row::for_spec($spec_id);
        $this->assertSame([NULL, NULL, NULL], array_map(static fn (array $r) => $r['match']['code'] ?? NULL, $before));

        // Оператор уже решил по гайке (подтвердил «как есть») — пересопоставление её не трогает.
        $rows->verify($admin, (int) $before[1]['id'], TRUE);
        // НСИ всё ещё лежит — честная 503, строки не тронуты.
        $this->expect_error(static fn () => $rows->rematch($admin, $spec_id), 'catalog_unavailable', 503);

        $provider->down = FALSE;
        $result = $rows->rematch($admin, $spec_id);
        $this->assertSame(['spec_id' => $spec_id, 'checked' => 2, 'changed' => 2], $result);
        $after = array_column(Model_Prx400_Row::for_spec($spec_id), NULL, 'id');
        $this->assertSame('B-16', $after[(int) $before[0]['id']]['match']['code'] ?? NULL);
        $this->assertSame('ok', $after[(int) $before[0]['id']]['status']);
        $this->assertNull($after[(int) $before[1]['id']]['match']['code'] ?? NULL, 'подтверждённая строка не пересопоставлена');
        $this->assertTrue((bool) $after[(int) $before[1]['id']]['verified']);
        $this->assertNotEmpty($after[(int) $before[2]['id']]['match']['alternatives'], 'шайба получила кандидатов');

        $spec = Model_Prx400_Spec::get($spec_id);
        $math = is_array($spec['math']) ? $spec['math'] : json_decode((string) $spec['math'], TRUE);
        $this->assertNotContains('catalog_unavailable', $math['warnings'], 'предупреждение снято');
        $this->assertSame('review', $spec['status'], 'статус меняет только оператор');
        $this->assertContains('rematch', array_column(Model_Prx400_Edit::for_spec($spec_id), 'field'));

        // Повтор без изменений справочника — ничего не меняет.
        $this->assertSame(0, $rows->rematch($admin, $spec_id)['changed']);
    }

    /** НСИ, который можно «уронить»: $provider->down = TRUE → catalog_unavailable. */
    private function flaky_catalog(): Prx400_Catalog_Provider
    {
        return new class implements Prx400_Catalog_Provider {
            public bool $down = TRUE;
            private array $items = [
                ['id' => 0, 'code' => 'B-16', 'name' => 'Болт М16х60 ГОСТ 7798', 'unit' => 'шт', 'okei_code' => '796', 'keywords' => ''],
                ['id' => 0, 'code' => 'N-16', 'name' => 'Гайка М16 ГОСТ 5915', 'unit' => 'шт', 'okei_code' => '796', 'keywords' => ''],
            ];
            public function name(): string { return 'flaky'; }
            public function writable(): bool { return FALSE; }
            public function count(): ?int { return NULL; }
            public function batch(): callable
            {
                return function (string $query): array {
                    if ($this->down) throw new Prx400_Error('catalog_unavailable', 503);
                    return $this->items;
                };
            }
            public function search(string $query, int $limit): array { return $this->down ? throw new Prx400_Error('catalog_unavailable', 503) : $this->items; }
            public function by_code(string $code): ?array
            {
                if ($this->down) throw new Prx400_Error('catalog_unavailable', 503);
                foreach ($this->items as $item) if ($item['code'] === $code) return $item;
                return NULL;
            }
        };
    }

    public function test_purge_rematches_after_catalog_returns(): void
    {
        $provider = $this->flaky_catalog();
        Service_Prx400_Catalog::use_provider($provider);

        $user = ORM::factory('User');
        $suffix = bin2hex(random_bytes(6));
        $user->username = 'specflow-auto-'.$suffix;
        $user->email = $suffix.'@example.test';
        $user->password = bin2hex(random_bytes(16));
        $user->create();
        $this->user_id = (int) $user->id;

        $spec_id = $this->finished_spec('done', 0, -$this->user_id);
        $job_id = (int) Service_Prx120_JobQueue::push(Service_Prx400_Specs::CHANNEL, ['spec_id' => $spec_id], ['module' => 'prx400']);
        $this->job_ids[] = $job_id;
        Model_Prx400_Spec::queue($spec_id, $job_id, 'ocr');
        Prx_Db::execute('UPDATE prx_120_jobs SET status = \'processing\', attempts = 1, claimed_by = \'smoke\', claimed_at = now() WHERE id = :id', [':id' => $job_id]);
        (new Service_Prx400_Pipeline)->store_result($job_id, 1, [
            'template_id' => 'spec_generic_v1', 'template_title' => 'Спецификация', 'pages' => 1,
            'rows' => [['pos_path' => '1', 'raw_name' => 'Болт М16х60 ГОСТ 7798', 'qty' => 4, 'unit' => 'шт', 'file_no' => 1, 'page' => 1]],
            'math' => ['row_errors' => [], 'gaps' => [], 'totals' => [], 'summary' => ''],
            'warnings' => [], 'stats' => ['via' => 'ocr', 'pages_processed' => 1],
        ]);
        $this->assertContains($spec_id, Model_Prx400_Spec::with_warning('catalog_unavailable', 1000));

        // НСИ ещё лежит: обслуживание не падает и запись не трогает.
        $report = (new Service_Prx400_Pipeline)->maintain();
        $this->assertSame(0, $report['rematched']);
        $this->assertContains($spec_id, Model_Prx400_Spec::with_warning('catalog_unavailable', 1000));

        $provider->down = FALSE;
        $report = (new Service_Prx400_Pipeline)->maintain();
        $this->assertGreaterThanOrEqual(1, $report['rematched']);
        $this->assertNotContains($spec_id, Model_Prx400_Spec::with_warning('catalog_unavailable', 1000), 'предупреждение снято');
        $row = Model_Prx400_Row::for_spec($spec_id)[0];
        $this->assertSame('B-16', $row['match']['code'] ?? NULL, 'код дозаполнен без оператора');
        $this->assertSame('review', Model_Prx400_Spec::get($spec_id)['status'], 'статус меняет только оператор');
        $journal = array_values(array_filter(Model_Prx400_Edit::for_spec($spec_id), static fn (array $e): bool => $e['field'] === 'rematch'));
        $this->assertSame(0, (int) $journal[0]['actor_id'], 'в журнале — «система»');
    }

    /** Пакет решений по строкам: форма проверяется целиком, ошибки — по операции, остальное применяется. */
    public function test_rows_batch_api_applies_each_operation_and_reports_failures(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $config = Kohana::$config->load('prx150_api_hub');
        $previous_key = $config->get('ip_hash_key');
        $config->set('ip_hash_key', 'test-only-hmac-key');
        $provider = $this->flaky_catalog();
        $provider->down = FALSE;
        Service_Prx400_Catalog::use_provider($provider);
        try {
            $user = ORM::factory('User');
            $suffix = bin2hex(random_bytes(6));
            $user->username = 'specflow-batch-'.$suffix;
            $user->email = $suffix.'@example.test';
            $user->password = bin2hex(random_bytes(16));
            $user->create();
            $this->user_id = (int) $user->id;
            [$key, $token] = Service_Prx150_Keys::create('specflow batch', [Service_Prx400_Specs::SCOPE, 'prx400.review'], 600, NULL, $this->user_id);
            $this->key_ids[] = $key;
            $this->owner_ids[] = $key;

            $spec_id = $this->finished_spec('done', 0, $key);
            $job_id = (int) Service_Prx120_JobQueue::push(Service_Prx400_Specs::CHANNEL, ['spec_id' => $spec_id], ['module' => 'prx400']);
            $this->job_ids[] = $job_id;
            Model_Prx400_Spec::queue($spec_id, $job_id, 'ocr');
            Prx_Db::execute('UPDATE prx_120_jobs SET status = \'processing\', attempts = 1, claimed_by = \'smoke\', claimed_at = now() WHERE id = :id', [':id' => $job_id]);
            (new Service_Prx400_Pipeline)->store_result($job_id, 1, [
                'template_id' => 'spec_generic_v1', 'template_title' => 'Спецификация', 'pages' => 1,
                'rows' => [
                    ['pos_path' => '1', 'raw_name' => 'Болт М16х60 ГОСТ 7798', 'qty' => 4, 'unit' => 'шт', 'file_no' => 1, 'page' => 1],
                    ['pos_path' => '2', 'raw_name' => 'Гайка М16', 'qty' => 4, 'unit' => 'шт', 'file_no' => 1, 'page' => 1],
                    ['pos_path' => '3', 'raw_name' => 'Шайба 16', 'qty' => 8, 'unit' => 'шт', 'file_no' => 1, 'page' => 1],
                ],
                'math' => ['row_errors' => [], 'gaps' => [], 'totals' => [], 'summary' => ''],
                'warnings' => [], 'stats' => ['via' => 'ocr', 'pages_processed' => 1],
            ]);
            $ids = array_map('intval', array_column(Model_Prx400_Row::for_spec($spec_id), 'id'));

            $call = static function (?string $json, string $type = 'application/json') use ($token, $spec_id): array {
                $request = Request::factory('api/v1/spec-extractions/'.$spec_id.'/rows-batch')->method('POST')
                    ->headers('Authorization', 'Bearer '.$token)->headers('Content-Type', $type)->body((string) $json);
                $response = $request->execute();
                return [$response->status(), (array) json_decode($response->body(), TRUE)];
            };

            [$status, $body] = $call('operations=1', 'application/x-www-form-urlencoded');
            $this->assertSame([415, 'json_required'], [$status, $body['error']['code'] ?? NULL]);
            [$status, $body] = $call('{"operations": [{"row_id": '.$ids[0].', "op": "explode"}]}');
            $this->assertSame([400, 'batch_invalid'], [$status, $body['error']['code'] ?? NULL]);
            [$status, $body] = $call(json_encode(['operations' => array_fill(0, Service_Prx400_Rows::BATCH_MAX + 1, ['row_id' => $ids[0], 'op' => 'verify'])]));
            $this->assertSame([413, 'batch_too_large'], [$status, $body['error']['code'] ?? NULL]);
            $this->assertSame([], array_filter(array_column(Model_Prx400_Row::for_spec($spec_id), 'verified')), 'неверный пакет ничего не записал');

            [$status, $body] = $call(json_encode(['operations' => [
                ['row_id' => $ids[0], 'op' => 'verify'],
                ['row_id' => $ids[1], 'op' => 'code', 'code' => 'N-16'],
                ['row_id' => $ids[2], 'op' => 'edit', 'fields' => ['qty' => '10']],
                ['row_id' => $ids[2], 'op' => 'code', 'code' => 'NO-SUCH-CODE'],
                ['row_id' => 2147483647, 'op' => 'verify'],
            ]]));
            $this->assertSame(200, $status);
            $this->assertSame([3, 2], [$body['applied'], $body['failed']]);
            $this->assertSame([TRUE, TRUE, TRUE, FALSE, FALSE], array_column($body['results'], 'ok'));
            $this->assertSame('not_found', $body['results'][4]['error']['code']);
            $after = array_column(Model_Prx400_Row::for_spec($spec_id), NULL, 'id');
            $this->assertTrue((bool) $after[$ids[0]]['verified']);
            $this->assertSame('N-16', $after[$ids[1]]['match']['code'] ?? NULL);
            $this->assertEquals(10, $after[$ids[2]]['qty']);
        } finally {
            $config->set('ip_hash_key', $previous_key);
        }
    }
}
