<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Протокол воркера specflow.parse и обслуживание prx400 (канон prx397):
 *
 *   GET  /prx400_specflow/worker/<job_id>/file   → read_pdf()     (lease + sha256)
 *   POST /prx400_specflow/worker/<job_id>/result → store_result() (lease; позиции
 *        или {error_code} — терминальная ошибка разбора)
 *   bin/console prx400:purge                     → maintain()     (сверка с очередью,
 *        срок хранения, осиротевшие файлы)
 *
 * Аренда (lease): задача канала в статусе processing и номер попытки из
 * X-PRX-Worker-Attempt совпадает с attempts очереди. Отменённая/перехваченная
 * задача получает 409 lease_lost и ничего не пишет.
 */
final class Service_Prx400_Pipeline
{
    private const FLAG_TYPES = ['math_row', 'gap', 'unit_fractional'];
    private const WARNING_PATTERN = '/\A[a-z0-9_]{1,48}\z/';

    public function read_pdf(int $job_id, int $attempt): string
    {
        $spec = $this->check_lease($job_id, $attempt);
        Model_Prx400_Spec::mark_running((int) $spec['id']);
        $bytes = Service_Prx400_Specs::pdf_bytes($spec);
        if ($bytes === NULL)
        {
            Log_Plain::event(Log::WARNING, 'prx400 spec=:s job_id=:j stage=worker_file result=unavailable', [':s' => (int) $spec['id'], ':j' => $job_id]);
            throw new Prx400_Error('file_unavailable', 410);
        }

        return $bytes;
    }

    /** @return array{status: string, error_code?: string, rows?: int, issues?: int} */
    public function store_result(int $job_id, int $attempt, array $body): array
    {
        $spec = $this->check_lease($job_id, $attempt);
        $spec_id = (int) $spec['id'];

        $error_code = $body['error_code'] ?? NULL;
        if ($error_code !== NULL)
        {
            $code = is_string($error_code) && in_array($error_code, Service_Prx400_Specs::FAILED_CODES, TRUE) ? $error_code : 'job_failed';
            $this->finish($job_id, $spec_id, static function () use ($spec_id, $code): void {
                Model_Prx400_Spec::fail($spec_id, $code);
            });
            Log_Plain::event(Log::INFO, 'prx400 spec=:s job_id=:j stage=failed code=:c', [':s' => $spec_id, ':j' => $job_id, ':c' => $code]);

            return ['status' => 'failed', 'error_code' => $code];
        }

        $rows_in = $body['rows'] ?? NULL;
        if ( ! is_array($rows_in) OR ! array_is_list($rows_in)) throw new Prx400_Error('invalid_result', 400);
        if (count($rows_in) > Service_Prx400_Limits::get('max_rows')) throw new Prx400_Error('result_too_large', 413);
        if ($rows_in === [])
        {
            $this->finish($job_id, $spec_id, static fn () => Model_Prx400_Spec::fail($spec_id, 'no_rows'));

            return ['status' => 'failed', 'error_code' => 'no_rows'];
        }

        // Матчинг — до транзакции: блокировка записи не ждёт перебор НСИ.
        $source = Service_Prx400_Match::catalog_source();
        $rows = [];
        foreach ($rows_in as $seq => $r)
        {
            if ( ! is_array($r)) throw new Prx400_Error('invalid_result', 400);
            $row = self::clean_row($seq, $r);
            $query = Service_Prx400_Match::query($row['raw_name'], $row['code_doc']);
            $row['match'] = Service_Prx400_Match::apply($row['raw_name'], $row['code_doc'], $source($query))['match'];
            $row['status'] = Service_Prx400_Match::row_status($row['flags'], $row['match']);
            $rows[] = $row;
        }

        $counts = array_count_values(array_column($rows, 'status')) + ['ok' => 0, 'review' => 0, 'new' => 0, 'error' => 0];
        $issues = count($rows) - $counts['ok'];
        $math = self::clean_math($body);
        $summary = sprintf('Позиций: %d; совпало с НСИ: %d; на подтверждение: %d; новых: %d; с ошибками: %d.',
            count($rows), $counts['ok'], $counts['review'], $counts['new'], $counts['error']);
        $status = $issues > 0 ? 'review' : 'done';

        $this->finish($job_id, $spec_id, static function () use ($spec_id, $rows, $body, $math, $summary, $status): void {
            Model_Prx400_Row::replace_for_spec($spec_id, $rows);
            Model_Prx400_Spec::set_parse_result($spec_id, [
                'template_id' => (string) ($body['template_id'] ?? ''),
                'template_title' => (string) ($body['template_title'] ?? ''),
                'spec_number' => (string) ($body['spec_number'] ?? ''),
                'department' => (string) ($body['department'] ?? ''),
                'pages' => (int) ($body['pages'] ?? 0),
                'text_sha256' => (string) ($body['text_sha256'] ?? ''),
                'math' => $math,
                'summary' => trim($math['summary'].' '.$summary),
            ], $status);
        });
        Log_Plain::event(Log::INFO, 'prx400 spec=:s job_id=:j stage=:st rows=:n issues=:i', [
            ':s' => $spec_id, ':j' => $job_id, ':st' => $status, ':n' => count($rows), ':i' => $issues,
        ]);

        return ['status' => $status, 'rows' => count($rows), 'issues' => $issues];
    }

    /**
     * Обслуживание по расписанию (prx400:purge):
     *  1. сверка queued/running с очередью — запись failed с кодом (UI это
     *     только показывает, на GET не пишет);
     *  2. срок хранения (PRX400_RETENTION_DAYS > 0) для done/failed;
     *  3. файлы prx400 старше 48 ч без записи — сироты сбоев.
     *
     * @return array{reconciled: int, purged: int, orphans: int, errors: int}
     */
    public function maintain(): array
    {
        $report = ['reconciled' => 0, 'recovered' => 0, 'purged' => 0, 'orphans' => 0, 'errors' => 0];

        foreach (Model_Prx400_Spec::in_flight() as $spec)
        {
            [$status, $code] = Service_Prx400_Specs::effective($spec, Service_Prx400_Specs::job((int) ($spec['job_id'] ?? 0)));
            if ($status !== 'failed') continue;
            // Свежая запись без задачи — ещё в транзакции постановки, не трогаем.
            if ((int) ($spec['job_id'] ?? 0) <= 0 AND strtotime((string) $spec['updated_at']) > time() - 600) continue;
            try {
                // Воркер замолчал посреди разбора — сначала автоповтор (PRX400_CRASH_RETRIES),
                // и только когда он исчерпан, запись получает ошибку worker_lost.
                if ($code === Service_Prx120_JobQueue::STOP_WORKER_LOST
                    AND (new Service_Prx400_Specs)->recover((int) $spec['id'], (int) $spec['job_id']))
                {
                    $report['recovered']++;
                    continue;
                }
                if (Model_Prx400_Spec::fail_in_flight((int) $spec['id'], (int) ($spec['job_id'] ?? 0), (string) $code) > 0) $report['reconciled']++;
            } catch (Throwable $e) {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx400 spec=:s stage=reconcile error_type=:t', [':s' => (int) $spec['id'], ':t' => get_class($e)]);
            }
        }

        $days = Service_Prx400_Limits::get('retention_days');
        if ($days > 0)
        {
            foreach (Model_Prx400_Spec::expired($days) as $spec)
            {
                try {
                    if ((int) ($spec['file_id'] ?? 0) > 0) Storage::delete_owned((int) $spec['file_id'], Service_Prx400_Specs::MODULE);
                    Model_Prx400_Spec::delete((int) $spec['id']);
                    $report['purged']++;
                } catch (Throwable $e) {
                    $report['errors']++;
                    Log_Plain::event(Log::ERROR, 'prx400 spec=:s stage=purge error_type=:t', [':s' => (int) $spec['id'], ':t' => get_class($e)]);
                }
            }
        }

        $sweep = Storage::sweep_orphans(Service_Prx400_Specs::MODULE, [Model_Prx400_Spec::class, 'referenced_files']);
        $report['orphans'] += $sweep['orphans'];
        $report['errors'] += $sweep['errors'];

        return $report;
    }

    /** Аренда задачи + запись, которой она принадлежит сейчас (после повтора старая задача — чужая). */
    private function check_lease(int $job_id, int $attempt): array
    {
        $job = Service_Prx400_Specs::job($job_id);
        if ($job === NULL OR (string) $job['channel'] !== Service_Prx400_Specs::CHANNEL
            OR (string) $job['status'] !== 'processing' OR (int) $job['attempts'] !== $attempt)
        {
            throw new Prx400_Error('lease_lost', 409);
        }
        $spec = Model_Prx400_Spec::get_by_job($job_id);
        if ($spec === NULL) throw new Prx400_Error('lease_lost', 409);
        if ( ! in_array($spec['status'], ['queued', 'running'], TRUE)) throw new Prx400_Error('wrong_state', 409);

        return $spec;
    }

    /** Запись результата под блокировкой: задача всё ещё та же, статус ещё «в работе». */
    private function finish(int $job_id, int $spec_id, callable $write): void
    {
        Prx_Db::transaction(static function () use ($job_id, $spec_id, $write): void {
            $spec = Model_Prx400_Spec::lock($spec_id);
            if ($spec === NULL OR (int) $spec['job_id'] !== $job_id) throw new Prx400_Error('lease_lost', 409);
            if ( ! in_array($spec['status'], ['queued', 'running'], TRUE)) throw new Prx400_Error('wrong_state', 409);
            $write();
        });
    }

    /** Строка воркера → строка таблицы: типы, длины, флаги (строчные — по Checks). */
    public static function clean_row(int $seq, array $r): array
    {
        $num = static fn ($v): ?float => Service_Prx400_Checks::number($v) !== NULL && is_finite((float) $v) && abs((float) $v) < 1e12 ? (float) $v : NULL;
        $text = static fn ($v, int $max): string => is_string($v) || is_int($v) || is_float($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $pos = $r['pos'] ?? NULL;

        $flags = [];
        foreach (array_slice(is_array($r['flags'] ?? NULL) ? $r['flags'] : [], 0, 10) as $f)
        {
            $type = is_array($f) ? (string) ($f['type'] ?? '') : '';
            if ( ! in_array($type, self::FLAG_TYPES, TRUE)) continue;
            $clean = ['type' => $type];
            if (isset($f['expected']) AND $num($f['expected']) !== NULL) $clean['expected'] = $num($f['expected']);
            if (isset($f['after_pos']) AND is_int($f['after_pos'])) $clean['after_pos'] = $f['after_pos'];
            if (isset($f['unit']) AND is_string($f['unit'])) $clean['unit'] = mb_substr($f['unit'], 0, 16);
            $flags[] = $clean;
        }

        $row = [
            'seq' => $seq,
            'section' => $text($r['section'] ?? '', 255),
            'pos' => is_int($pos) && $pos >= 0 && $pos < 2147483647 ? $pos : NULL,
            'raw_name' => $text($r['raw_name'] ?? '', 4000),
            'code_doc' => $text($r['code_doc'] ?? '', 255),
            'qty' => $num($r['qty'] ?? NULL),
            'unit' => $text($r['unit'] ?? '', 16),
            'unit_price' => $num($r['unit_price'] ?? NULL),
            'total_price' => $num($r['total_price'] ?? NULL),
        ];
        // Построчные флаги считает сервер (те же правила, что после правки
        // оператором); от воркера берётся только контекст документа — gap.
        $gaps = array_values(array_filter($flags, static fn (array $f): bool => $f['type'] === 'gap'));
        $row['flags'] = array_merge(Service_Prx400_Checks::row_flags($row), $gaps);

        return $row;
    }

    /** Математический отчёт + предупреждения разбора (усечение vision, пустые страницы). */
    public static function clean_math(array $body): array
    {
        $math = is_array($body['math'] ?? NULL) ? $body['math'] : [];
        $totals = is_array($math['totals'] ?? NULL) ? $math['totals'] : [];
        $num = static fn ($v): ?float => Service_Prx400_Checks::number($v);
        $warnings = [];
        foreach (array_slice(is_array($body['warnings'] ?? NULL) ? $body['warnings'] : [], 0, 50) as $w)
        {
            if (is_string($w) AND preg_match(self::WARNING_PATTERN, $w)) $warnings[] = $w;
        }
        $stats = is_array($body['stats'] ?? NULL) ? $body['stats'] : [];

        return [
            'row_errors' => array_slice(array_values(array_filter(is_array($math['row_errors'] ?? NULL) ? $math['row_errors'] : [], 'is_array')), 0, 500),
            'gaps' => array_slice(array_values(array_filter(is_array($math['gaps'] ?? NULL) ? $math['gaps'] : [], 'is_array')), 0, 500),
            'totals' => [
                'declared' => $num($totals['declared'] ?? NULL),
                'computed' => $num($totals['computed'] ?? NULL),
                'ok' => is_bool($totals['ok'] ?? NULL) ? $totals['ok'] : NULL,
            ],
            'summary' => mb_substr(trim((string) ($math['summary'] ?? '')), 0, 500),
            'warnings' => array_values(array_unique($warnings)),
            'via' => in_array($stats['via'] ?? NULL, ['ocr', 'vision'], TRUE) ? $stats['via'] : 'ocr',
            'pages_processed' => isset($stats['pages_processed']) && is_int($stats['pages_processed']) ? $stats['pages_processed'] : NULL,
        ];
    }
}
