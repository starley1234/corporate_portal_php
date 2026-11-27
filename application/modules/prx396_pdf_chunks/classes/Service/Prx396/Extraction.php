<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Extract-сценарий prx396: разбор большого PDF по частям через очередь prx120
 * (канал pdf.chunks) и Python-воркер.
 *
 * Слои (пилот Д8): здесь — только логика (валидация, статусы, lease, Storage,
 * очередь, идемпотентность, транзакции). Все запросы — в Model_Prx396_Batches
 * и Model_Prx396_Parts: один публичный метод = один запрос, только :param.
 */
class Service_Prx396_Extraction
{
    const CHANNEL = 'pdf.chunks';
    const MODULE = 'prx396';
    /** Scope внешнего ключа prx150, дающий доступ к этому сервису. */
    const SCOPE = 'prx396.extract';
    const FAILED_CODES = [
        'invalid_pdf', 'encrypted_pdf', 'page_limit', 'result_too_large', 'no_text',
        'ocr_unavailable', 'ocr_timeout', 'ocr_auth', 'ocr_config', 'ocr_rejected',
        'ocr_invalid_response', 'ocr_incomplete', 'ocr_page_failed', 'file_unavailable',
        'worker_auth', 'worker_endpoint_unavailable', 'worker_config', 'lease_lost',
        'invalid_part', 'invalid_plan', 'invalid_result', 'invalid_attempt', 'method_not_allowed',
    ];
    /**
     * Коды, при которых задача воркера через plan/complete валит batch: повтор
     * той же работы не поможет (битый PDF, нет текста/OCR, контрактная ошибка
     * части). Остальные коды воркера получают 503 и ретраятся; сетевые сбои
     * сюда не попадают вовсе — их воркер не отправляет как error_code.
     */
    const PERMANENT_CODES = [
        'invalid_pdf', 'encrypted_pdf', 'page_limit', 'result_too_large', 'no_text',
        'ocr_config', 'ocr_unavailable', 'ocr_auth', 'ocr_rejected', 'ocr_invalid_response',
        'ocr_incomplete', 'ocr_page_failed', 'file_unavailable', 'lease_lost',
        'invalid_part', 'invalid_plan', 'invalid_result', 'invalid_attempt', 'method_not_allowed',
    ];

    private Service_Prx120_JobQueue $queue;

    public function __construct()
    {
        // JobQueue::push, INSERT регистрации и ключ идемпотентности идут через
        // одно соединение: все берут default-группу (db_group конфига = NULL).
        $this->queue = Service_Prx120_JobQueue::instance();
    }

    public function submit(Prx_Principal $principal, string $key, $upload): int
    {
        $owner = Policy_Prx396_Extraction::ownerId($principal);
        return $this->register($owner, $key, self::validated_upload($upload));
    }

    private function register(int $owner, string $key, array $upload): int
    {
        if ( ! preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', $key))
            throw new Prx396_Error('invalid_idempotency_key', 400);
        if (trim((string) getenv('WORKER_API_TOKEN')) === '') throw new Prx396_Error('worker_not_configured', 503);
        $key_hash = hash('sha256', $key);
        $sha = hash_file('sha256', $upload['tmp_name']);
        if ($sha === FALSE) throw new Prx396_Error('upload_unavailable', 503);

        // ФС не откатывается вместе с PostgreSQL. Сохраняем через prx212 вне
        // транзакции; если ключ занят или транзакция не подтверждена — удаляем.
        $file_id = Storage::save($upload, self::MODULE, max_bytes: Service_Prx396_Limits::get('max_pdf_bytes'));

        try
        {
            $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key, $sha);
        }
        catch (Throwable $e)
        {
            $this->discard($file_id);
            throw $e;
        }

        if ($idem['state'] !== Prx_Idempotency::STATE_NEW)
        {
            $this->discard($file_id);

            if ($idem['state'] === Prx_Idempotency::STATE_REPLAY)
            {
                $batch_id = (int) ($idem['result']['batch_id'] ?? 0);
                if ($batch_id < 1) throw new Prx396_Error('idempotency_conflict', 409);
                Log_Plain::event(Log::INFO, 'prx396 batch_id=:id stage=replayed', [':id' => $batch_id]);
                return $batch_id;
            }

            throw new Prx396_Error(
                $idem['state'] === Prx_Idempotency::STATE_CONFLICT ? 'idempotency_conflict' : 'idempotency_key_expired',
                409
            );
        }

        try
        {
            $ttl = Service_Prx396_Limits::get('result_ttl_hours');
            $snapshot = self::config_snapshot();
            $batch_id = $this->insert_batch($owner, $key_hash, $sha, $file_id, $ttl, $snapshot);
            // Снимок лимитов уходит в задачу: воркер обрабатывает по значениям
            // на момент постановки, а не по тому, что сейчас в его .env.
            // visitor_id — правило prx120: «Мои задачи» и бюджет владельца.
            $job_id = Service_Prx120_JobQueue::push(self::CHANNEL,
                ['batch_id' => $batch_id, 'limits' => $snapshot], [
                'module' => self::MODULE,
                'max_attempts' => Service_Prx396_Limits::get('max_attempts'),
                'timeout_sec' => Service_Prx396_Limits::get('task_timeout_sec'),
                'visitor_id' => Visitor::uid(),
            ]);
            if ($job_id < 1) throw new RuntimeException('Отсутствует ID задачи');
            Model_Prx396_Batches::set_job($batch_id, $job_id);
            Prx_Idempotency::finish((int) $idem['id'], ['batch_id' => $batch_id]);
        }
        catch (Prx120_Budget_Exception $e)
        {
            // Владелец исчерпал бюджет токенов: снимаем файл и ключ, чтобы
            // повтор после пополнения прошёл с тем же Idempotency-Key.
            Prx_Idempotency::abort();
            $this->discard($file_id);
            throw new Prx396_Error('budget_exceeded', 429);
        }
        catch (Throwable $e)
        {
            Prx_Idempotency::abort();
            $this->discard($file_id);
            throw $e;
        }

        Log_Plain::event(Log::INFO, 'prx396 batch_id=:id stage=queued', [':id' => $batch_id]);
        return $batch_id;
    }

    private function insert_batch(int $owner, string $key_hash, string $sha, int $file_id, int $ttl, array $snapshot): int
    {
        $batch_id = Model_Prx396_Batches::insert_queued(
            $owner, $key_hash, $sha, $file_id,
            (int) Service_Prx396_Limits::get('chunk_pages'), $ttl,
            json_encode($snapshot, JSON_THROW_ON_ERROR));
        if ($batch_id < 1) throw new RuntimeException('Не создана партия');
        return $batch_id;
    }

    private static function config_snapshot(): array
    {
        $l = Service_Prx396_Limits::all();
        return [
            'max_pdf_bytes' => (int) $l['max_pdf_bytes'],
            'max_pages' => (int) $l['max_pages'],
            'chunk_pages' => (int) $l['chunk_pages'],
            'max_text_bytes' => (int) $l['max_text_bytes'],
            'result_ttl_hours' => (int) $l['result_ttl_hours'],
            'pdf_memory_mb' => (int) $l['pdf_memory_mb'],
            'probe_timeout_sec' => (int) $l['probe_timeout_sec'],
        ];
    }

    private static function validated_upload($file): array
    {
        $error = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)) throw new Prx396_Error('file_too_large', 413);
        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], TRUE))
            throw new Prx396_Error('upload_unavailable', 503);
        if ($error !== UPLOAD_ERR_OK || ! is_string($file['tmp_name'] ?? NULL) || ! is_uploaded_file($file['tmp_name']))
            throw new Prx396_Error('file_required', 400);
        $path = $file['tmp_name'];
        $size = filesize($path);
        if ($size === FALSE) throw new Prx396_Error('upload_unavailable', 503);
        if ($size > Service_Prx396_Limits::get('max_pdf_bytes')) throw new Prx396_Error('file_too_large', 413);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf'
            || file_get_contents($path, FALSE, NULL, 0, 5) !== '%PDF-')
            throw new Prx396_Error('pdf_required', 415);
        // Исходное имя не нужно для распознавания и не попадает в БД/логи.
        return ['tmp_name' => $path, 'name' => 'document.pdf', 'size' => $size, 'type' => 'application/pdf'];
    }

    public function status(Prx_Principal $principal, int $id): array
    {
        $owner = Policy_Prx396_Extraction::ownerId($principal);
        $batch = Model_Prx396_Batches::find_for_owner($id, $owner);
        if ( ! $batch) throw new Prx396_Error('not_found', 404);

        $counts = Model_Prx396_Parts::counts_by_batch($id);
        $job = $this->queue->get((int) $batch['job_id']);
        $state = $this->public_status((string) $batch['status'], $job);

        $response = [
            'batch_id' => $id,
            'job_id' => (int) $batch['job_id'],
            'status' => $state,
            'attempt' => (int) ($job['attempts'] ?? 0),
            'parts_done' => (int) ($counts['done'] ?? 0),
            'parts_total' => (int) ($counts['total'] ?? 0),
            'next_part' => isset($counts['next_part']) ? (int) $counts['next_part'] : NULL,
            'pages_total' => $batch['pages_total'] !== NULL ? (int) $batch['pages_total'] : NULL,
            'expires_at' => gmdate('c', strtotime($batch['expires_at'])),
        ];
        if ($state === 'failed')
        {
            $code = $batch['error_code'] ?: self::error_code($job);
            $response['error'] = ['code' => $code];
        }
        return $response;
    }

    private function public_status(string $batch_status, ?array $job): string
    {
        if ($batch_status === 'done') return 'succeeded';
        if ($batch_status === 'failed') return 'failed';
        if ( ! $job) return 'queued';
        // failed в очереди — финал (попытки исчерпаны или PermanentJobError):
        // показывать «running» значит врать владельцу, что будет повтор.
        // done в очереди, а пачка не собрана — ещё «running».
        return Prx120_JobOutcome::stopped_as($job)
            ?? ['pending' => 'queued', 'processing' => 'running', 'done' => 'running'][$job['status'] ?? ''] ?? 'queued';
    }

    /** Код отказа задачи: разбор текстов очереди — у prx120 (failure_code). */
    public static function error_code(?array $job): string
    {
        return Prx120_JobOutcome::failure_code($job, self::FAILED_CODES);
    }

    public function plan(int $batch_id, int $attempt, array $body): array
    {
        $batch = $this->check_lease($batch_id, $attempt);

        if (isset($body['error_code']))
        {
            $code = self::sanitize_code($body['error_code']);
            if (in_array($code, self::PERMANENT_CODES, TRUE))
            {
                $this->fail_batch($batch_id, $code);
                throw new Prx396_Error($code, 422);
            }
            throw new Prx396_Error($code ?: 'job_failed', 503);
        }

        if ($batch['status'] === 'done') return $this->plan_payload($batch_id, 'done');
        if ($batch['status'] === 'failed')
        {
            throw new Prx396_Error($batch['error_code'] ?: 'job_failed', 422);
        }

        if ($batch['pages_total'] === NULL)
        {
            $pages_total = $body['pages_total'] ?? NULL;
            if ( ! is_int($pages_total) || $pages_total < 1)
                throw new Prx396_Error('invalid_plan', 400);
            $max_pages = (int) ($batch['snapshot']['max_pages'] ?? Service_Prx396_Limits::get('max_pages'));
            if ($pages_total > $max_pages)
            {
                $this->fail_batch($batch_id, 'page_limit');
                throw new Prx396_Error('page_limit', 422);
            }
            $this->create_parts($batch_id, $pages_total, (int) ($batch['snapshot']['chunk_pages'] ?? Service_Prx396_Limits::get('chunk_pages')));
        }

        Model_Prx396_Batches::mark_running($batch_id);
        return $this->plan_payload($batch_id, 'running');
    }

    private function create_parts(int $batch_id, int $pages_total, int $chunk_pages): void
    {
        $chunk_pages = max(1, $chunk_pages);
        $ranges = [];
        $part_no = 1;
        for ($from = 1; $from <= $pages_total; $from += $chunk_pages)
        {
            $to = min($pages_total, $from + $chunk_pages - 1);
            $ranges[] = [$part_no, $from, $to];
            $part_no++;
        }
        if ( ! $ranges) throw new Prx396_Error('invalid_plan', 400);
        Model_Prx396_Parts::insert_ranges($batch_id, $ranges);
        Model_Prx396_Batches::set_pages_total($batch_id, $pages_total);
    }

    private function plan_payload(int $batch_id, string $status): array
    {
        $parts = Model_Prx396_Parts::find_by_batch($batch_id);
        $out = [];
        foreach ($parts as $p)
        {
            $out[] = [
                'part_no' => (int) $p['part_no'],
                'page_from' => (int) $p['page_from'],
                'page_to' => (int) $p['page_to'],
                'status' => (string) $p['status'],
            ];
        }
        $batch = Model_Prx396_Batches::find_summary($batch_id);
        return [
            'batch_id' => $batch_id,
            'status' => $status,
            'pages_total' => $batch && $batch['pages_total'] !== NULL ? (int) $batch['pages_total'] : NULL,
            'parts' => $out,
        ];
    }

    public function read_pdf(int $batch_id, int $attempt): string
    {
        $batch = $this->check_lease($batch_id, $attempt);
        $max_pdf = (int) ($batch['snapshot']['max_pdf_bytes'] ?? Service_Prx396_Limits::get('max_pdf_bytes'));
        $bytes = Storage::read_owned((int) $batch['file_id'], self::MODULE, $max_pdf);
        if ($bytes === NULL || ! hash_equals($batch['file_sha256'], hash('sha256', $bytes)))
            throw new Prx396_Error('file_unavailable', 410);
        return $bytes;
    }

    public function store_part(int $batch_id, int $attempt, array $body): array
    {
        $batch = $this->check_lease($batch_id, $attempt);
        if ($batch['status'] === 'failed')
            throw new Prx396_Error($batch['error_code'] ?: 'job_failed', 422);
        if ($batch['status'] === 'done')
            return $this->part_ack($batch_id, (int) ($body['part_no'] ?? 0));

        $part_no = $body['part_no'] ?? NULL;
        $text = $body['text'] ?? NULL;
        $pages_processed = $body['pages_processed'] ?? NULL;
        $warnings = $body['warnings'] ?? [];
        $provider = $body['provider'] ?? NULL;
        $format = $body['format'] ?? NULL;

        if ( ! is_int($part_no) || $part_no < 1)
            throw new Prx396_Error('invalid_part', 400);
        $part = Model_Prx396_Parts::find_part($batch_id, $part_no);
        if ( ! $part) throw new Prx396_Error('invalid_part', 400);

        if ($part['status'] === 'done')
            return $this->part_ack($batch_id, $part_no); // idempotent replay

        $max_text = (int) ($batch['snapshot']['max_text_bytes'] ?? Service_Prx396_Limits::get('max_text_bytes'));
        if ( ! is_string($text) || ! mb_check_encoding($text, 'UTF-8')
            || ! in_array($provider, ['remote', 'tesseract'], TRUE)
            || $format !== ($provider === 'remote' ? 'markdown' : 'plain')
            || ! in_array($warnings, [[], ['empty_page']], TRUE))
            throw new Prx396_Error('invalid_part', 400);

        $text = str_replace("\x00", '', $text);
        $text_bytes = strlen($text);
        $part_pages = (int) $part['page_to'] - (int) $part['page_from'] + 1;
        if ($pages_processed !== NULL && (! is_int($pages_processed) || $pages_processed !== $part_pages))
            throw new Prx396_Error('invalid_part', 400);
        if ($provider === 'tesseract' && $pages_processed === NULL)
            throw new Prx396_Error('invalid_part', 400);
        if ($text_bytes > $max_text)
            throw new Prx396_Error('result_too_large', 413);

        $current_total = Model_Prx396_Parts::done_bytes($batch_id);
        if ($current_total + $text_bytes > $max_text)
            throw new Prx396_Error('result_too_large', 413);

        $file_id = Storage::put($text, 'part-'.$part_no.'.txt', self::MODULE, NULL, FALSE, 'text/plain');
        try
        {
            $this->check_lease($batch_id, $attempt);
            $updated = Model_Prx396_Parts::mark_done($batch_id, $part_no, $file_id, $text_bytes);
            if ( ! is_numeric($updated) || (int) $updated < 1)
            {
                // Race or already done - check
                $fresh = Model_Prx396_Parts::find_part($batch_id, $part_no);
                if ( ! $fresh || $fresh['status'] !== 'done')
                {
                    $this->discard($file_id);
                    throw new Prx396_Error('lease_lost', 409);
                }
                $this->discard($file_id);
                return $this->part_ack($batch_id, $part_no);
            }
        }
        catch (Throwable $e)
        {
            $this->discard($file_id);
            throw $e;
        }

        $this->refresh_batch_progress($batch_id);
        Log_Plain::event(Log::INFO, 'prx396 batch_id=:id stage=part_done part=:p', [':id' => $batch_id, ':p' => $part_no]);
        return $this->part_ack($batch_id, $part_no);
    }

    private function part_ack(int $batch_id, int $part_no): array
    {
        $counts = Model_Prx396_Parts::counts_by_batch($batch_id);
        $batch = Model_Prx396_Batches::find_summary($batch_id);
        return [
            'batch_id' => $batch_id,
            'part_no' => $part_no,
            'parts_done' => (int) ($counts['done'] ?? 0),
            'parts_total' => (int) ($counts['total'] ?? 0),
            'batch_status' => (string) ($batch['status'] ?? 'running'),
        ];
    }

    private function refresh_batch_progress(int $batch_id): void
    {
        $counts = Model_Prx396_Parts::counts_by_batch($batch_id);
        if ( ! $counts) return;
        $all_done = (int) $counts['total'] > 0 && (int) $counts['done'] === (int) $counts['total'];
        Model_Prx396_Batches::update_progress($batch_id, (int) $counts['bytes'], $all_done ? 'done' : 'running');
    }

    public function complete(int $batch_id, int $attempt, array $body = []): array
    {
        $batch = $this->check_lease($batch_id, $attempt);
        $error = isset($body['error_code']) ? self::sanitize_code($body['error_code']) : '';
        if ($error !== '')
        {
            if (in_array($error, self::PERMANENT_CODES, TRUE))
            {
                $this->fail_batch($batch_id, $error);
                return ['batch_id' => $batch_id, 'status' => 'failed', 'error' => ['code' => $error]];
            }
            throw new Prx396_Error($error, 503);
        }

        $counts = Model_Prx396_Parts::counts_by_batch($batch_id);
        if ( ! $counts || (int) $counts['total'] === 0 || (int) $counts['done'] !== (int) $counts['total'])
            throw new Prx396_Error('batch_incomplete', 409);

        $this->refresh_batch_progress($batch_id);
        Model_Prx396_Batches::mark_done($batch_id);
        return ['batch_id' => $batch_id, 'status' => 'done'];
    }

    private function fail_batch(int $batch_id, string $code): void
    {
        Model_Prx396_Batches::fail($batch_id, $code);
    }

    private function check_lease(int $batch_id, int $attempt): array
    {
        $row = Model_Prx396_Batches::find_lease($batch_id);
        if ( ! $row) throw new Prx396_Error('not_found', 404);
        $job = $this->queue->get((int) $row['job_id']);
        if ( ! $job || $job['channel'] !== self::CHANNEL || $job['status'] !== 'processing' || (int) $job['attempts'] !== $attempt)
            throw new Prx396_Error('lease_lost', 409);
        $snapshot = json_decode((string) $row['config_snapshot'], TRUE);
        $row['snapshot'] = is_array($snapshot) ? $snapshot : [];
        return $row;
    }

    private static function sanitize_code($code): string
    {
        return is_string($code) && preg_match('/\A[a-z_]{1,64}\z/', $code) ? $code : '';
    }

    /** @return array{batch_id:int, parts:array<int,array{file_id:int,text_bytes:int}>} */
    public function result_parts(Prx_Principal $principal, int $id): array
    {
        $owner = Policy_Prx396_Extraction::ownerId($principal);
        $batch = Model_Prx396_Batches::find_for_owner($id, $owner);
        if ( ! $batch) throw new Prx396_Error('not_found', 404);
        if ($batch['status'] !== 'done') throw new Prx396_Error('result_not_ready', 409);

        $rows = Model_Prx396_Parts::find_result_rows($id);
        $parts = [];
        foreach ($rows as $r)
        {
            if ($r['status'] !== 'done' || $r['text_file_id'] === NULL)
                throw new Prx396_Error('result_unavailable', 503);
            $parts[] = ['file_id' => (int) $r['text_file_id'], 'text_bytes' => (int) $r['text_bytes']];
        }
        if ( ! $parts) throw new Prx396_Error('result_unavailable', 503);
        return ['batch_id' => $id, 'parts' => $parts];
    }

    public function purge(): array
    {
        $expired = Model_Prx396_Batches::find_expired(100);
        $report = ['purged' => 0, 'orphans' => 0, 'errors' => 0];
        foreach ($expired as $candidate)
        {
            $id = (int) $candidate['id'];
            try {
                if ($this->purge_one($id)) $report['purged']++;
            } catch (Throwable $e) {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx396 batch_id=:id stage=purge error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            }
        }
        $sweep = Storage::sweep_orphans(self::MODULE, [Model_Prx396_Batches::class, 'referenced_files']);
        $report['orphans'] += $sweep['orphans'];
        $report['errors'] += $sweep['errors'];
        return $report;
    }

    private function purge_one(int $id): bool
    {
        return Prx_Db::transaction(function () use ($id) {
            $row = Model_Prx396_Batches::lock_expired($id);
            // Гонка TTL и очереди (как в prx397): партия, чья задача ещё
            // ждёт/выполняется, файлом жива — доберём после завершения.
            if ($row)
            {
                $job = $this->queue->get((int) $row['job_id']);
                if ($job !== NULL && in_array((string) $job['status'], ['pending', 'processing'], TRUE))
                {
                    return FALSE;
                }
            }
            if ($row)
            {
                $file_ids = [(int) $row['file_id']];
                foreach (Model_Prx396_Parts::find_text_files($id) as $p)
                {
                    $file_ids[] = (int) $p['text_file_id'];
                }
                foreach ($file_ids as $file_id) {
                    if ($file_id > 0 && ! Storage::delete_owned($file_id, self::MODULE))
                        throw new RuntimeException('Артефакт не удалён');
                }
                Model_Prx396_Batches::delete($id);
            }
            return (bool) $row;
        });
    }

    private function discard(int $file_id): void
    {
        try {
            if (Storage::delete_owned($file_id, self::MODULE)) return;
        } catch (Throwable $e) {
            // Не заменяем исходную ошибку; ID нужен оператору для уборки.
        }
        Log_Plain::event(Log::ERROR, 'prx396 stage=discard file_id=:id', [':id' => $file_id]);
    }

}
