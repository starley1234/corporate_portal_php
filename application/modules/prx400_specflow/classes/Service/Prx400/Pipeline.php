<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Протокол воркера specflow.parse и обслуживание prx400 (канон prx397):
 *
 *   GET  /prx400_specflow/worker/<job_id>/file[/<n>] → read_pdf() (lease + sha256; n — файл спецификации)
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

    public function read_pdf(int $job_id, int $attempt, int $file_no = 1): string
    {
        $spec = $this->check_lease($job_id, $attempt);
        Model_Prx400_Spec::mark_running((int) $spec['id']);
        $bytes = Service_Prx400_Specs::pdf_bytes($spec, $file_no);
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
            $this->finish($job_id, $spec_id, static function () use ($spec_id, $spec, $code): void {
                Model_Prx400_Spec::fail($spec_id, $code);
                Service_Prx400_Notify::queue($spec_id, (int) $spec['owner_id'], 'failed');
            });
            Service_Prx400_Notify::publish($spec_id, 'failed', $code);
            Log_Plain::event(Log::INFO, 'prx400 spec=:s job_id=:j stage=failed code=:c', [':s' => $spec_id, ':j' => $job_id, ':c' => $code]);

            return ['status' => 'failed', 'error_code' => $code];
        }

        $rows_in = $body['rows'] ?? NULL;
        if ( ! is_array($rows_in) OR ! array_is_list($rows_in)) throw new Prx400_Error('invalid_result', 400);
        if (count($rows_in) > Service_Prx400_Limits::get('max_rows')) throw new Prx400_Error('result_too_large', 413);
        if ($rows_in === [])
        {
            $this->finish($job_id, $spec_id, static function () use ($spec_id, $spec): void {
                Model_Prx400_Spec::fail($spec_id, 'no_rows');
                Service_Prx400_Notify::queue($spec_id, (int) $spec['owner_id'], 'failed');
            });
            Service_Prx400_Notify::publish($spec_id, 'failed', 'no_rows');

            return ['status' => 'failed', 'error_code' => 'no_rows'];
        }

        $files = max(1, count(Model_Prx400_File::for_spec($spec_id)));
        $rows = [];
        foreach ($rows_in as $seq => $r)
        {
            if ( ! is_array($r)) throw new Prx400_Error('invalid_result', 400);
            $rows[] = self::clean_row($seq, $r, $files);
        }
        $rows = self::link_tree($rows);

        // Матчинг — до транзакции: блокировка записи не ждёт перебор НСИ.
        $matcher = new Service_Prx400_Matcher;
        $matcher->prime($rows);
        foreach ($rows as &$row)
        {
            $assessed = $matcher->assess($row);
            $row['match'] = $assessed['match'];
            $row['flags'] = $assessed['flags'];
            $row['status'] = $assessed['status'];
        }
        unset($row);
        $math = self::clean_math($body);
        $owner = (int) $spec['owner_id'];

        $outcome = $this->finish($job_id, $spec_id, static function () use ($spec_id, $owner, $rows, $body, $math): array {
            // Повторный разбор после проверки: решения оператора переносятся на
            // те же строки нового разбора, добавленные вручную строки остаются.
            $merged = self::merge_decisions($rows, Model_Prx400_Row::for_spec($spec_id));
            $rows = $merged['rows'];
            if ($merged['lost'] > 0) $math['warnings'] = array_values(array_unique(array_merge($math['warnings'], ['verified_rows_lost'])));

            $counts = array_count_values(array_map(static fn (array $r): string => ! empty($r['verified']) ? 'ok' : $r['status'], $rows))
                + ['ok' => 0, 'review' => 0, 'new' => 0, 'error' => 0];
            $issues = count($rows) - $counts['ok'];
            $summary = sprintf('Позиций: %d; совпало с НСИ или подтверждено: %d; на подтверждение: %d; новых: %d; с ошибками: %d.',
                count($rows), $counts['ok'], $counts['review'], $counts['new'], $counts['error']);
            if ($merged['inherited'] > 0) $summary .= sprintf(' Перенесено решений оператора: %d.', $merged['inherited']);
            $status = $issues > 0 ? 'review' : 'done';

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
            Service_Prx400_Notify::queue($spec_id, $owner, $status);

            return ['status' => $status, 'rows' => count($rows), 'issues' => $issues];
        });
        $matcher->record_hits();
        Service_Prx400_Notify::publish($spec_id, $outcome['status']);
        Log_Plain::event(Log::INFO, 'prx400 spec=:s job_id=:j stage=:st rows=:n issues=:i memory=:m', [
            ':s' => $spec_id, ':j' => $job_id, ':st' => $outcome['status'], ':n' => $outcome['rows'], ':i' => $outcome['issues'],
            ':m' => $matcher->memory_hits(),
        ]);

        return $outcome;
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
                $failed = Prx_Db::transaction(static function () use ($spec, $code): bool {
                    if (Model_Prx400_Spec::fail_in_flight((int) $spec['id'], (int) ($spec['job_id'] ?? 0), (string) $code) < 1) return FALSE;
                    Service_Prx400_Notify::queue((int) $spec['id'], (int) $spec['owner_id'], 'failed');
                    return TRUE;
                });
                if ($failed)
                {
                    $report['reconciled']++;
                    Service_Prx400_Notify::publish((int) $spec['id'], 'failed', (string) $code);
                }
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
                    $file_ids = Service_Prx400_Specs::file_ids($spec);
                    if ( ! Model_Prx400_Spec::delete_expired((int) $spec['id'], $days)) continue; // ожила — не трогаем
                    // Файл мог перейти к другой записи (перезагрузка) — удаляем только ничьи.
                    $orphaned = array_diff($file_ids, array_map('intval', Model_Prx400_Spec::referenced_files($file_ids)));
                    foreach ($orphaned as $file_id) Storage::delete_owned($file_id, Service_Prx400_Specs::MODULE);
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
        $report['deliveries_purged'] = Model_Prx400_Hook::purge(30);

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
    private function finish(int $job_id, int $spec_id, callable $write)
    {
        return Prx_Db::transaction(static function () use ($job_id, $spec_id, $write) {
            $spec = Model_Prx400_Spec::lock($spec_id);
            if ($spec === NULL OR (int) $spec['job_id'] !== $job_id) throw new Prx400_Error('lease_lost', 409);
            if ( ! in_array($spec['status'], ['queued', 'running'], TRUE)) throw new Prx400_Error('wrong_state', 409);
            return $write();
        });
    }

    /** Иерархическая позиция «1», «1.2», «1.2.3» (до 8 уровней). */
    public const POS_PATH_PATTERN = '/\A[0-9]{1,4}(?:\.[0-9]{1,4}){0,7}\z/';

    /** Строка воркера → строка таблицы: типы, длины, флаги (строчные — по Checks). */
    public static function clean_row(int $seq, array $r, int $files = 1): array
    {
        $num = static fn ($v): ?float => Service_Prx400_Checks::number($v) !== NULL && is_finite((float) $v) && abs((float) $v) < 1e12 ? (float) $v : NULL;
        $text = static fn ($v, int $max): string => is_string($v) || is_int($v) || is_float($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $pos = $r['pos'] ?? NULL;
        $path = is_string($r['pos_path'] ?? NULL) && preg_match(self::POS_PATH_PATTERN, $r['pos_path']) ? $r['pos_path'] : '';
        if ($path === '' AND is_int($pos) AND $pos >= 0 AND $pos <= 9999) $path = (string) $pos;
        if ($path !== '' AND ! is_int($pos)) $pos = (int) substr(strrchr('.'.$path, '.'), 1);
        $level = is_int($r['level'] ?? NULL) && $r['level'] >= 0 && $r['level'] <= 7 ? $r['level'] : NULL;
        $file_no = is_int($r['file_no'] ?? NULL) && $r['file_no'] >= 1 && $r['file_no'] <= $files ? $r['file_no'] : 1;
        $page = is_int($r['page'] ?? NULL) && $r['page'] >= 1 && $r['page'] <= 100000 ? $r['page'] : NULL;

        $flags = [];
        foreach (array_slice(is_array($r['flags'] ?? NULL) ? $r['flags'] : [], 0, 10) as $f)
        {
            $type = is_array($f) ? (string) ($f['type'] ?? '') : '';
            if ( ! in_array($type, self::FLAG_TYPES, TRUE)) continue;
            $clean = ['type' => $type];
            if (isset($f['expected']) AND $num($f['expected']) !== NULL) $clean['expected'] = $num($f['expected']);
            if (isset($f['after_pos']) AND (is_int($f['after_pos']) OR (is_string($f['after_pos']) AND preg_match(self::POS_PATH_PATTERN, $f['after_pos'])))) $clean['after_pos'] = $f['after_pos'];
            if (isset($f['unit']) AND is_string($f['unit'])) $clean['unit'] = mb_substr($f['unit'], 0, 16);
            $flags[] = $clean;
        }

        $row = [
            'seq' => $seq,
            'section' => $text($r['section'] ?? '', 255),
            'pos' => is_int($pos) && $pos >= 0 && $pos < 2147483647 ? $pos : NULL,
            'pos_path' => $path,
            'level' => $level ?? ($path !== '' ? substr_count($path, '.') : 0),
            'explicit_level' => $level !== NULL,
            'file_no' => $file_no,
            'page' => $page,
            'raw_name' => $text($r['raw_name'] ?? '', 4000),
            'code_doc' => $text($r['code_doc'] ?? '', 255),
            'qty' => $num($r['qty'] ?? NULL),
            'unit' => $text($r['unit'] ?? '', 16),
            'unit_price' => $num($r['unit_price'] ?? NULL),
            'total_price' => $num($r['total_price'] ?? NULL),
            'origin' => 'parsed',
        ];
        // Построчные флаги считает сервер (те же правила, что после правки
        // оператором); от воркера берётся только контекст документа — gap.
        $gaps = array_values(array_filter($flags, static fn (array $f): bool => $f['type'] === 'gap'));
        $row['flags'] = array_merge(Service_Prx400_Checks::row_flags($row), $gaps);

        return $row;
    }

    /**
     * Дерево BOM: parent_seq — индекс родителя в $rows. Иерархический номер
     * «1.2.3» → родитель «1.2» (ближайший выше в том же файле); без номера,
     * но с явным уровнем (колонка «Уровень», «..2») — ближайшая выше строка
     * уровнем меньше. Строка без родителя — корень (level 0).
     */
    public static function link_tree(array $rows): array
    {
        $paths = [];
        $stack = [];
        foreach ($rows as $i => &$row)
        {
            $file = (int) ($row['file_no'] ?? 1);
            $path = (string) ($row['pos_path'] ?? '');
            $parent = NULL;
            if (str_contains($path, '.'))
            {
                $parent = $paths[$file][substr($path, 0, (int) strrpos($path, '.'))] ?? NULL;
                $row['level'] = substr_count($path, '.');
            }
            elseif ( ! empty($row['explicit_level']))
            {
                for ($l = (int) $row['level'] - 1; $l >= 0 AND $parent === NULL; $l--) $parent = $stack[$file][$l] ?? NULL;
            }
            else
            {
                $row['level'] = 0;
            }
            $row['parent_seq'] = $parent;
            if ($path !== '') $paths[$file][$path] = $i;
            $level = (int) $row['level'];
            $stack[$file][$level] = $i;
            foreach (array_keys($stack[$file]) as $l) if ($l > $level) unset($stack[$file][$l]);
            unset($row['explicit_level']);
        }
        unset($row);

        return $rows;
    }

    /** Ключ строки для переноса решений между разборами. */
    public static function row_key(array $r): string
    {
        $qty = $r['qty'] !== NULL ? rtrim(rtrim(number_format((float) $r['qty'], 4, '.', ''), '0'), '.') : '';

        return Service_Prx400_Match::normalize((string) $r['raw_name']).'|'.Service_Prx400_Match::code_key((string) $r['code_doc'])
            .'|'.$qty.'|'.Service_Prx400_Match::unit_key((string) $r['unit']);
    }

    /**
     * Перенос решений оператора на новый разбор (повтор из review/done):
     *  - новая строка с тем же ключом (наименование, обозначение, кол-во, ед.),
     *    что у подтверждённой старой, наследует подтверждение и ручной код;
     *  - строки, добавленные человеком (origin manual), дописываются в конец;
     *  - подтверждённые строки, которых в новом разборе нет, — счётчик lost
     *    (предупреждение verified_rows_lost в карточке).
     *
     * @return array{rows: array, inherited: int, kept: int, lost: int}
     */
    public static function merge_decisions(array $rows, array $old): array
    {
        $decided = [];
        $manual = [];
        foreach ($old as $o)
        {
            if (($o['origin'] ?? 'parsed') === 'manual') { $manual[] = $o; continue; }
            if ( ! empty($o['verified']) OR ! empty($o['match']['manual'])) $decided[self::row_key($o)][] = $o;
        }
        $inherited = 0;
        foreach ($rows as &$row)
        {
            $key = self::row_key($row);
            if (empty($decided[$key])) continue;
            $o = array_shift($decided[$key]);
            if ( ! empty($o['match']['manual']))
            {
                $row['match'] = $o['match'];
                $row['flags'] = Service_Prx400_Checks::refresh($row, $row['match']);
                $row['status'] = Service_Prx400_Match::row_status($row['flags'], $row['match']);
            }
            if ( ! empty($o['verified']))
            {
                $row['verified'] = TRUE;
                $row['verified_by'] = $o['verified_by'] !== NULL ? (int) $o['verified_by'] : NULL;
                $row['verified_at'] = $o['verified_at'] ?? NULL;
            }
            $inherited++;
        }
        unset($row);
        $lost = array_sum(array_map('count', $decided));
        foreach ($manual as $o)
        {
            $o['parent_seq'] = NULL;
            $o['level'] = 0;
            $rows[] = $o;
        }

        return ['rows' => array_values($rows), 'inherited' => $inherited, 'kept' => count($manual), 'lost' => $lost];
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
            'via' => in_array($stats['via'] ?? NULL, ['ocr', 'vision', 'mixed'], TRUE) ? $stats['via'] : 'ocr', // mixed — файлы разными путями
            'pages_processed' => isset($stats['pages_processed']) && is_int($stats['pages_processed']) ? $stats['pages_processed'] : NULL,
        ];
    }
}
