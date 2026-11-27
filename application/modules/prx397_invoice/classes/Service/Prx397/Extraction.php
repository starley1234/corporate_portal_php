<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx397_Extraction
{
    const CHANNEL = 'invoice.extract';
    const MODULE = 'prx397';
    const SCOPE = 'prx397.extract';
    /**
     * Терминальные коды: задание дальше не станет успешным. Нужны, чтобы
     * error_code() не выдавал произвольную строку из очереди как код ошибки.
     */
    const FAILED_CODES = [
        'invalid_pdf', 'encrypted_pdf', 'page_limit', 'result_too_large', 'no_text',
        'text_too_large_for_llm', 'ocr_config', 'ocr_unavailable', 'ocr_auth', 'ocr_rejected',
        'ocr_invalid_response', 'ocr_incomplete', 'ocr_page_failed', 'file_unavailable',
        'worker_auth', 'worker_endpoint_unavailable', 'worker_config', 'lease_lost',
        'invalid_result', 'invalid_attempt', 'method_not_allowed', 'pdf_required',
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
            $idem = Prx_Idempotency::begin(self::SCOPE, (string) $owner, $key, $sha);
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
                // Правило prx120: без visitor_id задача не попадает в «Мои задачи».
                'visitor_id' => Visitor::uid(),
            ]);
            if ($job_id < 1) throw new RuntimeException('Отсутствует ID задачи');
            $ttl = Service_Prx397_Limits::get('result_ttl_hours');
            Prx_Db::execute('INSERT INTO prx_397_invoices '
                .'(job_id, owner_id, file_id, request_key, file_sha256, status, expires_at) VALUES ('
                .':job,'.(int) $owner.','.$file_id.',:key_hash,'
                .':sha,\'queued\',now() + (:ttl * interval \'1 hour\'))',
                [':job' => $job_id, ':key_hash' => $key_hash, ':sha' => $sha, ':ttl' => (int) $ttl]);
            Prx_Idempotency::finish((int) $idem['id'], ['job_id' => $job_id]);
        } catch (Throwable $e) {
            Prx_Idempotency::abort();
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
            $response['error'] = ['code' => $row['error_code'] ?: self::error_code($job)];
        }
        return $response;
    }

    private function public_status(string $db_status, ?array $job): string
    {
        if (in_array($db_status, ['done', 'review', 'failed'], TRUE)) return $db_status;
        if ( ! $job) return 'queued';
        // failed в очереди — финал (попытки исчерпаны или PermanentJobError):
        // показывать «queued» значит врать владельцу, что будет повтор.
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
            Prx_Db::execute(
                'UPDATE prx_397_invoices SET status = \'failed\', error_code = :code'
                .', updated_at = now() WHERE job_id = '.(int) $id,
                [':code' => $error_code]);
            return ['status' => 'failed', 'error_code' => $error_code];
        }

        $fields = $body['fields'] ?? NULL;
        $confidence = $body['confidence'] ?? NULL;
        $text_chars = $body['text_chars'] ?? NULL;
        if ( ! is_array($fields)) throw new Prx397_Error('invalid_result', 400);
        if ($confidence !== NULL && ( ! is_numeric($confidence) || $confidence < 0 || $confidence > 1))
            throw new Prx397_Error('invalid_result', 400);
        if (is_int($text_chars) && $text_chars > Service_Prx397_Limits::get('max_llm_chars')) {
            Prx_Db::execute("UPDATE prx_397_invoices SET status='failed', error_code='text_too_large_for_llm', updated_at=now() WHERE job_id=".(int)$id);
            return ['status' => 'failed', 'error_code' => 'text_too_large_for_llm'];
        }

        $normalized = self::normalize_fields($fields);
        $checks = self::run_checks($normalized);
        $ok = TRUE;
        foreach ($checks as $c) { if (empty($c['ok'])) { $ok = FALSE; break; } }
        $status = $ok ? 'done' : 'review';

        Prx_Db::execute(
            'UPDATE prx_397_invoices SET status = :status'
            .', fields = :fields::jsonb'
            .', checks = :checks::jsonb'
            .', confidence = '.($confidence === NULL ? 'NULL' : number_format((float) $confidence, 3, '.', ''))
            .', error_code = NULL, updated_at = now() WHERE job_id = '.$id,
            [':status' => $status, ':fields' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
             ':checks' => json_encode($checks, JSON_UNESCAPED_UNICODE)]);

        return ['status' => $status, 'checks' => $checks];
    }

    public static function normalize_fields(array $f): array
    {
        $str = static fn($v) => is_scalar($v) ? trim((string) $v) : '';
        $num = static function ($v) {
            if (is_int($v) || is_float($v)) return (float) $v;
            if ( ! is_string($v)) return NULL;
            // Суммы из OCR/LLM приходят с пробелами, знаком валюты и
            // разными разделителями: «1 000,00 ₽», «1,000.00», «1.000.000,00».
            $s = (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($v));
            $s = (string) preg_replace('/(?:₽|руб\.?|RUB|USD|EUR|RUR)/iu', '', $s);
            $s = (string) preg_replace('/\x{2212}/u', '-', $s);
            if ( ! preg_match('/\A-?[0-9.,]+\z/', $s)) return NULL;
            if (preg_match('/\A-?\d{1,3}(?:\.\d{3})+(?:,\d+)?\z/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
            elseif (preg_match('/\A-?\d{1,3}(?:,\d{3})+(?:\.\d+)?\z/', $s)) $s = str_replace(',', '', $s);
            else $s = str_replace(',', '.', $s);
            return preg_match('/\A-?\d+(?:\.\d+)?\z/', $s) ? (float) $s : NULL;
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
        // Кросс-проверки «не применимо — не ошибка»: счёт без табличной части
        // или без НДС не должен уходить в ручную проверку только из-за этого
        // (обязательные поля проверяются отдельно в required_*).
        $checks[] = ['code' => 'total_matches_lines', 'ok' => ! $has_line_sum ? TRUE : ($total !== NULL && abs((float) $total - $lines_sum) <= 0.01)];
        $checks[] = ['code' => 'vat_range', 'ok' => $vat === NULL ? TRUE : ($total !== NULL && $vat >= 0 && $vat <= (float) $total)];

        return $checks;
    }

    private static function date_parseable(string $date): bool
    {
        $date = trim($date);
        if ($date === '') return FALSE;
        // Сначала строгие форматы: strtotime нормализует 31.02.2024 в 2 марта,
        // поэтому невозможная дата не должна проходить как валидная.
        if (preg_match('/\A(\d{2})\.(\d{2})\.(\d{4})\z/', $date, $m))
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $m))
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        return strtotime($date) !== FALSE;
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
        $expired = Prx_Db::select(
            'SELECT job_id, file_id FROM prx_397_invoices WHERE expires_at <= now() ORDER BY expires_at, job_id LIMIT 100');
        $report = ['purged' => 0, 'orphans' => 0, 'errors' => 0];
        foreach ($expired as $row) {
            $id = (int) $row['job_id'];
            $file_id = (int) $row['file_id'];
            // Гонка TTL и очереди (прод 25.11.2026: file_unavailable): если
            // задача ещё ждёт/выполняется, файл ей нужен — партию пропускаем,
            // purge доберёт её после завершения задачи.
            $job = $this->queue->get($id);
            if ($job !== NULL && in_array((string) $job['status'], ['pending', 'processing'], TRUE)) {
                continue;
            }
            try {
                if ($file_id > 0) Storage::delete_owned($file_id, self::MODULE);
                Prx_Db::execute('DELETE FROM prx_397_invoices WHERE job_id = '.$id.' AND expires_at <= now()');
                $report['purged']++;
            } catch (Throwable $e) {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx397 job_id=:id stage=purge error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            }
        }
        $sweep = Storage::sweep_orphans(self::MODULE, static fn (array $ids): array => array_column(
            Prx_Db::select('SELECT file_id FROM prx_397_invoices WHERE file_id IN ('.implode(', ', array_map('intval', $ids)).')'), 'file_id'));
        $report['orphans'] += $sweep['orphans'];
        $report['errors'] += $sweep['errors'];
        return $report;
    }

    private function discard(int $file_id): void
    {
        try { if (Storage::delete_owned($file_id, self::MODULE)) return; } catch (Throwable $e) {}
        Log_Plain::event(Log::ERROR, 'prx397 stage=discard file_id=:id', [':id' => $file_id]);
    }

    private function one(string $sql, array $params = []): ?array
    {
        return Prx_Db::one($sql, $params);
    }
}
