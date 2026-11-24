<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx398_Route
{
    const CHANNEL = 'doc.route';
    const MODULE = 'prx398';
    const SCOPE = 'prx398.route';

    private Database $db;
    private Service_Prx120_JobQueue $queue;

    public function __construct()
    {
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
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
        try { $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key, $sha, $this->db); }
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
            ]);
            if ($job_id < 1) throw new RuntimeException('Отсутствует ID задачи');
            $ttl = (int) Service_Prx398_Limits::get('result_ttl_hours');
            $this->db->query(Database::INSERT, 'INSERT INTO prx_398_routes '
                .'(job_id, owner_id, file_id, request_key, file_sha256, status, expires_at) VALUES ('
                .$job_id.','.(int) $owner.','.$file_id.','.$this->db->quote($key_hash).','
                .$this->db->quote($sha).",'queued',now() + (".$ttl." * interval '1 hour'))", FALSE);
            Prx_Idempotency::finish((int) $idem['id'], ['job_id' => $job_id], $this->db);
        } catch (Throwable $e) {
            Prx_Idempotency::abort($this->db);
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
            $response['error'] = ['code' => $row['error_code'] ?: self::error_code($job['error'] ?? '', $job !== NULL)];
        }
        return $response;
    }

    private function public_status(string $db_status, ?array $job): string
    {
        if (in_array($db_status, ['done', 'review', 'failed'], TRUE)) return $db_status;
        if ( ! $job) return 'queued';
        $map = ['pending' => 'queued', 'processing' => 'running', 'done' => 'running', 'failed' => 'failed', 'cancelled' => 'canceled'];
        $js = $map[$job['status'] ?? ''] ?? 'queued';
        if ($js === 'failed' && $db_status !== 'failed') return 'queued';
        return $js;
    }

    public static function error_code(string $error, bool $found = TRUE): string
    {
        if ( ! $found) return 'job_unavailable';
        if (preg_match('/\A(?:DocumentJobError|PermanentJobError): ([a-z_]+)\z/', $error, $m)) return $m[1];
        if (str_starts_with($error, 'Превышен таймаут задачи')) return 'job_timeout';
        return 'job_failed';
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
        $error_code = $body['error_code'] ?? NULL;
        $attempts = isset($body['attempts']) && is_numeric($body['attempts']) ? (int) $body['attempts'] : 0;
        $state_data = is_array($body['state'] ?? NULL) ? $body['state'] : NULL;
        $doc_type = is_string($body['doc_type'] ?? NULL) ? $body['doc_type'] : NULL;
        $route = is_string($body['route'] ?? NULL) ? $body['route'] : NULL;
        $confidence = $body['confidence'] ?? NULL;
        if ($confidence !== NULL && ( ! is_numeric($confidence) || $confidence < 0 || $confidence > 1))
            $confidence = NULL;

        if (is_string($error_code) && $error_code !== '') {
            $this->db->query(Database::UPDATE,
                'UPDATE prx_398_routes SET status=\'failed\', error_code='.$this->db->quote($error_code)
                .', attempts='.(int)$attempts
                .', doc_type='.($doc_type ? $this->db->quote($doc_type) : 'NULL')
                .', route='.($route ? $this->db->quote($route) : 'NULL')
                .', confidence='.($confidence === NULL ? 'NULL' : number_format((float)$confidence,3,'.',''))
                .', state='.($state_data ? $this->db->quote(json_encode($state_data, JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL')
                .', updated_at=now() WHERE job_id='.(int)$id, FALSE);
            return ['status' => 'failed', 'error_code' => $error_code];
        }

        $status = (string) ($body['status'] ?? 'done');
        if ( ! in_array($status, ['done','review','failed'], TRUE)) $status = 'review';
        $this->db->query(Database::UPDATE,
            'UPDATE prx_398_routes SET status='.$this->db->quote($status)
            .', doc_type='.($doc_type ? $this->db->quote($doc_type) : 'NULL')
            .', route='.($route ? $this->db->quote($route) : 'NULL')
            .', confidence='.($confidence === NULL ? 'NULL' : number_format((float)$confidence,3,'.',''))
            .', state='.($state_data ? $this->db->quote(json_encode($state_data, JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL')
            .', error_code=NULL, attempts='.(int)$attempts.', updated_at=now() WHERE job_id='.(int)$id, FALSE);
        return ['status' => $status];
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
        $expired = $this->db->query(Database::SELECT,
            'SELECT job_id, file_id FROM prx_398_routes WHERE expires_at <= now() ORDER BY expires_at, job_id LIMIT 100', FALSE)->as_array();
        $report = ['purged' => 0, 'orphans' => 0, 'errors' => 0];
        foreach ($expired as $row) {
            $id = (int) $row['job_id']; $file_id = (int) $row['file_id'];
            try {
                if ($file_id > 0) Storage::delete_owned($file_id, self::MODULE);
                $this->db->query(Database::DELETE, 'DELETE FROM prx_398_routes WHERE job_id = '.$id.' AND expires_at <= now()', FALSE);
                $report['purged']++;
            } catch (Throwable $e) { $report['errors']++; Log_Plain::event(Log::ERROR, 'prx398 job_id=:id stage=purge error_type=:type', [':id'=>$id,':type'=>get_class($e)]); }
        }
        foreach (Storage::owned_ids_before(self::MODULE, 48) as $file_id) {
            if ($this->one('SELECT 1 FROM prx_398_routes WHERE file_id = '.$file_id.' LIMIT 1')) continue;
            try { if (Storage::delete_owned($file_id, self::MODULE)) $report['orphans']++; } catch (Throwable $e) { $report['errors']++; }
        }
        return $report;
    }

    private function discard(int $file_id): void
    {
        try { if (Storage::delete_owned($file_id, self::MODULE)) return; } catch (Throwable $e) {}
        Log_Plain::event(Log::ERROR, 'prx398 stage=discard file_id=:id', [':id' => $file_id]);
    }

    private function one(string $sql): ?array
    {
        $row = $this->db->query(Database::SELECT, $sql, FALSE)->current();
        return $row ? (array) $row : NULL;
    }
}
