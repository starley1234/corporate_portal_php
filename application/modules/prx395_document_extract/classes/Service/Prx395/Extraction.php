<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx395_Extraction
{
    const CHANNEL = 'document.extract';
    const MODULE = 'prx395';
    /** Scope внешнего ключа prx150, дающий доступ к этому сервису. */
    const SCOPE = 'prx395.extract';
    const FAILED_CODES = [
        'invalid_pdf', 'encrypted_pdf', 'page_limit', 'result_too_large', 'no_text',
        'ocr_unavailable', 'ocr_timeout', 'ocr_auth', 'ocr_config', 'ocr_rejected',
        'ocr_invalid_response', 'ocr_incomplete', 'ocr_page_failed', 'file_unavailable',
        'worker_auth', 'worker_endpoint_unavailable', 'worker_config', 'lease_lost',
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
        $owner = Policy_Prx395_Extraction::ownerId($principal);
        return $this->register($owner, $key, self::validated_upload($upload));
    }

    public function submit_sample(Prx_Actor $actor, string $key): int
    {
        $owner = Policy_Prx395_Extraction::ownerId($actor);
        $path = dirname(__DIR__, 3).'/fixtures/sample.pdf';
        return $this->register($owner, $key, [
            'tmp_name' => $path, 'name' => 'document.pdf', 'size' => filesize($path), 'type' => 'application/pdf',
        ]);
    }

    private function register(int $client, string $key, array $upload): int
    {
        if ( ! preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', $key))
            throw new Prx395_Error('invalid_idempotency_key', 400);
        if (trim((string) getenv('WORKER_API_TOKEN')) === '') throw new Prx395_Error('worker_not_configured', 503);
        $key_hash = hash('sha256', $key);
        $sha = hash_file('sha256', $upload['tmp_name']);
        if ($sha === FALSE) throw new Prx395_Error('upload_unavailable', 503);

        // ФС не откатывается вместе с PostgreSQL. Сохраняем через prx212 вне
        // транзакции; если ключ занят или транзакция не подтверждена — удаляем.
        $file_id = Storage::save($upload, self::MODULE, max_bytes: Service_Prx395_Limits::get('max_pdf_bytes'));

        try
        {
            $idem = Prx_Idempotency::begin(self::SCOPE, (string) $client, $key, $sha, $this->db);
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
                $job_id = (int) ($idem['result']['job_id'] ?? 0);

                if ($job_id < 1) throw new Prx395_Error('idempotency_conflict', 409);
                Log_Plain::event(Log::INFO, 'prx395 job_id=:id stage=replayed', [':id' => $job_id]);
                return $job_id;
            }

            throw new Prx395_Error(
                $idem['state'] === Prx_Idempotency::STATE_CONFLICT ? 'idempotency_conflict' : 'idempotency_key_expired',
                409
            );
        }

        try
        {
            $job_id = Service_Prx120_JobQueue::push(self::CHANNEL, [], [
                'module' => self::MODULE,
                'max_attempts' => Service_Prx395_Limits::get('max_attempts'),
                'timeout_sec' => Service_Prx395_Limits::get('task_timeout_sec'),
            ]);
            if ($job_id < 1) throw new RuntimeException('Отсутствует ID задачи');
            // push и ссылка на задание — в одной транзакции с ключом идемпотентности.
            $ttl = Service_Prx395_Limits::get('result_ttl_hours');
            $this->db->query(Database::INSERT, 'INSERT INTO prx_395_extractions '
                .'(job_id, api_client_id, request_key, file_sha256, file_id, expires_at) VALUES ('
                .$job_id.','.(int) $client.','.$this->db->quote($key_hash).','
                .$this->db->quote($sha).','.$file_id.",now() + ({$ttl} * interval '1 hour'))", FALSE);
            Prx_Idempotency::finish((int) $idem['id'], ['job_id' => $job_id], $this->db);
        }
        catch (Throwable $e)
        {
            Prx_Idempotency::abort($this->db);
            $this->discard($file_id);
            throw $e;
        }

        Log_Plain::event(Log::INFO, 'prx395 job_id=:id stage=queued', [':id' => $job_id]);
        return $job_id;
    }

    private static function validated_upload($file): array
    {
        $error = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)) throw new Prx395_Error('file_too_large', 413);
        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], TRUE))
            throw new Prx395_Error('upload_unavailable', 503);
        if ($error !== UPLOAD_ERR_OK || ! is_string($file['tmp_name'] ?? NULL) || ! is_uploaded_file($file['tmp_name']))
            throw new Prx395_Error('file_required', 400);
        $path = $file['tmp_name'];
        $size = filesize($path);
        if ($size === FALSE) throw new Prx395_Error('upload_unavailable', 503);
        if ($size > Service_Prx395_Limits::get('max_pdf_bytes')) throw new Prx395_Error('file_too_large', 413);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf'
            || file_get_contents($path, FALSE, NULL, 0, 5) !== '%PDF-')
            throw new Prx395_Error('pdf_required', 415);
        // Исходное имя не нужно для распознавания и не попадает в БД/логи.
        return ['tmp_name' => $path, 'name' => 'document.pdf', 'size' => $size, 'type' => 'application/pdf'];
    }

    public function status(Prx_Principal $principal, int $id): array
    {
        $client = Policy_Prx395_Extraction::ownerId($principal);
        $row = $this->one('SELECT expires_at FROM prx_395_extractions WHERE job_id = '.$id
            .' AND api_client_id = '.(int) $client.' AND expires_at > now()');
        if ( ! $row) throw new Prx395_Error('not_found', 404);
        $job = $this->queue->get($id);
        $state = [
            'pending' => 'queued', 'processing' => 'running', 'done' => 'succeeded',
            'failed' => 'failed', 'cancelled' => 'canceled',
        ][$job['status'] ?? ''] ?? 'failed';
        $response = ['job_id' => $id, 'status' => $state, 'attempt' => (int) ($job['attempts'] ?? 0),
            'expires_at' => gmdate('c', strtotime($row['expires_at']))];
        if ($state === 'failed')
        {
            $response['error'] = ['code' => self::error_code($job['error'] ?? '', $job !== NULL)];
        }
        elseif ($state === 'succeeded')
        {
            $receipt = $job['result']['receipt'] ?? NULL;
            $result = is_string($receipt) && preg_match('/\A[a-f0-9]{32}\z/', $receipt)
                ? $this->result_row($id, (int) $job['attempts'], $receipt) : NULL;
            if ( ! $result) throw new Prx395_Error('result_unavailable', 503);
            $text = Storage::read_owned((int) $result['file_id'], self::MODULE, Service_Prx395_Limits::get('max_text_bytes'));
            if ($text === NULL) throw new Prx395_Error('result_unavailable', 503);
            $response['result'] = ['text' => $text] + json_decode($result['metadata'], TRUE, 8, JSON_THROW_ON_ERROR);
        }
        return $response;
    }

    public static function error_code(string $error, bool $found = TRUE): string
    {
        if ( ! $found) return 'job_unavailable';
        if (preg_match('/\A(?:DocumentJobError|PermanentJobError): ([a-z_]+)\z/', $error, $m)
            && in_array($m[1], self::FAILED_CODES, TRUE)) return $m[1];
        if (str_starts_with($error, 'Превышен таймаут задачи')) return 'job_timeout';
        return 'job_failed';
    }

    public function read_pdf(int $id, int $attempt): string
    {
        $row = $this->check_lease($id, $attempt);
        $bytes = Storage::read_owned((int) $row['file_id'], self::MODULE, Service_Prx395_Limits::get('max_pdf_bytes'));
        if ($bytes === NULL || ! hash_equals($row['file_sha256'], hash('sha256', $bytes)))
            throw new Prx395_Error('file_unavailable', 410);
        return $bytes;
    }

    public function store_result(int $id, int $attempt, array $body): string
    {
        $this->check_lease($id, $attempt);
        $metadata = self::validate_result($body);
        $current = $this->result_row($id, $attempt);
        if ($current) return $current['receipt']; // повтор POST после потери ответа
        $receipt = bin2hex(random_bytes(16));
        $file_id = Storage::put($body['text'], 'result.txt', self::MODULE, NULL, FALSE, 'text/plain');
        try {
            $this->check_lease($id, $attempt);
            $result = $this->one('INSERT INTO prx_395_results (job_id, attempt, receipt, file_id, metadata) '
                .'SELECT '.$id.','.$attempt.','.$this->db->quote($receipt).','.$file_id.','
                .$this->db->quote(json_encode($metadata, JSON_THROW_ON_ERROR)).'::jsonb '
                .'FROM prx_395_extractions WHERE job_id = '.$id.' AND expires_at > now() '
                .'ON CONFLICT (job_id, attempt) DO NOTHING RETURNING receipt');
        } catch (Throwable $e) {
            $this->discard($file_id);
            throw $e;
        }
        if ($result) return $result['receipt'];
        $this->discard($file_id);
        $current = $this->result_row($id, $attempt);
        if ($current) return $current['receipt'];
        throw new Prx395_Error('lease_lost', 409);
    }

    public static function validate_result(array $body): array
    {
        $text = $body['text'] ?? NULL;
        $provider = $body['provider'] ?? NULL;
        $format = $body['format'] ?? NULL;
        $total = $body['pages_total'] ?? NULL;
        $pages = $body['pages_processed'] ?? NULL;
        $warnings = $body['warnings'] ?? [];
        if (is_string($text) && strlen($text) > Service_Prx395_Limits::get('max_text_bytes')) throw new Prx395_Error('result_too_large', 413);
        if ( ! is_string($text) || trim($text) === '' || ! mb_check_encoding($text, 'UTF-8')
            || ! in_array($provider, ['remote', 'tesseract'], TRUE) || $format !== ($provider === 'remote' ? 'markdown' : 'plain')
            || ! is_int($total) || $total < 1 || $total > Service_Prx395_Limits::get('max_pages')
            || ($pages !== NULL && (! is_int($pages) || $pages !== $total))
            || ($provider === 'tesseract' && $pages === NULL)
            || ! in_array($warnings, [[], ['empty_page']], TRUE))
            throw new Prx395_Error('invalid_result', 400);
        return ['provider' => $provider, 'format' => $format, 'pages_total' => $total,
            'pages_processed' => $pages, 'warnings' => $warnings];
    }

    private function check_lease(int $id, int $attempt): array
    {
        $row = $this->one('SELECT file_id, file_sha256 FROM prx_395_extractions WHERE job_id = '.$id.' AND expires_at > now()');
        if ( ! $row) throw new Prx395_Error('not_found', 404);
        $job = $this->queue->get($id);
        if ( ! $job || $job['channel'] !== self::CHANNEL || $job['status'] !== 'processing' || (int) $job['attempts'] !== $attempt)
            throw new Prx395_Error('lease_lost', 409);
        return $row;
    }

    private function result_row(int $id, int $attempt, ?string $receipt = NULL): ?array
    {
        return $this->one('SELECT receipt, file_id, metadata FROM prx_395_results WHERE job_id = '.$id.' AND attempt = '.$attempt
            .($receipt === NULL ? '' : ' AND receipt = '.$this->db->quote($receipt)));
    }

    public function purge(): array
    {
        $expired = $this->db->query(Database::SELECT,
            'SELECT job_id FROM prx_395_extractions WHERE expires_at <= now() ORDER BY expires_at, job_id LIMIT 100', FALSE)->as_array();
        $report = ['purged' => 0, 'orphans' => 0, 'errors' => 0];
        foreach ($expired as $candidate)
        {
            $id = (int) $candidate['job_id'];
            try {
                if ($this->purge_one($id)) $report['purged']++;
            } catch (Throwable $e) {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx395 job_id=:id stage=purge error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            }
        }
        // Компенсация после SIGKILL между сохранением в Storage и записью
        // ссылки в БД. Возрастной порог — страховка для сирот; файлы со ссылками
        // в БД не удаляются этим обходом независимо от TTL.
        foreach (Storage::owned_ids_before(self::MODULE, 48) as $file_id)
        {
            if ($this->one('SELECT 1 FROM prx_395_extractions WHERE file_id = '.$file_id
                .' UNION ALL SELECT 1 FROM prx_395_results WHERE file_id = '.$file_id.' LIMIT 1')) continue;
            try {
                if ( ! Storage::delete_owned($file_id, self::MODULE)) throw new RuntimeException('Артефакт не удалён');
                $report['orphans']++;
            } catch (Throwable $e) {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx395 stage=purge_orphan file_id=:id', [':id' => $file_id]);
            }
        }
        return $report;
    }

    private function purge_one(int $id): bool
    {
        if ( ! $this->db->begin()) throw new RuntimeException('Не начата транзакция очистки');
        try {
            // FK на попытки сериализует удаление с их записью. Если попытка
            // завершает запись, сначала ждём её, потом удаляем все артефакты.
            $row = $this->one('SELECT file_id FROM prx_395_extractions WHERE job_id = '.$id.' AND expires_at <= now() FOR UPDATE');
            if ($row)
            {
                $files = $this->db->query(Database::SELECT, 'SELECT file_id FROM prx_395_results WHERE job_id = '.$id, FALSE)->as_array();
                foreach (array_merge([$row['file_id']], array_column($files, 'file_id')) as $file_id) {
                    if ( ! Storage::delete_owned((int) $file_id, self::MODULE)) throw new RuntimeException('Артефакт не удалён');
                }
                $this->db->query(Database::DELETE, 'DELETE FROM prx_395_extractions WHERE job_id = '.$id, FALSE);
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
        Log_Plain::event(Log::ERROR, 'prx395 stage=discard file_id=:id', [':id' => $file_id]);
    }

    private function one(string $sql): ?array
    {
        $row = $this->db->query(Database::SELECT, $sql, FALSE)->current();
        return $row ? (array) $row : NULL;
    }
}
