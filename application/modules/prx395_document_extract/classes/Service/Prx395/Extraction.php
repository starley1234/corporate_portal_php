<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx395_Extraction
{
    const CHANNEL = 'document.extract';
    const MODULE = 'prx395';
    const MAX_PDF = 10485760;
    const MAX_TEXT = 1048576;
    const MAX_PAGES = 20;
    const FAILED_CODES = [
        'invalid_pdf', 'encrypted_pdf', 'page_limit', 'result_too_large', 'no_text',
        'ocr_unavailable', 'ocr_timeout', 'ocr_auth', 'ocr_config', 'ocr_rejected',
        'ocr_invalid_response', 'ocr_incomplete', 'ocr_page_failed', 'file_unavailable',
        'worker_auth', 'worker_endpoint_unavailable', 'worker_config', 'lease_lost',
    ];

    private Database $db;
    private Service_Prx120_JobQueue $queue;
    private Service_Prx212_Storage $storage;

    public function __construct()
    {
        // JobQueue::push и INSERT регистрации должны использовать одно соединение.
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
        $this->queue = Service_Prx120_JobQueue::instance();
        $this->storage = Service_Prx212_Storage::instance();
    }

    public function submit(string $client, string $key, $upload): int
    {
        if ( ! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key))
            throw new Prx395_Error('invalid_idempotency_key', 400);
        if (trim((string) getenv('WORKER_API_TOKEN')) === '') throw new Prx395_Error('worker_not_configured', 503);
        $upload = self::validated_upload($upload);
        $key_hash = hash('sha256', $key);
        $sha = hash_file('sha256', $upload['tmp_name']);
        if ($sha === FALSE) throw new Prx395_Error('upload_unavailable', 503);
        $owner = 'client_id = '.$this->db->quote($client).' AND request_key = '.$this->db->quote($key_hash);
        $existing = $this->one('SELECT job_id, file_sha256 FROM prx_395_extractions WHERE '.$owner.' AND expires_at > now()');
        if ($existing) return $this->replay($existing, $sha);

        // ФС не откатывается вместе с PostgreSQL. Сохраняем через prx212 вне
        // транзакции; при отказе транзакции удаляем артефакт отдельной операцией.
        $file_id = (int) $this->storage->save($upload, ['module' => self::MODULE, 'is_public' => 0]);
        try {
            if ( ! $this->db->begin()) throw new RuntimeException('Не начата транзакция постановки задачи');
            try {
                // Сериализуем только один ключ одного клиента; UNIQUE — второй барьер.
                $lock_key = $this->db->quote(self::MODULE.':'.$client.':'.$key_hash);
                $this->one('SELECT pg_advisory_xact_lock(hashtextextended('.$lock_key.', 0))');
                $existing = $this->one('SELECT job_id, file_sha256, (expires_at <= now())::int AS expired FROM prx_395_extractions WHERE '.$owner);
                if ($existing)
                {
                    if ((int) $existing['expired'] === 1) throw new Prx395_Error('idempotency_key_expired', 409);
                    $job_id = $this->replay($existing, $sha);
                }
                else
                {
                    $job_id = Service_Prx120_JobQueue::push(self::CHANNEL, [], [
                        'module' => self::MODULE, 'max_attempts' => 3, 'timeout_sec' => 300,
                    ]);
                    if ($job_id < 1) throw new RuntimeException('Отсутствует ID задачи');
                    $this->db->query(Database::INSERT, 'INSERT INTO prx_395_extractions '
                        .'(job_id, client_id, request_key, file_sha256, file_id) VALUES ('
                        .$job_id.','.$this->db->quote($client).','.$this->db->quote($key_hash).','.$this->db->quote($sha).','.$file_id.')', FALSE);
                }
                if ( ! $this->db->commit()) throw new RuntimeException('Не подтверждена транзакция постановки задачи');
            } catch (Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        } catch (Throwable $e) {
            $this->discard($file_id);
            throw $e;
        }
        if ($existing) $this->discard($file_id);
        Log_Plain::event(Log::INFO, 'prx395 job_id=:id stage=queued_or_replayed', [':id' => $job_id]);
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
        if ($size > self::MAX_PDF) throw new Prx395_Error('file_too_large', 413);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf'
            || file_get_contents($path, FALSE, NULL, 0, 5) !== '%PDF-')
            throw new Prx395_Error('pdf_required', 415);
        // Исходное имя не нужно для распознавания и не попадает в БД/логи.
        return ['tmp_name' => $path, 'name' => 'document.pdf', 'size' => $size, 'type' => 'application/pdf'];
    }

    public function status(string $client, int $id): array
    {
        $row = $this->one('SELECT expires_at FROM prx_395_extractions WHERE job_id = '.$id
            .' AND client_id = '.$this->db->quote($client).' AND expires_at > now()');
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
            $text = $this->storage->read_owned((int) $result['file_id'], self::MODULE, self::MAX_TEXT);
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
        $bytes = $this->storage->read_owned((int) $row['file_id'], self::MODULE, self::MAX_PDF);
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
        $file_id = (int) $this->storage->saveRaw($body['text'], 'result.txt', ['module' => self::MODULE], 'text/plain');
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
        if (is_string($text) && strlen($text) > self::MAX_TEXT) throw new Prx395_Error('result_too_large', 413);
        if ( ! is_string($text) || trim($text) === '' || ! mb_check_encoding($text, 'UTF-8')
            || ! in_array($provider, ['remote', 'tesseract'], TRUE) || $format !== ($provider === 'remote' ? 'markdown' : 'plain')
            || ! is_int($total) || $total < 1 || $total > self::MAX_PAGES
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

    private function replay(array $row, string $sha): int
    {
        if ( ! hash_equals($row['file_sha256'], $sha)) throw new Prx395_Error('idempotency_conflict', 409);
        return (int) $row['job_id'];
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
        // Компенсация после SIGKILL между сохранением в storage и записью
        // ссылки в БД. Запас 48 ч больше любого TTL/таймаута нашего контура.
        foreach ($this->storage->owned_file_ids_before(self::MODULE, 48) as $file_id)
        {
            if ($this->one('SELECT 1 FROM prx_395_extractions WHERE file_id = '.$file_id
                .' UNION ALL SELECT 1 FROM prx_395_results WHERE file_id = '.$file_id.' LIMIT 1')) continue;
            try {
                if ( ! $this->storage->delete_owned($file_id, self::MODULE)) throw new RuntimeException('Артефакт не удалён');
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
                    if ( ! $this->storage->delete_owned((int) $file_id, self::MODULE)) throw new RuntimeException('Артефакт не удалён');
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
            if ($this->storage->delete_owned($file_id, self::MODULE)) return;
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
