<?php
use PHPUnit\Framework\TestCase;

/**
 * prx400_specflow, структура и работа оператора на настоящей PostgreSQL:
 * многофайловая спецификация (файл N для воркера), иерархия BOM (parent_id
 * по номеру «1.2»), правки оператора (вставка за поддеревом, разделение,
 * удаление, массовое подтверждение), закупочная ведомость, повторный разбор
 * с переносом решений и webhook API-ключа (outbox → подписанная доставка).
 */
class SpecflowStructureTest extends TestCase
{
    private array $spec_ids = [];
    private array $job_ids = [];
    private array $key_ids = [];
    private array $catalog_ids = [];
    private array $file_ids = [];
    private ?int $user_id = NULL;

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

    /** Спецификация из нескольких PDF «как после submit». */
    private function queued_spec(int $owner, array $pdfs): array
    {
        $files = [];
        foreach ($pdfs as $i => $pdf)
        {
            $file_id = Storage::put($pdf, 'sheet'.($i + 1).'.pdf', Service_Prx400_Specs::MODULE, NULL, FALSE, 'application/pdf');
            $this->file_ids[] = $file_id;
            $files[] = [$file_id, hash('sha256', $pdf), 'sheet'.($i + 1).'.pdf', strlen($pdf)];
        }
        $spec_id = (int) Model_Prx400_Spec::insert([
            'owner_id' => $owner, 'original_name' => 'BOM (2 файла)', 'file_id' => $files[0][0],
            'file_sha256' => $files[0][1], 'parse_mode' => 'ocr',
        ])['id'];
        $this->spec_ids[] = $spec_id;
        foreach ($files as $i => [$file_id, $sha, $name, $size]) Model_Prx400_File::add($spec_id, $i + 1, $file_id, $sha, $name, $size);
        $job_id = $this->push($spec_id);
        Model_Prx400_Spec::queue($spec_id, $job_id, 'ocr');

        return [$spec_id, $job_id];
    }

    private function push(int $spec_id): int
    {
        $job_id = (int) Service_Prx120_JobQueue::push(Service_Prx400_Specs::CHANNEL, ['spec_id' => $spec_id], ['module' => 'prx400']);
        $this->job_ids[] = $job_id;

        return $job_id;
    }

    private function claim(int $job_id): void
    {
        Prx_Db::execute('UPDATE prx_120_jobs SET status = \'processing\', attempts = 1, claimed_by = \'smoke\', claimed_at = now() WHERE id = :id', [':id' => $job_id]);
    }

    /** Запрос к API под ключом; JSON-тело — для POST/PATCH/PUT. */
    private function call(string $method, string $uri, string $token, ?array $json = NULL): array
    {
        $request = Request::factory($uri)->method($method)->headers('Authorization', 'Bearer '.$token);
        if ($json !== NULL) $request->headers('Content-Type', 'application/json')->body(json_encode($json));
        $response = $request->execute();
        $this->assertSame('no-store', $response->headers('Cache-Control'), $uri);

        return [$response->status(), $response->body(), $response];
    }

    private function parsed(string $code_name): array
    {
        return [
            'template_id' => 'spec_generic_v1', 'template_title' => 'Спецификация', 'pages' => 3,
            'rows' => [
                ['pos_path' => '1', 'raw_name' => 'Узел крепления', 'code_doc' => 'КБ20.01.000', 'qty' => 2, 'unit' => 'шт', 'file_no' => 1, 'page' => 1],
                ['pos_path' => '1.1', 'raw_name' => $code_name, 'qty' => 4, 'unit' => 'шт', 'unit_price' => 10, 'total_price' => 40, 'file_no' => 1, 'page' => 1],
                ['pos_path' => '1.2', 'raw_name' => 'Шайба плоская 16', 'qty' => 8, 'unit' => 'шт', 'file_no' => 1, 'page' => 2],
                ['pos_path' => '2', 'raw_name' => $code_name, 'qty' => 6, 'unit' => 'шт', 'unit_price' => 10, 'total_price' => 60, 'file_no' => 2, 'page' => 1],
            ],
            'math' => ['row_errors' => [], 'gaps' => [], 'totals' => [], 'summary' => ''],
            'warnings' => [], 'stats' => ['via' => 'mixed', 'pages_processed' => 3],
        ];
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_multi_file_bom_operator_tools_relaunch_and_webhook(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $config = Kohana::$config->load('prx150_api_hub');
        $previous_key = $config->get('ip_hash_key');
        $config->set('ip_hash_key', 'test-only-hmac-key');
        $previous_token = getenv('WORKER_API_TOKEN');
        if (trim((string) $previous_token) === '') putenv('WORKER_API_TOKEN=smoke-token');
        $sent = [];
        Service_Prx400_Hooks::use_transport(static function (string $url, string $ip, array $headers, string $body) use (&$sent): int {
            $sent[] = compact('url', 'ip', 'headers', 'body');
            return count($sent) === 1 ? 500 : 204; // первая попытка — сбой клиента, повтор — успех
        });

        try
        {
            $user = ORM::factory('User');
            $suffix = bin2hex(random_bytes(6));
            $user->username = 'specflow-bom-'.$suffix;
            $user->email = $suffix.'@example.test';
            $user->password = bin2hex(random_bytes(16));
            $user->create();
            $this->user_id = (int) $user->id;
            $admin = new Prx_Actor($this->user_id, ['admin']);

            [$key, $token] = Service_Prx150_Keys::create('specflow bom', [Service_Prx400_Specs::SCOPE, 'prx400.review'], 600, NULL, $this->user_id);
            [$ro_key, $ro_token] = Service_Prx150_Keys::create('specflow ro', [Service_Prx400_Specs::SCOPE], 600, NULL, $this->user_id);
            $this->key_ids = [$key, $ro_key];
            $principal = static fn (string $t): Prx_Principal => Service_Prx150_Keys::principal(Service_Prx150_Keys::authenticate(
                Request::factory('api/v1/spec-extractions')->headers('Authorization', 'Bearer '.$t)));
            $api = $principal($token);
            $ro = $principal($ro_token);

            $bolt = 'Болт М16х60 ГОСТ 7798 smoke'.$suffix;
            $item = Model_Prx400_Catalog::insert(['code' => 'BOLT-'.$suffix, 'name' => $bolt, 'unit' => 'шт', 'okei_code' => '796', 'keywords' => '']);
            $this->catalog_ids[] = (int) $item['id'];

            // ---- Webhook: только у API-ключа, адрес — публичный https
            $this->expect_error(static fn () => Service_Prx400_Hooks::subscribe($admin, 'https://93.184.216.34/hook'), 'forbidden', 403);
            $this->expect_error(static fn () => Service_Prx400_Hooks::subscribe($api, 'https://10.0.0.5/hook'), 'webhook_url_invalid', 400);
            $this->expect_error(static fn () => Service_Prx400_Hooks::subscribe($api, 'http://93.184.216.34/hook'), 'webhook_url_invalid', 400);
            $sub = Service_Prx400_Hooks::subscribe($api, 'https://93.184.216.34/hook', 'spec.review,spec.done');
            $this->assertSame(['spec.review', 'spec.done'], $sub['events']);
            $this->assertSame(48, strlen($sub['secret']));

            // ---- Два файла: воркер читает каждый своим номером
            $pdf1 = "%PDF-1.4\n% sheet1 ".$suffix."\n%%EOF\n";
            $pdf2 = "%PDF-1.4\n% sheet2 ".$suffix."\n%%EOF\n";
            [$spec_id, $job_id] = $this->queued_spec($key, [$pdf1, $pdf2]);
            $service = new Service_Prx400_Specs;
            $pipeline = new Service_Prx400_Pipeline;
            $rows_service = new Service_Prx400_Rows;
            $this->claim($job_id);
            $this->assertSame($pdf1, $pipeline->read_pdf($job_id, 1));
            $this->assertSame($pdf2, $pipeline->read_pdf($job_id, 1, 2));
            $this->expect_error(static fn () => $pipeline->read_pdf($job_id, 1, 3), 'file_unavailable', 410);
            $this->assertSame($pdf2, $service->pdf($api, $spec_id, 2));
            $worker = fn (string $path): Response => Request::factory('prx400_specflow/worker/'.$job_id.$path)
                ->headers('X-Worker-Token', (string) getenv('WORKER_API_TOKEN'))->headers('X-PRX-Worker-Attempt', '1')->execute();
            $this->assertSame([200, $pdf2], [$worker('/file/2')->status(), $worker('/file/2')->body()]);
            $this->assertSame($pdf1, $worker('/file')->body(), 'без номера — файл 1 (старые воркеры)');
            $this->assertSame(410, $worker('/file/7')->status());

            // ---- Результат: дерево, файл и страница у строки
            $out = $pipeline->store_result($job_id, 1, $this->parsed($bolt));
            $this->assertSame('review', $out['status']);
            $rows = Model_Prx400_Row::for_spec($spec_id);
            $this->assertSame(['1', '1.1', '1.2', '2'], array_column($rows, 'pos_path'));
            $this->assertSame([0, 1, 1, 0], array_map('intval', array_column($rows, 'level')));
            $this->assertNull($rows[0]['parent_id']);
            $this->assertSame((int) $rows[0]['id'], (int) $rows[1]['parent_id']);
            $this->assertSame((int) $rows[0]['id'], (int) $rows[2]['parent_id']);
            $this->assertSame([1, 1, 1, 2], array_map('intval', array_column($rows, 'file_no')));
            $this->assertSame(2, (int) $rows[2]['page']);
            $this->assertSame('BOLT-'.$suffix, $rows[1]['match']['code']);
            $card = $service->card($admin, $spec_id);
            $this->assertCount(2, $card['files']);
            $this->assertSame('mixed', $card['math']['via']);

            // Outbox: событие review поставлено в той же транзакции
            $report = Service_Prx400_Hooks::deliver_due(10);
            $this->assertSame(['sent' => 0, 'retry' => 1, 'dead' => 0], $report);
            Prx_Db::execute('UPDATE prx_400_deliveries SET next_at = now() WHERE owner_id = :o', [':o' => $key]);
            $this->assertSame(['sent' => 1, 'retry' => 0, 'dead' => 0], Service_Prx400_Hooks::deliver_due(10));
            $this->assertCount(2, $sent);
            $body = json_decode($sent[1]['body'], TRUE);
            $this->assertSame(['spec.review', $spec_id, 'review'], [$body['event'], $body['spec_id'], $body['status']]);
            $this->assertSame('93.184.216.34', $sent[1]['ip']);
            $signature = '';
            foreach ($sent[1]['headers'] as $h) if (str_starts_with($h, 'X-PRX-Signature: ')) $signature = substr($h, 17);
            $this->assertMatchesRegularExpression('/\At=(\d+),v1=[0-9a-f]{64}\z/', $signature);
            preg_match('/\At=(\d+),/', $signature, $m);
            $this->assertSame(Service_Prx400_Hooks::signature($sub['secret'], $sent[1]['body'], (int) $m[1]), $signature);
            $described = Service_Prx400_Hooks::describe($api);
            $this->assertSame('sent', $described['deliveries'][0]['status']);

            // ---- HTTP API: позиции, правка, подтверждение, файл, ведомость, НСИ, подписка
            $base = 'api/v1/spec-extractions/'.$spec_id;
            [$code, $body] = $this->call('GET', $base.'/rows?filter=disputed', $token);
            $this->assertSame(200, $code, $body);
            $listed = json_decode($body, TRUE);
            $this->assertSame('disputed', $listed['filter']);
            $this->assertSame(4, $listed['counts']['all']);
            $this->assertSame([0, 1], array_column($listed['rows'], 'level'), 'спорные: узел и шайба внутри него');
            [$code, $body] = $this->call('GET', $base.'/rows?filter=nope', $token);
            $this->assertSame(400, $code);
            [$code, $body] = $this->call('PATCH', $base.'/rows/'.$rows[2]['id'], $token, ['unit_price' => '1,50', 'total_price' => '12']);
            $this->assertSame(200, $code, $body);
            $this->assertSame(1.5, json_decode($body, TRUE)['unit_price']);
            [$code, $body] = $this->call('PATCH', $base.'/rows/'.$rows[2]['id'], $ro_token, ['qty' => '1']);
            $this->assertSame(403, $code, 'scope prx400.review обязателен для правок');
            [$code, $body] = $this->call('POST', 'api/v1/spec-extractions/'.($spec_id + 100000).'/rows/'.$rows[2]['id'].'/verify', $token, []);
            $this->assertSame(404, $code, 'строка из чужого URL');
            [$code, $body] = $this->call('DELETE', $base.'/rows/'.$rows[0]['id'], $token);
            $this->assertSame(409, $code, $body);
            $this->assertSame('row_has_children', json_decode($body, TRUE)['error']['code']);
            [$code, $body, $response] = $this->call('GET', $base.'/files/2', $token);
            $this->assertSame([200, $pdf2, 'application/pdf'], [$code, $body, $response->headers('Content-Type')]);
            [$code, $body] = $this->call('GET', $base.'/files/3', $token);
            $this->assertSame(404, $code, $body);
            [$code, $body] = $this->call('GET', $base.'/export?format=aggregate&bom=per_parent', $token);
            $this->assertSame(200, $code, $body);
            $this->assertNotSame([], json_decode($body, TRUE)['items'], $body);
            [$code, $body, $response] = $this->call('GET', $base.'/export?format=xlsx', $token);
            $this->assertSame(200, $code);
            $this->assertStringStartsWith('PK', $body);
            [$code, $body] = $this->call('GET', 'api/v1/spec-catalog?q='.rawurlencode('болт м16х60 smoke'.$suffix), $token);
            $this->assertSame(200, $code, $body);
            $this->assertSame('BOLT-'.$suffix, json_decode($body, TRUE)['items'][0]['code'] ?? NULL, $body);
            [$code, $body] = $this->call('GET', 'api/v1/spec-extractions/webhook', $token);
            $this->assertSame(200, $code, $body);
            $this->assertSame('https://93.184.216.34/hook', json_decode($body, TRUE)['url']);
            $this->assertArrayNotHasKey('secret', json_decode($body, TRUE), 'секрет показывается только при создании');
            [$code, $body] = $this->call('GET', 'api/v1/spec-extractions?status=review&per=20', $token);
            $this->assertSame(200, $code, $body);
            $this->assertContains($spec_id, array_column(json_decode($body, TRUE)['items'], 'spec_id'), $body);
            $this->assertSame(2, json_decode($body, TRUE)['items'][0]['files']);

            // ---- Права: ключ без prx400.review видит, но не правит
            $this->expect_error(static fn () => $rows_service->verify($ro, (int) $rows[2]['id']), 'forbidden', 403);
            $this->expect_error(static fn () => $rows_service->delete($api, (int) $rows[0]['id']), 'row_has_children', 409);

            // ---- Вставка «после узла» — за всем его составом, с номером 1.3 внутри узла
            $added = $rows_service->add($api, $spec_id, ['raw_name' => 'Гайка М16', 'qty' => '8', 'unit' => 'шт', 'parent_row_id' => (string) $rows[0]['id'], 'pos_path' => '1.3']);
            $this->assertSame($spec_id, $added['spec_id']);
            $after = $rows_service->add($api, $spec_id, ['raw_name' => 'Рама', 'qty' => '1', 'unit' => 'шт', 'after_row_id' => (string) $rows[0]['id']]);
            $now = Model_Prx400_Row::for_spec($spec_id);
            $this->assertSame(['Узел крепления', $bolt, 'Шайба плоская 16', 'Гайка М16', 'Рама', $bolt], array_column($now, 'raw_name'));
            $this->assertSame((int) $rows[0]['id'], (int) $now[3]['parent_id']);
            $this->assertNull($now[4]['parent_id']);
            $this->assertSame('manual', $now[4]['origin']);
            $this->assertSame($spec_id, $rows_service->delete($api, $after['row_id']));
            $this->expect_error(static fn () => $rows_service->add($api, $spec_id, ['qty' => '1']), 'row_name_required', 400);

            // ---- Разделение: сумма частей = количеству, цена пересчитана
            $this->expect_error(static fn () => $rows_service->split($api, (int) $rows[3]['id'], ['2', '3']), 'split_sum_mismatch', 409);
            $split = $rows_service->split($api, (int) $rows[3]['id'], ['2', '4']);
            $this->assertCount(2, $split['row_ids']);
            $parts = array_values(array_filter(Model_Prx400_Row::for_spec($spec_id), static fn (array $r): bool => in_array((int) $r['id'], $split['row_ids'], TRUE)));
            $this->assertSame([2.0, 4.0], array_map('floatval', array_column($parts, 'qty')));
            $this->assertSame([20.0, 40.0], array_map('floatval', array_column($parts, 'total_price')));
            $this->assertSame('split', $parts[1]['origin']);

            // ---- Массовое подтверждение уверенных совпадений + фильтры
            $bulk = $rows_service->bulk_verify($api, $spec_id);
            $this->assertSame(3, $bulk['verified'], 'болт в узле и обе части второй позиции');
            $listing = $rows_service->listing($api, $spec_id, 'disputed');
            $this->assertSame(['Узел крепления', 'Шайба плоская 16', 'Гайка М16'], array_column($listing['rows'], 'raw_text'));
            $this->assertSame(6, $listing['counts']['all']);
            $this->expect_error(static fn () => $rows_service->bulk_verify($api, $spec_id, '0.1'), 'min_score_invalid', 400);

            // ---- Закупочная ведомость: узел не закупается; per_parent умножает на 2 узла
            [$spec, $export_rows] = $service->export($api, $spec_id);
            $agg = array_column(Service_Prx400_Export::aggregate($export_rows), NULL, 'name');
            $this->assertSame(10.0, $agg[$bolt]['quantity']);
            $this->assertArrayNotHasKey('Узел крепления', $agg);
            $agg = array_column(Service_Prx400_Export::aggregate($export_rows, 'per_parent'), NULL, 'name');
            $this->assertSame(14.0, $agg[$bolt]['quantity']);
            $this->assertSame(16.0, $agg['Шайба плоская 16']['quantity']);
            $this->assertStringStartsWith('PK', Service_Prx400_Export::xlsx($spec, $export_rows));

            // ---- Ручной код НСИ запоминается: следующий разбор подставит его сам (via memory)
            $washer = Model_Prx400_Catalog::insert(['code' => 'BOLT-W-'.$suffix, 'name' => 'Шайба C.16 ГОСТ 11371 smoke'.$suffix, 'unit' => 'шт', 'okei_code' => '796', 'keywords' => '']);
            $this->catalog_ids[] = (int) $washer['id'];
            $this->expect_error(static fn () => $rows_service->assign_code($api, (int) $rows[2]['id'], 'NO-SUCH-'.$suffix), 'catalog_code_not_found', 400);
            $this->assertSame($spec_id, $rows_service->assign_code($api, (int) $rows[2]['id'], 'BOLT-W-'.$suffix));
            $this->assertTrue(Model_Prx400_Row::get((int) $rows[2]['id'])['match']['manual']);

            // ---- Страницы оператора рендерятся на реальных данных (дерево, файлы, журнал, фильтры)
            foreach (Service_Prx400_Rows::FILTERS as $filter)
            {
                $html = View::factory('prx400/spec', $service->card($admin, $spec_id) + [
                    'filter' => $filter, 'filters' => Service_Prx400_Rows::FILTERS, 'csrf' => 'smoke', 'flash' => NULL,
                ])->render();
                $this->assertStringContainsString('Узел крепления', $html, $filter);
            }
            $this->assertStringContainsString('sheet2.pdf', $html);
            $this->assertStringContainsString('/prx400_specflow/file/'.$spec_id.'?n=2#page=1', $html);
            $this->assertStringContainsString('Гайка М16', $html, 'журнал: добавленная строка');
            $html = View::factory('prx400/index', [
                'list' => $service->listing($admin, ['q' => 'BOM', 'status' => 'review', 'per' => '20']),
                'statuses' => Service_Prx400_Specs::STATUSES, 'per_options' => Service_Prx400_Specs::PER_PAGE,
                'max_files' => Service_Prx400_Limits::get('max_files'), 'catalog_provider' => Service_Prx400_Catalog::provider()->name(),
                'templates' => Service_Prx400_Templates::options(), 'catalog_count' => Service_Prx400_Catalog::provider()->count(),
                'max_pdf' => Service_Prx400_Limits::pdf_label(), 'can_edit' => TRUE, 'request_key' => str_repeat('a', 32),
                'flash' => NULL, 'csrf' => 'smoke',
            ])->render();
            $this->assertStringContainsString('/prx400_specflow/spec/'.$spec_id, $html);
            $listed = array_column($service->listing($admin, ['q' => 'BOM', 'per' => 200])['rows'], NULL, 'id');
            $this->assertSame(2, (int) $listed[$spec_id]['files_count']);
            $this->assertArrayNotHasKey($spec_id, array_column($service->listing($admin, ['status' => 'done', 'per' => 200])['rows'], NULL, 'id'));
            $this->assertArrayNotHasKey($spec_id, array_column($service->listing($admin, ['to' => '2000-01-01', 'per' => 200])['rows'], NULL, 'id'));
            $aliases = Model_Prx400_Alias::page('', 50, 0);
            $this->assertNotSame([], $aliases['rows'], 'ручной код запомнен как алиас');
            $html = View::factory('prx400/aliases', ['aliases' => $aliases['rows'], 'total' => $aliases['total'], 'query' => '', 'page_no' => 1,
                'pages' => 1, 'actor_names' => Service_Prx400_Specs::actor_names(array_column($aliases['rows'], 'actor_id')),
                'can_edit' => TRUE, 'csrf' => 'smoke', 'flash' => NULL])->render();
            $this->assertStringContainsString('alias_revoke/', $html);

            // ---- Повторный разбор из review: решения переносятся, ручная строка остаётся
            $service->launch($api, $spec_id, ['parse_mode' => 'ocr']);
            $relaunched = (int) Model_Prx400_Spec::get($spec_id)['job_id'];
            $this->job_ids[] = $relaunched;
            $this->assertNotSame($job_id, $relaunched);
            $this->claim($relaunched);
            $out = $pipeline->store_result($relaunched, 1, $this->parsed($bolt));
            $again = Model_Prx400_Row::for_spec($spec_id);
            $this->assertSame(['1', '1.1', '1.2', '2', '1.3'], array_column($again, 'pos_path'));
            $this->assertTrue($again[1]['verified'], 'болт в узле: то же наименование/кол-во — решение перенесено');
            $this->assertFalse($again[3]['verified'], 'поз. 2 была разделена — 6 шт ≠ 2 + 4');
            $this->assertSame('manual', $again[4]['origin']);
            $this->assertContains('verified_rows_lost', $service->card($admin, $spec_id)['math']['warnings']);
            $this->assertSame('review', $out['status']);
            $this->assertSame('BOLT-W-'.$suffix, $again[2]['match']['code'], 'ручной код перенесён на новый разбор');

            // ---- prx400:eval: качество в бою по строкам и журналу
            $report = Service_Prx400_Quality::report(1);
            $this->assertGreaterThanOrEqual(5, $report['rows']['total']);
            $this->assertGreaterThanOrEqual(1, $report['rows']['via_manual']);
            $this->assertGreaterThan(0, $report['rates']['auto_coded']);
            $this->assertContains('split', array_column($report['edited_fields'], 'field'));
            $this->assertIsArray($report['catalog_gaps']);
            $html = View::factory('prx400/quality', ['days' => 1, 'report' => $report, 'can_edit' => TRUE, 'catalog_writable' => TRUE])->render();
            $this->assertStringContainsString('Кандидаты в НСИ', $html);
            $this->assertStringContainsString('Откуда коды НСИ', $html);
        }
        finally
        {
            Service_Prx400_Hooks::use_transport(NULL);
            foreach ($this->key_ids as $id) Prx_Db::execute('DELETE FROM prx_400_deliveries WHERE owner_id = :id', [':id' => $id]);
            foreach ($this->key_ids as $id) Prx_Db::execute('DELETE FROM prx_400_hooks WHERE owner_id = :id', [':id' => $id]);
            foreach ($this->spec_ids as $id) Prx_Db::execute('DELETE FROM prx_400_specs WHERE id = :id', [':id' => $id]);
            foreach ($this->file_ids as $id) { try { Storage::delete_owned($id, Service_Prx400_Specs::MODULE); } catch (Throwable $e) {} }
            foreach ($this->catalog_ids as $id) Model_Prx400_Catalog::delete($id);
            Prx_Db::execute('DELETE FROM prx_400_aliases WHERE catalog_code LIKE :c', [':c' => 'BOLT-%']);
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

    /**
     * Внешний НСИ (провайдер только для чтения): поиск оператора и страница
     * справочника идут в него, запись из prx400 запрещена, сбой — 503, а не пусто.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_external_catalog_provider_is_read_only_and_searchable(): void
    {
        $calls = [];
        $down = FALSE;
        $provider = new Prx400_Catalog_Http(['url' => 'https://nsi.example/api/', 'token' => 't'], static function (string $url) use (&$calls, &$down): array {
            $calls[] = $url;
            if ($down) return [0, ''];
            if (str_contains($url, '/items/')) return [404, '{}'];
            return [200, json_encode(['items' => [
                ['code' => 'EXT-1', 'name' => 'Болт M16x60 ГОСТ 7798', 'unit' => 'шт', 'okei_code' => '796'],
                ['code' => '', 'name' => 'без кода — отброшен'],
            ]])];
        });
        Service_Prx400_Catalog::use_provider($provider);
        try
        {
            $admin = new Prx_Actor(1, ['admin']);
            $found = Service_Prx400_Catalog::search($admin, 'болт м16', 10);
            $this->assertSame(['EXT-1'], array_column($found['items'] ?? $found, 'code'));
            $this->assertStringStartsWith('https://nsi.example/api/search?q=', $calls[0]);
            $this->assertNull(Service_Prx400_Catalog::by_code('EXT-404'));
            $this->expect_error(static fn () => Service_Prx400_Catalog::save(['code' => 'X', 'name' => 'Y', 'unit' => '', 'okei_code' => '', 'keywords' => '']), 'catalog_readonly', 409);

            $html = View::factory('prx400/catalog', [
                'catalog' => $provider->search('болт', 100), 'total' => 1, 'query' => 'болт', 'page_no' => 1, 'per' => 100,
                'per_options' => [100], 'pages' => 1, 'import_report' => NULL, 'max_import' => '1 МБ', 'max_import_rows' => 10,
                'can_edit' => TRUE, 'flash' => NULL, 'csrf' => 'smoke',
                'catalog_provider' => $provider->name(), 'catalog_writable' => FALSE, 'catalog_count' => NULL,
            ])->render();
            $this->assertStringContainsString('EXT-1', $html);
            $this->assertStringContainsString('Подключён внешний справочник', $html);
            $this->assertStringNotContainsString('catalog_import', $html, 'импорт скрыт');
            $this->assertStringNotContainsString('catalog_delete', $html, 'удаление скрыто');
            $html = View::factory('prx400/api_docs', ['scope' => Service_Prx400_Specs::SCOPE, 'parse_modes' => Service_Prx400_Limits::parse_modes(),
                'templates' => Service_Prx400_Templates::options(), 'max_pdf' => '50 МиБ', 'max_files' => 20, 'max_total' => '200 МиБ',
                'catalog_provider' => $provider->name(), 'can_keys' => FALSE])->render();
            foreach (['files[]', '/rows?filter=', 'rows-batch', 'verify-bulk', 'X-PRX-Signature', 'bom=as_is', 'spec-catalog'] as $needle) $this->assertStringContainsString($needle, $html);

            $down = TRUE;
            $this->expect_error(static fn () => Service_Prx400_Catalog::search($admin, 'гайка', 10), 'catalog_unavailable', 503);
        }
        finally
        {
            Service_Prx400_Catalog::use_provider(NULL);
        }
    }

    /**
     * Кеш внешнего НСИ в БД: повтор не ходит в источник, при сбое выручает
     * недавний ответ (stale-if-error) и это видно счётчику; старше stale_ttl — 503.
     */
    public function test_external_catalog_cache_serves_fresh_and_stale_answers(): void
    {
        $calls = 0;
        $down = FALSE;
        $url = 'https://nsi-cache-'.bin2hex(random_bytes(4)).'.example/api';
        $transport = static function (string $u) use (&$calls, &$down): array {
            $calls++;
            if ($down) return [503, ''];
            if (str_contains($u, '/items/')) return [200, json_encode(['code' => 'EXT-7', 'name' => 'Гайка М16', 'unit' => 'шт'])];
            return [200, json_encode(['items' => [['code' => 'EXT-7', 'name' => 'Гайка М16', 'unit' => 'шт']]])];
        };
        $config = ['url' => $url, 'cache_ttl' => 3600, 'stale_ttl' => 86400];
        try
        {
            $first = new Prx400_Catalog_Http($config, $transport);
            $this->assertSame('EXT-7', $first->search('гайка м16', 5)[0]['code']);
            $this->assertSame('EXT-7', $first->by_code('EXT-7')['code']);
            $this->assertSame(2, $calls);

            // Новый процесс (новый объект) — ответ из БД, без запроса.
            $second = new Prx400_Catalog_Http($config, $transport);
            $this->assertSame('EXT-7', $second->search('гайка м16', 5)[0]['code']);
            $this->assertSame(2, $calls);
            $this->assertSame(0, $second->take_stale());

            // Свежесть вышла, источник лежит — выручает устаревший ответ.
            $down = TRUE;
            $stale = new Prx400_Catalog_Http(['cache_ttl' => 0] + $config, $transport);
            $this->assertSame('EXT-7', $stale->search('гайка м16', 5)[0]['code']);
            $this->assertSame('EXT-7', $stale->by_code('EXT-7')['code']);
            $this->assertSame(2, $stale->take_stale());
            $this->assertSame(0, $stale->take_stale(), 'счётчик обнуляется');

            // Устаревшее не разрешено — честная 503; неизвестный запрос — тоже.
            $strict = new Prx400_Catalog_Http(['cache_ttl' => 0, 'stale_ttl' => 0] + $config, $transport);
            $this->expect_error(static fn () => $strict->search('гайка м16', 5), 'catalog_unavailable', 503);
            $this->expect_error(static fn () => $stale->search('шайба', 5), 'catalog_unavailable', 503);
        }
        finally
        {
            Prx_Db::execute('DELETE FROM prx_400_catalog_cache WHERE payload::text LIKE :p', [':p' => '%EXT-7%']);
        }
    }
}
