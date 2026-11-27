<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx398_Route
{
    const CHANNEL = 'doc.route';
    const MODULE = 'prx398';
    const SCOPE = 'prx398.route';
    /** Терминальные коды: задание дальше не станет успешным. */
    const FAILED_CODES = [
        'invalid_pdf', 'encrypted_pdf', 'page_limit', 'result_too_large', 'no_text',
        'ocr_config', 'ocr_unavailable', 'ocr_auth', 'ocr_rejected', 'ocr_invalid_response',
        'ocr_incomplete', 'ocr_page_failed', 'file_unavailable', 'worker_auth',
        'worker_endpoint_unavailable', 'worker_config', 'lease_lost', 'invalid_result',
        'invalid_attempt', 'method_not_allowed', 'pdf_required', 'graph_unavailable',
    ];
    /** Тип документа → маршрут (тот же словарь у воркера: graph.py ROUTES). */
    const ROUTES = ['invoice' => 'erp', 'contract' => 'legal', 'letter' => 'inbox', 'other' => 'inbox'];
    /** Таблицы чекпойнтов LangGraph, которые создаёт AsyncPostgresSaver. */
    const CHECKPOINT_TABLES = ['checkpoints', 'checkpoint_blobs', 'checkpoint_writes'];

    private ?array $checkpoint_tables = NULL;

    private Service_Prx120_JobQueue $queue;

    public function __construct()
    {
        // JobQueue::push, INSERT регистрации и ключ идемпотентности идут через
        // одно соединение: все берут default-группу (db_group конфига = NULL).
        $this->queue = Service_Prx120_JobQueue::instance();
    }

    public function submit(Prx_Principal $principal, string $key, $upload): int
    {
        $owner = Policy_Prx398_Route::ownerId($principal);
        return $this->register($owner, $key, self::validated_upload($upload));
    }

    private function register(int $owner, string $key, array $upload): int
    {
        if ( ! preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', $key))
            throw new Prx398_Error('invalid_idempotency_key', 400);
        if (trim((string) getenv('WORKER_API_TOKEN')) === '') throw new Prx398_Error('worker_not_configured', 503);
        $key_hash = hash('sha256', $key);
        $sha = hash_file('sha256', $upload['tmp_name']);
        if ($sha === FALSE) throw new Prx398_Error('upload_unavailable', 503);

        $file_id = Storage::save($upload, self::MODULE, max_bytes: (int) Service_Prx398_Limits::get('max_pdf_bytes'));
        try { $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key, $sha); }
        catch (Throwable $e) { $this->discard($file_id); throw $e; }

        if ($idem['state'] !== Prx_Idempotency::STATE_NEW)
        {
            $this->discard($file_id);
            if ($idem['state'] === Prx_Idempotency::STATE_REPLAY) {
                $job_id = (int) ($idem['result']['job_id'] ?? 0);
                if ($job_id < 1) throw new Prx398_Error('idempotency_conflict', 409);
                return $job_id;
            }
            throw new Prx398_Error($idem['state'] === Prx_Idempotency::STATE_CONFLICT ? 'idempotency_conflict' : 'idempotency_key_expired', 409);
        }

        try {
            $job_id = Service_Prx120_JobQueue::push(self::CHANNEL, [], [
                'module' => self::MODULE,
                'max_attempts' => (int) Service_Prx398_Limits::get('max_attempts'),
                'timeout_sec' => (int) Service_Prx398_Limits::get('task_timeout_sec'),
                // Правило prx120: без visitor_id задача не попадает в «Мои задачи».
                'visitor_id' => Visitor::uid(),
            ]);
            if ($job_id < 1) throw new RuntimeException('Отсутствует ID задачи');
            $ttl = (int) Service_Prx398_Limits::get('result_ttl_hours');
            Prx_Db::execute('INSERT INTO prx_398_routes '
                .'(job_id, owner_id, file_id, request_key, file_sha256, status, expires_at) VALUES ('
                .$job_id.','.(int) $owner.','.$file_id.',:key_hash,'
                .':sha,\'queued\',now() + (:ttl * interval \'1 hour\'))',
                [':key_hash' => $key_hash, ':sha' => $sha, ':ttl' => $ttl]);
            Prx_Idempotency::finish((int) $idem['id'], ['job_id' => $job_id]);
        } catch (Prx120_Budget_Exception $e) {
            // Бюджет токенов владельца исчерпан (задача с visitor_id): снимаем
            // файл и ключ — после пополнения тот же Idempotency-Key пройдёт.
            Prx_Idempotency::abort();
            $this->discard($file_id);
            throw new Prx398_Error('budget_exceeded', 429);
        } catch (Throwable $e) {
            Prx_Idempotency::abort();
            $this->discard($file_id);
            throw $e;
        }
        Log_Plain::event(Log::INFO, 'prx398 job_id=:id stage=queued', [':id' => $job_id]);
        return $job_id;
    }

    private static function validated_upload($file): array
    {
        $error = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)) throw new Prx398_Error('file_too_large', 413);
        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], TRUE))
            throw new Prx398_Error('upload_unavailable', 503);
        if ($error !== UPLOAD_ERR_OK || ! is_string($file['tmp_name'] ?? NULL) || ! is_uploaded_file($file['tmp_name']))
            throw new Prx398_Error('file_required', 400);
        $path = $file['tmp_name'];
        $size = filesize($path);
        if ($size === FALSE) throw new Prx398_Error('upload_unavailable', 503);
        if ($size > (int) Service_Prx398_Limits::get('max_pdf_bytes')) throw new Prx398_Error('file_too_large', 413);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf'
            || file_get_contents($path, FALSE, NULL, 0, 5) !== '%PDF-')
            throw new Prx398_Error('pdf_required', 415);
        return ['tmp_name' => $path, 'name' => 'document.pdf', 'size' => $size, 'type' => 'application/pdf'];
    }

    public function status(Prx_Principal $principal, int $id): array
    {
        $owner = Policy_Prx398_Route::ownerId($principal);
        $row = $this->one('SELECT job_id, status, doc_type, route, confidence, state, error_code, attempts, expires_at '
            .'FROM prx_398_routes WHERE job_id = '.$id.' AND owner_id = '.(int) $owner.' AND expires_at > now()');
        if ( ! $row) throw new Prx398_Error('not_found', 404);
        $job = $this->queue->get($id);
        $state = $this->public_status((string) $row['status'], $job);
        $state_data = $this->decode_json($row['state']);
        $reasons = [];
        if (is_array($state_data['reasons'] ?? NULL)) {
            foreach ($state_data['reasons'] as $r) { if (is_string($r) && $r !== '') $reasons[] = $r; }
        }
        $response = [
            'job_id' => $id,
            'status' => $state,
            'doc_type' => $row['doc_type'],
            'route' => $row['route'],
            'confidence' => $row['confidence'] !== NULL ? (float) $row['confidence'] : NULL,
            'attempts' => (int) $row['attempts'],
            'reasons' => $reasons,
            'state' => $state_data,
            'expires_at' => gmdate('c', strtotime($row['expires_at'])),
        ];
        if ($state === 'failed') {
            $response['error'] = ['code' => $row['error_code'] ?: self::error_code($job)];
        }
        return $response;
    }

    private function public_status(string $db_status, ?array $job): string
    {
        if (in_array($db_status, ['done', 'review', 'failed'], TRUE)) return $db_status;
        if ( ! $job) return 'queued';
        // failed в очереди — финал (попытки исчерпаны или PermanentJobError).
        return Prx120_JobOutcome::stopped_as($job)
            ?? ['pending' => 'queued', 'processing' => 'running', 'done' => 'running'][$job['status'] ?? ''] ?? 'queued';
    }

    /** Код отказа задачи: разбор текстов очереди — у prx120 (failure_code). */
    public static function error_code(?array $job): string
    {
        return Prx120_JobOutcome::failure_code($job, self::FAILED_CODES);
    }

    public function read_pdf(int $id, int $attempt): string
    {
        $row = $this->check_lease($id, $attempt);
        $bytes = Storage::read_owned((int) $row['file_id'], self::MODULE, (int) Service_Prx398_Limits::get('max_pdf_bytes'));
        if ($bytes === NULL || ! hash_equals($row['file_sha256'], hash('sha256', $bytes)))
            throw new Prx398_Error('file_unavailable', 410);
        return $bytes;
    }

    public function store_result(int $id, int $attempt, array $body): array
    {
        $this->check_lease($id, $attempt);
        // PHP — граница доверия: в БД и клиенту уходят только известные значения.
        $error_code = $body['error_code'] ?? NULL;
        $attempts = isset($body['attempts']) && is_numeric($body['attempts']) ? max(0, min(1000, (int) $body['attempts'])) : 0;
        $state_data = is_array($body['state'] ?? NULL) ? $body['state'] : NULL;
        $doc_type = is_string($body['doc_type'] ?? NULL) && isset(self::ROUTES[$body['doc_type']]) ? $body['doc_type'] : NULL;
        $route = is_string($body['route'] ?? NULL) && in_array($body['route'], self::ROUTES, TRUE) ? $body['route'] : NULL;
        // Маршрут обязан соответствовать типу; расхождение — на ручную проверку.
        $mismatch = ($doc_type !== NULL && $route !== NULL && self::ROUTES[$doc_type] !== $route)
            || (is_string($body['doc_type'] ?? NULL) && $doc_type === NULL)
            || (is_string($body['route'] ?? NULL) && $route === NULL);
        if ($mismatch) $route = NULL;
        $confidence = $body['confidence'] ?? NULL;
        if ($confidence !== NULL && ( ! is_numeric($confidence) || $confidence < 0 || $confidence > 1))
            $confidence = NULL;

        if ($error_code !== NULL && $error_code !== '') {
            $error_code = Prx120_JobOutcome::reported_code($error_code, self::FAILED_CODES);
            $params = [':error_code' => $error_code];
            if ($doc_type) $params[':doc_type'] = $doc_type;
            if ($route) $params[':route'] = $route;
            if ($state_data) $params[':state'] = self::json($state_data);
            Prx_Db::execute(
                'UPDATE prx_398_routes SET status=\'failed\', error_code=:error_code'
                .', attempts='.(int)$attempts
                .', doc_type='.($doc_type ? ':doc_type' : 'NULL')
                .', route='.($route ? ':route' : 'NULL')
                .', confidence='.($confidence === NULL ? 'NULL' : number_format((float)$confidence,3,'.',''))
                .', state='.($state_data ? ':state::jsonb' : 'NULL')
                .', updated_at=now() WHERE job_id='.(int)$id,
                $params);
            // Терминальный отказ: продолжение не нужно, чекпойнт с текстом убираем.
            $this->forget_checkpoint((int) $id);
            return ['status' => 'failed', 'error_code' => $error_code];
        }

        $status = (string) ($body['status'] ?? 'done');
        if ( ! in_array($status, ['done','review','failed'], TRUE)) $status = 'review';
        if ($status === 'done' && ($mismatch || $doc_type === NULL || $route === NULL)) $status = 'review';
        $params = [':status' => $status];
        if ($doc_type) $params[':doc_type'] = $doc_type;
        if ($route) $params[':route'] = $route;
        if ($state_data) $params[':state'] = self::json($state_data);
        Prx_Db::execute(
            'UPDATE prx_398_routes SET status=:status'
            .', doc_type='.($doc_type ? ':doc_type' : 'NULL')
            .', route='.($route ? ':route' : 'NULL')
            .', confidence='.($confidence === NULL ? 'NULL' : number_format((float)$confidence,3,'.',''))
            .', state='.($state_data ? ':state::jsonb' : 'NULL')
            .', error_code=NULL, attempts='.(int)$attempts.', updated_at=now() WHERE job_id='.(int)$id,
            $params);
        return ['status' => $status];
    }

    /** JSON для jsonb: битый UTF-8 из OCR/LLM заменяется, а не превращает запись в ''::jsonb. */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function check_lease(int $id, int $attempt): array
    {
        $row = $this->one('SELECT job_id, file_id, file_sha256, status FROM prx_398_routes WHERE job_id = '.$id.' AND expires_at > now()');
        if ( ! $row) throw new Prx398_Error('not_found', 404);
        $job = $this->queue->get($id);
        if ( ! $job || $job['channel'] !== self::CHANNEL || $job['status'] !== 'processing' || (int) $job['attempts'] !== $attempt)
            throw new Prx398_Error('lease_lost', 409);
        return $row;
    }

    private function decode_json($v): ?array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') { $d = json_decode($v, TRUE); return is_array($d) ? $d : NULL; }
        return NULL;
    }

    public function purge(): array
    {
        $expired = Prx_Db::select(
            'SELECT job_id, file_id FROM prx_398_routes WHERE expires_at <= now() ORDER BY expires_at, job_id LIMIT 100');
        $report = ['purged' => 0, 'orphans' => 0, 'errors' => 0];
        foreach ($expired as $row) {
            $id = (int) $row['job_id']; $file_id = (int) $row['file_id'];
            try {
                if ($file_id > 0) Storage::delete_owned($file_id, self::MODULE);
                Prx_Db::execute('DELETE FROM prx_398_routes WHERE job_id = '.$id.' AND expires_at <= now()');
                $this->forget_checkpoint($id);
                $report['purged']++;
            } catch (Throwable $e) { $report['errors']++; Log_Plain::event(Log::ERROR, 'prx398 job_id=:id stage=purge error_type=:type', [':id'=>$id,':type'=>get_class($e)]); }
        }
        $sweep = Storage::sweep_orphans(self::MODULE, static fn (array $ids): array => array_column(
            Prx_Db::select('SELECT file_id FROM prx_398_routes WHERE file_id IN ('.implode(', ', array_map('intval', $ids)).')'), 'file_id'));
        $report['orphans'] += $sweep['orphans'];
        $report['errors'] += $sweep['errors'];
        return $report;
    }

    private function discard(int $file_id): void
    {
        try { if (Storage::delete_owned($file_id, self::MODULE)) return; } catch (Throwable $e) {}
        Log_Plain::event(Log::ERROR, 'prx398 stage=discard file_id=:id', [':id' => $file_id]);
    }

    private function existing_checkpoint_tables(): array
    {
        if ($this->checkpoint_tables !== NULL) return $this->checkpoint_tables;
        $found = [];
        foreach (self::CHECKPOINT_TABLES as $table)
        {
            if ($this->one('SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() '
                .'AND table_name = :table', [':table' => $table])) $found[] = $table;
        }
        return $this->checkpoint_tables = $found;
    }

    /**
     * Удалить чекпойнт потока (thread_id = job_id). Чекпойнт LangGraph хранит
     * полный OCR-текст, а не только публичное состояние графа, поэтому его
     * нельзя оставлять после истечения TTL. Таблиц может не быть (чекпойнт
     * memory) — тогда молча ничего не делаем.
     */
    private function forget_checkpoint(int $job_id): void
    {
        foreach ($this->existing_checkpoint_tables() as $table)
        {
            try {
                Prx_Db::execute(
                    'DELETE FROM '.$table.' WHERE thread_id = '.(int) $job_id);
            } catch (Throwable $e) {
                Log_Plain::event(Log::ERROR, 'prx398 job_id=:id stage=checkpoint_purge error_type=:type',
                    [':id' => $job_id, ':type' => get_class($e)]);
            }
        }
    }

    private function one(string $sql, array $params = []): ?array
    {
        return Prx_Db::one($sql, $params);
    }
}
