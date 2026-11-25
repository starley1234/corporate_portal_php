<?php defined('SYSPATH') or die('No direct script access.');

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

    private Database $db;
    private Service_Prx120_JobQueue $queue;

    public function __construct()
    {
        // JobQueue::push и INSERT регистрации должны использовать одно соединение.
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
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
            $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key, $sha, $this->db);
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
            $this->db->query(Database::UPDATE,
                'UPDATE prx_396_batches SET job_id = '.$job_id.' WHERE id = '.$batch_id, FALSE);
            Prx_Idempotency::finish((int) $idem['id'], ['batch_id' => $batch_id], $this->db);
        }
        catch (Prx120_Budget_Exception $e)
        {
            // Владелец исчерпал бюджет токенов: снимаем файл и ключ, чтобы
            // повтор после пополнения прошёл с тем же Idempotency-Key.
            Prx_Idempotency::abort($this->db);
            $this->discard($file_id);
            throw new Prx396_Error('budget_exceeded', 429);
        }
        catch (Throwable $e)
        {
            Prx_Idempotency::abort($this->db);
            $this->discard($file_id);
            throw $e;
        }

        Log_Plain::event(Log::INFO, 'prx396 batch_id=:id stage=queued', [':id' => $batch_id]);
        return $batch_id;
    }

    private function insert_batch(int $owner, string $key_hash, string $sha, int $file_id, int $ttl, array $snapshot): int
    {
        $row = $this->one('INSERT INTO prx_396_batches '
            .'(job_id, owner_id, request_key, file_sha256, file_id, chunk_pages, status, config_snapshot, expires_at) VALUES ('
            .'0,'.(int) $owner.','.$this->db->quote($key_hash).','.$this->db->quote($sha).','.$file_id.','
            .Service_Prx396_Limits::get('chunk_pages').",'queued',"
            .$this->db->quote(json_encode($snapshot, JSON_THROW_ON_ERROR)).'::jsonb,'
            ."now() + ({$ttl} * interval '1 hour')) RETURNING id");
        if ( ! $row) throw new RuntimeException('Не создана партия');
        return (int) $row['id'];
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
        $batch = $this->one('SELECT id, job_id, status, pages_total, error_code, text_bytes, expires_at '
            .'FROM prx_396_batches WHERE id = '.$id.' AND owner_id = '.(int) $owner.' AND expires_at > now()');
        if ( ! $batch) throw new Prx396_Error('not_found', 404);

        $counts = $this->one('SELECT '
            ."COUNT(*) FILTER (WHERE status = 'done') AS done, "
            ."COUNT(*) AS total, "
            ."MIN(part_no) FILTER (WHERE status IN ('pending','failed')) AS next_part "
            .'FROM prx_396_parts WHERE batch_id = '.$id);
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
            $code = $batch['error_code'] ?: self::error_code($job['error'] ?? '', $job !== NULL);
            $response['error'] = ['code' => $code];
        }
        return $response;
    }

    private function public_status(string $batch_status, ?array $job): string
    {
        if ($batch_status === 'done') return 'succeeded';
        if ($batch_status === 'failed') return 'failed';
        if ( ! $job) return 'queued';
        $map = [
            'pending' => 'queued', 'processing' => 'running', 'done' => 'succeeded',
            'failed' => 'failed', 'cancelled' => 'canceled',
        ];
        $js = $map[$job['status'] ?? ''] ?? 'queued';
        // failed в очереди — финал (попытки исчерпаны или PermanentJobError):
        // показывать «running» значит врать владельцу, что будет повтор.
        return $js === 'succeeded' ? 'running' : $js;
    }

    public static function error_code(string $error, bool $found = TRUE): string
    {
        if ( ! $found) return 'job_unavailable';
        if (preg_match('/\A(?:DocumentJobError|PermanentJobError): ([a-z_]+)\z/', $error, $m)
            && in_array($m[1], self::FAILED_CODES, TRUE)) return $m[1];
        if (str_starts_with($error, 'Превышен таймаут задачи')) return 'job_timeout';
        return 'job_failed';
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

        $this->db->query(Database::UPDATE,
            "UPDATE prx_396_batches SET status = 'running', error_code = NULL WHERE id = ".$batch_id
            ." AND status IN ('queued','running')", FALSE);
        return $this->plan_payload($batch_id, 'running');
    }

    private function create_parts(int $batch_id, int $pages_total, int $chunk_pages): void
    {
        $chunk_pages = max(1, $chunk_pages);
        $values = [];
        $part_no = 1;
        for ($from = 1; $from <= $pages_total; $from += $chunk_pages)
        {
            $to = min($pages_total, $from + $chunk_pages - 1);
            $values[] = '('.$batch_id.','.$part_no.','.$from.','.$to.",'pending',now())";
            $part_no++;
        }
        if ( ! $values) throw new Prx396_Error('invalid_plan', 400);
        $this->db->query(Database::INSERT,
            'INSERT INTO prx_396_parts (batch_id, part_no, page_from, page_to, status, updated_at) VALUES '
            .implode(',', $values)
            .' ON CONFLICT (batch_id, part_no) DO NOTHING', FALSE);
        $this->db->query(Database::UPDATE,
            'UPDATE prx_396_batches SET pages_total = '.$pages_total.', status = \'running\' WHERE id = '.$batch_id
            .' AND pages_total IS NULL', FALSE);
    }

    private function plan_payload(int $batch_id, string $status): array
    {
        $parts = $this->db->query(Database::SELECT,
            'SELECT part_no, page_from, page_to, status, text_bytes, error_code FROM prx_396_parts '
            .'WHERE batch_id = '.$batch_id.' ORDER BY part_no', FALSE)->as_array();
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
        $batch = $this->one('SELECT pages_total, text_bytes, error_code, status FROM prx_396_batches WHERE id = '.$batch_id);
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
        $part = $this->one('SELECT part_no, page_from, page_to, status, text_file_id, text_bytes FROM prx_396_parts '
            .'WHERE batch_id = '.$batch_id.' AND part_no = '.$part_no);
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

        $current_total = (int) $this->one('SELECT COALESCE(SUM(text_bytes),0) AS total FROM prx_396_parts '
            .'WHERE batch_id = '.$batch_id." AND status = 'done'")['total'];
        if ($current_total + $text_bytes > $max_text)
            throw new Prx396_Error('result_too_large', 413);

        $file_id = Storage::put($text, 'part-'.$part_no.'.txt', self::MODULE, NULL, FALSE, 'text/plain');
        try
        {
            $this->check_lease($batch_id, $attempt);
            $updated = $this->db->query(Database::UPDATE,
                'UPDATE prx_396_parts SET status = \'done\', text_file_id = '.$file_id.', text_bytes = '.$text_bytes.', '
                ."error_code = NULL, updated_at = now() WHERE batch_id = ".$batch_id.' AND part_no = '.$part_no
                ." AND status IN ('pending','failed','running')", FALSE);
            if ( ! is_numeric($updated) || (int) $updated < 1)
            {
                // Race or already done - check
                $fresh = $this->one('SELECT status FROM prx_396_parts WHERE batch_id = '.$batch_id.' AND part_no = '.$part_no);
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
        $counts = $this->one('SELECT '
            ."COUNT(*) FILTER (WHERE status = 'done') AS done, COUNT(*) AS total "
            .'FROM prx_396_parts WHERE batch_id = '.$batch_id);
        $batch = $this->one('SELECT status FROM prx_396_batches WHERE id = '.$batch_id);
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
        $counts = $this->one('SELECT '
            ."COUNT(*) FILTER (WHERE status = 'done') AS done, COUNT(*) AS total, "
            ."COALESCE(SUM(text_bytes) FILTER (WHERE status = 'done'), 0) AS bytes "
            .'FROM prx_396_parts WHERE batch_id = '.$batch_id);
        if ( ! $counts) return;
        $all_done = (int) $counts['total'] > 0 && (int) $counts['done'] === (int) $counts['total'];
        $status_sql = $all_done ? ", status = 'done', error_code = NULL" : ", status = 'running'";
        $this->db->query(Database::UPDATE,
            'UPDATE prx_396_batches SET text_bytes = '.((int) $counts['bytes']).$status_sql
            .' WHERE id = '.$batch_id." AND status IN ('queued','running','done')", FALSE);
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

        $counts = $this->one('SELECT '
            ."COUNT(*) FILTER (WHERE status = 'done') AS done, COUNT(*) AS total "
            .'FROM prx_396_parts WHERE batch_id = '.$batch_id);
        if ( ! $counts || (int) $counts['total'] === 0 || (int) $counts['done'] !== (int) $counts['total'])
            throw new Prx396_Error('batch_incomplete', 409);

        $this->refresh_batch_progress($batch_id);
        $this->db->query(Database::UPDATE,
            "UPDATE prx_396_batches SET status = 'done', error_code = NULL WHERE id = ".$batch_id
            ." AND status IN ('running','done')", FALSE);
        return ['batch_id' => $batch_id, 'status' => 'done'];
    }

    private function fail_batch(int $batch_id, string $code): void
    {
        $this->db->query(Database::UPDATE,
            'UPDATE prx_396_batches SET status = \'failed\', error_code = '.$this->db->quote($code)
            .' WHERE id = '.$batch_id." AND status IN ('queued','running','failed')", FALSE);
    }

    private function check_lease(int $batch_id, int $attempt): array
    {
        $row = $this->one('SELECT id, job_id, owner_id, file_id, file_sha256, pages_total, status, error_code, config_snapshot '
            .'FROM prx_396_batches WHERE id = '.$batch_id.' AND expires_at > now()');
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
        $batch = $this->one('SELECT id, status FROM prx_396_batches WHERE id = '.$id
            .' AND owner_id = '.(int) $owner.' AND expires_at > now()');
        if ( ! $batch) throw new Prx396_Error('not_found', 404);
        if ($batch['status'] !== 'done') throw new Prx396_Error('result_not_ready', 409);

        $rows = $this->db->query(Database::SELECT,
            'SELECT part_no, text_file_id, text_bytes, status FROM prx_396_parts '
            .'WHERE batch_id = '.$id.' ORDER BY part_no', FALSE)->as_array();
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
        $expired = $this->db->query(Database::SELECT,
            'SELECT id FROM prx_396_batches WHERE expires_at <= now() ORDER BY expires_at, id LIMIT 100', FALSE)->as_array();
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
        foreach (Storage::owned_ids_before(self::MODULE, 48) as $file_id)
        {
            if ($this->one('SELECT 1 FROM prx_396_batches WHERE file_id = '.$file_id
                .' UNION ALL SELECT 1 FROM prx_396_parts WHERE text_file_id = '.$file_id.' LIMIT 1')) continue;
            try {
                if ( ! Storage::delete_owned($file_id, self::MODULE)) throw new RuntimeException('Артефакт не удалён');
                $report['orphans']++;
            } catch (Throwable $e) {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx396 stage=purge_orphan file_id=:id', [':id' => $file_id]);
            }
        }
        return $report;
    }

    private function purge_one(int $id): bool
    {
        if ( ! $this->db->begin()) throw new RuntimeException('Не начата транзакция очистки');
        try {
            $row = $this->one('SELECT file_id FROM prx_396_batches WHERE id = '.$id.' AND expires_at <= now() FOR UPDATE');
            if ($row)
            {
                $parts = $this->db->query(Database::SELECT,
                    'SELECT text_file_id FROM prx_396_parts WHERE batch_id = '.$id.' AND text_file_id IS NOT NULL', FALSE)->as_array();
                $file_ids = [(int) $row['file_id']];
                foreach ($parts as $p) $file_ids[] = (int) $p['text_file_id'];
                foreach ($file_ids as $file_id) {
                    if ($file_id > 0 && ! Storage::delete_owned($file_id, self::MODULE))
                        throw new RuntimeException('Артефакт не удалён');
                }
                $this->db->query(Database::DELETE, 'DELETE FROM prx_396_batches WHERE id = '.$id, FALSE);
            }
            if ( ! $this->db->commit()) throw new RuntimeException('Не подтверждена очистка');
            return (bool) $row;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
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

    private function one(string $sql): ?array
    {
        $row = $this->db->query(Database::SELECT, $sql, FALSE)->current();
        return $row ? (array) $row : NULL;
    }
}
