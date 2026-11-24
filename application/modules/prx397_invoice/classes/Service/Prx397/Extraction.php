<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx397_Extraction
{
    const CHANNEL = 'invoice.extract';
    const MODULE = 'prx397';
    const SCOPE = 'prx397.extract';

    private Database $db;
    private Service_Prx120_JobQueue $queue;

    public function __construct()
    {
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
        $this->queue = Service_Prx120_JobQueue::instance();
    }

    public function submit(Prx_Principal $principal, string $key, $upload): int
    {
        $owner = Policy_Prx397_Extraction::ownerId($principal);
        return $this->register($owner, $key, self::validated_upload($upload));
    }

    private function register(int $owner, string $key, array $upload): int
    {
        if ( ! preg_match('/'.Prx_Idempotency::KEY_PATTERN.'/', $key))
            throw new Prx397_Error('invalid_idempotency_key', 400);
        if (trim((string) getenv('WORKER_API_TOKEN')) === '') throw new Prx397_Error('worker_not_configured', 503);
        $key_hash = hash('sha256', $key);
        $sha = hash_file('sha256', $upload['tmp_name']);
        if ($sha === FALSE) throw new Prx397_Error('upload_unavailable', 503);

        $file_id = Storage::save($upload, self::MODULE, max_bytes: Service_Prx397_Limits::get('max_pdf_bytes'));
        try {
            $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key, $sha, $this->db);
        } catch (Throwable $e) {
            $this->discard($file_id);
            throw $e;
        }

        if ($idem['state'] !== Prx_Idempotency::STATE_NEW)
        {
            $this->discard($file_id);
            if ($idem['state'] === Prx_Idempotency::STATE_REPLAY)
            {
                $job_id = (int) ($idem['result']['job_id'] ?? 0);
                if ($job_id < 1) throw new Prx397_Error('idempotency_conflict', 409);
                return $job_id;
            }
            throw new Prx397_Error($idem['state'] === Prx_Idempotency::STATE_CONFLICT ? 'idempotency_conflict' : 'idempotency_key_expired', 409);
        }

        try {
            $job_id = Service_Prx120_JobQueue::push(self::CHANNEL, [], [
                'module' => self::MODULE,
                'max_attempts' => Service_Prx397_Limits::get('max_attempts'),
                'timeout_sec' => Service_Prx397_Limits::get('task_timeout_sec'),
            ]);
            if ($job_id < 1) throw new RuntimeException('Отсутствует ID задачи');
            $ttl = Service_Prx397_Limits::get('result_ttl_hours');
            $this->db->query(Database::INSERT, 'INSERT INTO prx_397_invoices '
                .'(job_id, owner_id, file_id, request_key, file_sha256, status, expires_at) VALUES ('
                .$job_id.','.(int) $owner.','.$file_id.','.$this->db->quote($key_hash).','
                .$this->db->quote($sha).",'queued',now() + (".$ttl." * interval '1 hour'))", FALSE);
            Prx_Idempotency::finish((int) $idem['id'], ['job_id' => $job_id], $this->db);
        } catch (Throwable $e) {
            Prx_Idempotency::abort($this->db);
            $this->discard($file_id);
            throw $e;
        }
        Log_Plain::event(Log::INFO, 'prx397 job_id=:id stage=queued', [':id' => $job_id]);
        return $job_id;
    }

    private static function validated_upload($file): array
    {
        $error = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)) throw new Prx397_Error('file_too_large', 413);
        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], TRUE))
            throw new Prx397_Error('upload_unavailable', 503);
        if ($error !== UPLOAD_ERR_OK || ! is_string($file['tmp_name'] ?? NULL) || ! is_uploaded_file($file['tmp_name']))
            throw new Prx397_Error('file_required', 400);
        $path = $file['tmp_name'];
        $size = filesize($path);
        if ($size === FALSE) throw new Prx397_Error('upload_unavailable', 503);
        if ($size > Service_Prx397_Limits::get('max_pdf_bytes')) throw new Prx397_Error('file_too_large', 413);
        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf'
            || file_get_contents($path, FALSE, NULL, 0, 5) !== '%PDF-')
            throw new Prx397_Error('pdf_required', 415);
        return ['tmp_name' => $path, 'name' => 'document.pdf', 'size' => $size, 'type' => 'application/pdf'];
    }

    public function status(Prx_Principal $principal, int $id): array
    {
        $owner = Policy_Prx397_Extraction::ownerId($principal);
        $row = $this->one('SELECT job_id, status, fields, checks, confidence, error_code, expires_at '
            .'FROM prx_397_invoices WHERE job_id = '.$id.' AND owner_id = '.(int) $owner.' AND expires_at > now()');
        if ( ! $row) throw new Prx397_Error('not_found', 404);

        $job = $this->queue->get($id);
        $state = $this->public_status((string) $row['status'], $job);
        $checks = $this->decode_json($row['checks']);
        $fields = $this->decode_json($row['fields']);
        $reasons = [];
        if (is_array($checks)) {
            foreach ($checks as $c) {
                if (is_array($c) && empty($c['ok']) && ! empty($c['code'])) $reasons[] = (string) $c['code'];
            }
        }

        $response = [
            'job_id' => $id,
            'status' => $state,
            'attempt' => (int) ($job['attempts'] ?? 0),
            'fields' => is_array($fields) ? $fields : NULL,
            'checks' => is_array($checks) ? $checks : [],
            'reasons' => $reasons,
            'confidence' => $row['confidence'] !== NULL ? (float) $row['confidence'] : NULL,
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
        if ($js === 'failed' && $db_status !== 'failed') return 'queued'; // retry
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
        $bytes = Storage::read_owned((int) $row['file_id'], self::MODULE, Service_Prx397_Limits::get('max_pdf_bytes'));
        if ($bytes === NULL || ! hash_equals($row['file_sha256'], hash('sha256', $bytes)))
            throw new Prx397_Error('file_unavailable', 410);
        return $bytes;
    }

    public function store_result(int $id, int $attempt, array $body): array
    {
        $this->check_lease($id, $attempt);
        $error_code = $body['error_code'] ?? NULL;
        if (is_string($error_code) && $error_code !== '') {
            $this->db->query(Database::UPDATE,
                'UPDATE prx_397_invoices SET status = \'failed\', error_code = '.$this->db->quote($error_code)
                .', updated_at = now() WHERE job_id = '.(int) $id, FALSE);
            return ['status' => 'failed', 'error_code' => $error_code];
        }

        $fields = $body['fields'] ?? NULL;
        $confidence = $body['confidence'] ?? NULL;
        $text_chars = $body['text_chars'] ?? NULL;
        if ( ! is_array($fields)) throw new Prx397_Error('invalid_result', 400);
        if ($confidence !== NULL && ( ! is_numeric($confidence) || $confidence < 0 || $confidence > 1))
            throw new Prx397_Error('invalid_result', 400);
        if (is_int($text_chars) && $text_chars > Service_Prx397_Limits::get('max_llm_chars')) {
            $this->db->query(Database::UPDATE, "UPDATE prx_397_invoices SET status='failed', error_code='text_too_large_for_llm', updated_at=now() WHERE job_id=".(int)$id, FALSE);
            return ['status' => 'failed', 'error_code' => 'text_too_large_for_llm'];
        }

        $normalized = self::normalize_fields($fields);
        $checks = self::run_checks($normalized);
        $ok = TRUE;
        foreach ($checks as $c) { if (empty($c['ok'])) { $ok = FALSE; break; } }
        $status = $ok ? 'done' : 'review';

        $this->db->query(Database::UPDATE,
            'UPDATE prx_397_invoices SET status = '.$this->db->quote($status)
            .', fields = '.$this->db->quote(json_encode($normalized, JSON_UNESCAPED_UNICODE)).'::jsonb'
            .', checks = '.$this->db->quote(json_encode($checks, JSON_UNESCAPED_UNICODE)).'::jsonb'
            .', confidence = '.($confidence === NULL ? 'NULL' : number_format((float) $confidence, 3, '.', ''))
            .', error_code = NULL, updated_at = now() WHERE job_id = '.$id, FALSE);

        return ['status' => $status, 'checks' => $checks];
    }

    public static function normalize_fields(array $f): array
    {
        $str = static fn($v) => is_scalar($v) ? trim((string) $v) : '';
        $num = static function ($v) {
            if (is_int($v) || is_float($v)) return (float) $v;
            if (is_string($v) && preg_match('/\A-?[0-9]+(?:[.,][0-9]+)?\z/', trim($v))) return (float) str_replace(',', '.', trim($v));
            return NULL;
        };
        $party = static fn($v) => [
            'name' => $str(is_array($v) ? ($v['name'] ?? '') : ''),
            'inn' => $str(is_array($v) ? ($v['inn'] ?? '') : ''),
        ];
        $lines = [];
        if (is_array($f['lines'] ?? NULL)) {
            foreach ($f['lines'] as $ln) {
                if ( ! is_array($ln)) continue;
                $lines[] = [
                    'name' => $str($ln['name'] ?? ''),
                    'qty' => $num($ln['qty'] ?? NULL),
                    'price' => $num($ln['price'] ?? NULL),
                    'sum' => $num($ln['sum'] ?? NULL),
                ];
            }
        }
        return [
            'number' => $str($f['number'] ?? ''),
            'date' => $str($f['date'] ?? ''),
            'seller' => $party($f['seller'] ?? []),
            'buyer' => $party($f['buyer'] ?? []),
            'total' => $num($f['total'] ?? NULL),
            'vat' => $num($f['vat'] ?? NULL),
            'currency' => $str($f['currency'] ?? ''),
            'lines' => $lines,
        ];
    }

    public static function run_checks(array $f): array
    {
        $checks = [];
        $required = [
            'number' => $f['number'] !== '',
            'date' => $f['date'] !== '',
            'seller_name' => ($f['seller']['name'] ?? '') !== '',
            'buyer_name' => ($f['buyer']['name'] ?? '') !== '',
            'total' => $f['total'] !== NULL,
        ];
        foreach ($required as $code => $ok) $checks[] = ['code' => 'required_'.$code, 'ok' => (bool) $ok];

        $checks[] = ['code' => 'date_parseable', 'ok' => self::date_parseable($f['date'])];
        $checks[] = ['code' => 'seller_inn', 'ok' => self::inn_valid($f['seller']['inn'] ?? '')];
        $checks[] = ['code' => 'buyer_inn', 'ok' => self::inn_valid($f['buyer']['inn'] ?? '')];

        $total = $f['total']; $vat = $f['vat'];
        $lines_sum = 0.0; $has_line_sum = FALSE;
        foreach ($f['lines'] as $ln) { if ($ln['sum'] !== NULL) { $lines_sum += (float) $ln['sum']; $has_line_sum = TRUE; } }
        $checks[] = ['code' => 'total_matches_lines', 'ok' => ($total === NULL || ! $has_line_sum) ? FALSE : abs((float) $total - $lines_sum) <= 0.01];
        $checks[] = ['code' => 'vat_range', 'ok' => ($vat === NULL || $total === NULL) ? FALSE : ($vat >= 0 && $vat <= (float) $total)];

        return $checks;
    }

    private static function date_parseable(string $date): bool
    {
        if ($date === '') return FALSE;
        $ts = strtotime($date);
        if ($ts !== FALSE) return TRUE;
        // DD.MM.YYYY
        if (preg_match('/\A(\d{2})\.(\d{2})\.(\d{4})\z/', $date, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
        }
        return FALSE;
    }

    public static function inn_valid(string $inn): bool
    {
        $inn = preg_replace('/\D+/', '', $inn);
        if ( ! preg_match('/\A(\d{10}|\d{12})\z/', $inn)) return FALSE;
        $d = array_map('intval', str_split($inn));
        if (count($d) === 10) {
            $c = [2,4,10,3,5,9,4,6,8];
            $s = 0; for ($i=0;$i<9;$i++) $s += $c[$i]*$d[$i];
            return ($s % 11 % 10) === $d[9];
        }
        $c11 = [7,2,4,10,3,5,9,4,6,8];
        $c12 = [3,7,2,4,10,3,5,9,4,6,8];
        $s11 = 0; for ($i=0;$i<10;$i++) $s11 += $c11[$i]*$d[$i];
        $n11 = $s11 % 11 % 10;
        $s12 = 0; for ($i=0;$i<11;$i++) $s12 += $c12[$i]*$d[$i];
        $n12 = $s12 % 11 % 10;
        return $n11 === $d[10] && $n12 === $d[11];
    }

    private function check_lease(int $id, int $attempt): array
    {
        $row = $this->one('SELECT job_id, file_id, file_sha256, status FROM prx_397_invoices WHERE job_id = '.$id.' AND expires_at > now()');
        if ( ! $row) throw new Prx397_Error('not_found', 404);
        $job = $this->queue->get($id);
        if ( ! $job || $job['channel'] !== self::CHANNEL || $job['status'] !== 'processing' || (int) $job['attempts'] !== $attempt)
            throw new Prx397_Error('lease_lost', 409);
        return $row;
    }

    private function decode_json($v): ?array
    {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, TRUE);
            return is_array($d) ? $d : NULL;
        }
        return NULL;
    }

    public function purge(): array
    {
        $expired = $this->db->query(Database::SELECT,
            'SELECT job_id, file_id FROM prx_397_invoices WHERE expires_at <= now() ORDER BY expires_at, job_id LIMIT 100', FALSE)->as_array();
        $report = ['purged' => 0, 'orphans' => 0, 'errors' => 0];
        foreach ($expired as $row) {
            $id = (int) $row['job_id'];
            $file_id = (int) $row['file_id'];
            try {
                if ($file_id > 0) Storage::delete_owned($file_id, self::MODULE);
                $this->db->query(Database::DELETE, 'DELETE FROM prx_397_invoices WHERE job_id = '.$id.' AND expires_at <= now()', FALSE);
                $report['purged']++;
            } catch (Throwable $e) {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx397 job_id=:id stage=purge error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            }
        }
        foreach (Storage::owned_ids_before(self::MODULE, 48) as $file_id) {
            if ($this->one('SELECT 1 FROM prx_397_invoices WHERE file_id = '.$file_id.' LIMIT 1')) continue;
            try { if (Storage::delete_owned($file_id, self::MODULE)) $report['orphans']++; }
            catch (Throwable $e) { $report['errors']++; }
        }
        return $report;
    }

    private function discard(int $file_id): void
    {
        try { if (Storage::delete_owned($file_id, self::MODULE)) return; } catch (Throwable $e) {}
        Log_Plain::event(Log::ERROR, 'prx397 stage=discard file_id=:id', [':id' => $file_id]);
    }

    private function one(string $sql): ?array
    {
        $row = $this->db->query(Database::SELECT, $sql, FALSE)->current();
        return $row ? (array) $row : NULL;
    }
}
