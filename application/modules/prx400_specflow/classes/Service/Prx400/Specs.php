<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Жизненный цикл спецификации prx400: загрузка → задача specflow.parse →
 * приём результата воркера (позиции + математика) → матчинг с НСИ →
 * верификация оператором → экспорт JSON.
 */
class Service_Prx400_Specs {

    protected $_max_upload;
    protected $_max_rows;

    public function __construct()
    {
        $cfg = Kohana::$config->load('prx400_specflow');
        $this->_max_upload = (int) ($cfg->get('max_upload_bytes') ?? 20971520);
        $this->_max_rows = (int) ($cfg->get('max_rows') ?? 2000);
    }

    /**
     * Корень легаси-хранилища (до перехода на prx212): env-каталог или
     * storage/specflow в корне проекта. Новые загрузки этот путь не пишут;
     * используется только resolve_pdf при перепривязке старых записей.
     */
    protected static function storage_dir(): string
    {
        $dir = (string) (Kohana::$config->load('prx400_specflow')->get('storage_dir') ?: 'storage/specflow');

        return rtrim(Storage_Path::absolute(getenv('PRX400_STORAGE_DIR') ?: $dir), '/');
    }

    /** Список спецификаций владельца (+ счётчики строк для ленты). */
    public function list($actor): array
    {
        $specs = Model_Prx400_Spec::for_owner($actor->id());
        $out = [];
        foreach ($specs as $s)
        {
            $s = (array) $s;
            $s['rows_total'] = 0;
            $s['rows_issue'] = 0;
            $out[$s['id']] = $s;
        }
        if ($out)
        {
            foreach (Prx_Db::select(
                'SELECT spec_id, count(*) AS total,
                        count(*) FILTER (WHERE status <> \'ok\') AS issue
                 FROM prx_400_rows WHERE spec_id IN ('.implode(',', array_map('intval', array_keys($out))).')
                 GROUP BY spec_id') as $agg)
            {
                $agg = (array) $agg;
                if (isset($out[(int) $agg['spec_id']]))
                {
                    $out[(int) $agg['spec_id']]['rows_total'] = (int) $agg['total'];
                    $out[(int) $agg['spec_id']]['rows_issue'] = (int) $agg['issue'];
                }
            }
        }

        return array_values($out);
    }

    /** Загрузить PDF спецификации и поставить задачу разбора. */
    public function create($actor, array $files)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');

        $upload = $files['pdf'] ?? NULL;
        if (empty($upload['name']))
        {
            throw new Prx400_Error('pdf_required', 400);
        }
        if ($upload['error'] !== UPLOAD_ERR_OK OR ! is_uploaded_file($upload['tmp_name']))
        {
            throw new Prx400_Error('upload_error', 400);
        }
        if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf')
        {
            throw new Prx400_Error('pdf_required', 400);
        }
        if ($upload['size'] > $this->_max_upload)
        {
            throw new Prx400_Error('file_too_large', 413);
        }

        $sha = hash_file('sha256', $upload['tmp_name']);
        $name = mb_substr(trim((string) $upload['name']), 0, 255);
        $content = (string) file_get_contents($upload['tmp_name']);
        if ($content === '')
        {
            throw new Prx400_Error('upload_error', 400);
        }

        // Хранение — через платформенное хранилище prx212 (фасад Storage):
        // путь и бекенд ведёт prx212 в своей БД, файл переживает выкатку
        // релиза и не зависит от каталога кода. Локальный каталог остался
        // только для ЛЕГАси-записей (resolve_pdf ниже).
        try
        {
            $file_id = Storage::put($content, $name, 'prx400', $actor->id(), FALSE, 'application/pdf');
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx400 storage put failed: :m', [':m' => $e->getMessage()]);
            throw new Prx400_Error('storage_unavailable', 503);
        }

        $spec = Model_Prx400_Spec::insert([
            'owner_id' => $actor->id(),
            'original_name' => $name,
            'storage_path' => 'prx212:'.$file_id, // маркер фасада, не путь ФС
            'file_sha256' => $sha,
        ]);

        $this->_push_job((int) $spec['id'], $actor->id());

        return $spec;
    }

    /** Повторный запуск разбора (упавшая/отменённая задача). */
    public function launch($actor, $spec_id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');
        $spec = Model_Prx400_Spec::get($spec_id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }
        if ( ! in_array($spec['status'], ['failed', 'queued', 'running'], TRUE))
        {
            throw new Prx400_Error('wrong_state', 409);
        }

        $this->_push_job((int) $spec_id, $actor->id());
        Model_Prx400_Spec::set_status((int) $spec_id, 'queued', NULL);
    }

    protected function _push_job($spec_id, $actor_id)
    {
        $templates = Service_Prx400_Templates::for_payload();
        if (empty($templates))
        {
            // Пустая библиотека = обречённая задача (воркер вернёт
            // template_missing). Честная ошибка сразу, на стороне сайта.
            throw new Prx400_Error('template_library_empty', 503);
        }

        $job_id = Service_Prx120_JobQueue::push('specflow.parse', [
            'spec_id' => (int) $spec_id,
            'templates' => $templates,
        ], [
            'module' => 'prx400',
            'priority' => 40,
            'max_attempts' => 2,
            'timeout_sec' => 1800,
            'created_by' => (int) $actor_id,
        ]);
        Model_Prx400_Spec::set_job($spec_id, $job_id);
    }

    /** Карточка: спецификация + позиции (+ задача и этап воркера для индикации). */
    public function spec($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.view');
        $spec = Model_Prx400_Spec::get($id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }

        $this->_reconcile((int) $id, $spec);
        $spec = Model_Prx400_Spec::get($id);

        // Последняя задача этого spec_id + её ЖИВОЙ этап (payload_jobs —
        // SQL у владельца очереди prx120; первое = последнее по времени).
        $job_stage = NULL;
        if (class_exists('Service_Prx120_JobQueue'))
        {
            try
            {
                foreach (Service_Prx120_JobQueue::instance()->payload_jobs('prx400', 'spec_id', (int) $id, 10) as $job)
                {
                    $job_stage = $job;
                    break;
                }
            }
            catch (Throwable $e) { /* индикация не должна ломать карточку */ }
        }

        return [
            'spec' => $spec,
            'rows' => Model_Prx400_Row::for_spec($id),
            'catalog_count' => Model_Prx400_Catalog::count(),
            'job_stage' => $job_stage,
            'math' => json_decode((string) ($spec['math'] ?? 'null'), TRUE),
            'file_ok' => $this->file_ok($id),
        ];
    }

    /**
     * Самолечение зомби-спецификаций: queued/running сверяем с задачей
     * (публичный JobQueue::get). failed/cancelled/missing → failed с причиной
     * и «Повторить разбор»; done без позиций → результат не был записан →
     * failed. Живая задача (pending/processing) — ждать.
     */
    private function _reconcile($id, array $spec)
    {
        if ( ! class_exists('Service_Prx120_JobQueue')
            OR ! in_array($spec['status'], ['queued', 'running'], TRUE))
        {
            return;
        }

        $job_id = (int) ($spec['job_id'] ?? 0);
        if ($job_id <= 0)
        {
            return; // задача ещё не записана — считать рабочей
        }

        try
        {
            $job = Service_Prx120_JobQueue::instance()->get($job_id);
        }
        catch (Throwable $e)
        {
            return; // очередь недоступна — не ломаем показ
        }

        if ($job === NULL)
        {
            Model_Prx400_Spec::set_status($id, 'failed',
                'Задача очереди не найдена (удалена или не была создана) — запустите разбор повторно');

            return;
        }
        if (in_array($job['status'], ['failed', 'cancelled'], TRUE))
        {
            $error = trim((string) ($job['error'] ?? ''));
            Model_Prx400_Spec::set_status($id, 'failed',
                'Задача очереди завершилась ошибкой'.($error !== '' ? ': '.mb_substr($error, 0, 200) : ''));

            return;
        }
        if ($job['status'] === 'done')
        {
            // Воркер вернул задачу как выполненную, а результат не записан
            // (сбой POST результата) — ждать нечего.
            Model_Prx400_Spec::set_status($id, 'failed',
                'Задача завершилась, но результат разбора не был записан — запустите разбор повторно');
        }
    }

    /** Исходный PDF для воркера и для карточки. */
    public function read_pdf($spec_id)
    {
        $spec = Model_Prx400_Spec::get($spec_id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }

        $bytes = $this->_pdf_bytes($spec);
        if ($bytes === NULL)
        {
            throw new Prx400_Error('file_unavailable', 404);
        }

        return $bytes;
    }

    /** Содержимое PDF: маркер prx212 → фасад; легаси-пути → resolve_pdf. */
    private function _pdf_bytes(array $spec): ?string
    {
        $raw = (string) $spec['storage_path'];

        if (strpos($raw, 'prx212:') === 0)
        {
            $file_id = (int) substr($raw, 7);

            return $file_id > 0 ? Storage::read_owned($file_id, 'prx400', $this->_max_upload) : NULL;
        }

        [$path, $healed] = self::resolve_pdf($raw);
        if ($healed !== NULL)
        {
            // Легаси-запись нашлась по перепривязке — чиним запись навсегда.
            Prx_Db::execute(
                'UPDATE prx_400_specs SET storage_path = :p WHERE id = :id',
                [':id' => (int) $spec['id'], ':p' => $healed]);
        }

        return $path !== NULL ? (string) file_get_contents($path) : NULL;
    }

    /**
     * Путь PDF из записи: относительный (канон, prx399) → абсолютизируем;
     * абсолютный (легаси до фикса) → пробуем как есть, затем перепривязываем
     * к ТЕКУЩЕМУ корню проекта по маркеру /storage/ (переживает перевыкатку).
     * [путь|NULL, healed_relative|NULL].
     */
    public static function resolve_pdf(string $raw): array
    {
        if ($raw === '')
        {
            return [NULL, NULL]; // путь не был записан при загрузке
        }

        if ($raw[0] !== '/' AND ! preg_match('~^[A-Za-z]:[\\\\/]~', $raw))
        {
            return [is_file(Storage_Path::absolute($raw)) ? Storage_Path::absolute($raw) : NULL, NULL];
        }

        if (is_file($raw))
        {
            return [$raw, self::to_relative($raw)];
        }

        // Файл по абсолютному пути не найден: перепривязка по СТРУКТУРЕ
        // нашего хранилища <storage>/<id>/spec-*.pdf к ТЕКУЩЕМУ корню
        // хранилища (env-каталог может лежать вне корня проекта — тогда
        // перепривязка к корню проекта не сработала бы).
        if (preg_match('~/([1-9][0-9]{0,8})/(spec-[^/\\\\]+)$~', str_replace('\\', '/', $raw), $m))
        {
            $candidate = rtrim(self::storage_dir(), '/').'/'.$m[1].'/'.$m[2];
            if (is_file($candidate))
            {
                return [$candidate, self::to_relative($candidate)];
            }
        }

        return [NULL, NULL];
    }

    /** Абсолютный путь → относительный от корня проекта (если он внутри корня). */
    public static function to_relative(string $absolute): string
    {
        $root = rtrim(str_replace('\\', '/', dirname(APPPATH)), '/');
        $normalized = rtrim(str_replace('\\', '/', $absolute), '/');
        if (strpos($normalized, $root.'/') === 0)
        {
            return ltrim(substr($normalized, strlen($root)), '/');
        }

        return $absolute; // нештатный каталог (env) — храним как есть
    }

    /** Жив ли исходный файл у спецификации (для честных подсказок карточки). */
    public function file_ok($spec_id): bool
    {
        $spec = Model_Prx400_Spec::get($spec_id);
        if ( ! $spec)
        {
            return FALSE;
        }
        $raw = (string) $spec['storage_path'];

        if (strpos($raw, 'prx212:') === 0)
        {
            return Storage::metadata((int) substr($raw, 7)) !== NULL;
        }
        [$path] = self::resolve_pdf($raw);

        return $path !== NULL;
    }

    /**
     * Результат воркера: позиции (+математика от воркера) → матчинг с НСИ →
     * статусы строк. Живёт только с queued/running.
     */
    public function result($spec_id, array $body)
    {
        $spec = Model_Prx400_Spec::get($spec_id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }
        if ( ! in_array($spec['status'], ['queued', 'running'], TRUE))
        {
            throw new Prx400_Error('wrong_state', 409);
        }

        $rows_in = $body['rows'] ?? [];
        if ( ! is_array($rows_in))
        {
            throw new Prx400_Error('invalid_rows', 400);
        }
        if (count($rows_in) > $this->_max_rows)
        {
            throw new Prx400_Error('result_too_large', 413);
        }

        $catalog = Model_Prx400_Catalog::all();
        $rows = [];
        foreach (array_values($rows_in) as $seq => $r)
        {
            if ( ! is_array($r)) continue;
            $clean = [
                'seq' => $seq,
                'section' => mb_substr(trim((string) ($r['section'] ?? '')), 0, 255),
                'pos' => isset($r['pos']) && $r['pos'] !== NULL ? (int) $r['pos'] : NULL,
                'raw_name' => mb_substr(trim((string) ($r['raw_name'] ?? '')), 0, 4000),
                'code_doc' => mb_substr(trim((string) ($r['code_doc'] ?? '')), 0, 255),
                'qty' => isset($r['qty']) && is_numeric($r['qty']) ? (float) $r['qty'] : NULL,
                'unit' => mb_substr(trim((string) ($r['unit'] ?? '')), 0, 16),
                'unit_price' => isset($r['unit_price']) && is_numeric($r['unit_price']) ? (float) $r['unit_price'] : NULL,
                'total_price' => isset($r['total_price']) && is_numeric($r['total_price']) ? (float) $r['total_price'] : NULL,
                'flags' => array_slice((array) ($r['flags'] ?? []), 0, 10),
            ];

            $m = Service_Prx400_Match::apply($clean['raw_name'], $clean['code_doc'], $catalog);
            $clean['match'] = $m['match'];

            // Статус строки: красный — арифметика/ед./нет в НСИ; жёлтый — на
            // подтверждение; зелёный — сошлось и найдено.
            $flags = array_column($clean['flags'], 'type');
            if (in_array('math_row', $flags, TRUE))
            {
                $clean['status'] = 'error';
            }
            elseif (in_array('unit_fractional', $flags, TRUE))
            {
                $clean['status'] = $m['status'] === 'ok' ? 'review' : $m['status'];
            }
            else
            {
                $clean['status'] = $m['status'];
            }

            $rows[] = $clean;
        }

        Model_Prx400_Row::replace_for_spec((int) $spec_id, $rows);

        $math = is_array($body['math'] ?? NULL) ? $body['math'] : [];
        $issues = count(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'ok'));
        $summary = sprintf('Позиций: %d; совпало с НСИ: %d; на подтверждение: %d; новых: %d; с ошибками: %d.',
            count($rows),
            count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'ok')),
            count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'review')),
            count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'new')),
            count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'error')));

        Model_Prx400_Spec::set_parse_result((int) $spec_id, [
            'template_id' => (string) ($body['template_id'] ?? ''),
            'template_title' => (string) ($body['template_title'] ?? ''),
            'spec_number' => (string) ($body['spec_number'] ?? ''),
            'department' => (string) ($body['department'] ?? ''),
            'pages' => (int) ($body['pages'] ?? 0),
            'text_sha256' => (string) ($body['text_sha256'] ?? ''),
            'math' => $math,
            'summary' => trim((string) ($math['summary'] ?? '').' '.$summary),
        ]);

        // Ревью нужен, если есть что подтверждать; идеальный прогон — сразу готов.
        Model_Prx400_Spec::set_status((int) $spec_id, $issues > 0 ? 'review' : 'done', NULL);

        return ['ok' => TRUE, 'rows' => count($rows), 'issues' => $issues];
    }

    /** Верификация строки: подтверждение сопоставления НСИ оператором. */
    public function verify_row($actor, $row_id, $manual_code = NULL)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');
        $row = Model_Prx400_Row::get($row_id);
        if ( ! $row)
        {
            throw new Prx400_Error('not_found', 404);
        }

        if ($manual_code !== NULL AND $manual_code !== '')
        {
            $item = Prx_Db::one(
                'SELECT id, code, name, unit, okei_code FROM prx_400_catalog WHERE code = :c',
                [':c' => (string) $manual_code]);
            if ( ! $item)
            {
                throw new Prx400_Error('catalog_code_not_found', 400);
            }
            $match = [
                'catalog_id' => (int) $item['id'], 'code' => (string) $item['code'],
                'name' => (string) $item['name'], 'unit' => (string) $item['unit'],
                'okei_code' => (string) ($item['okei_code'] ?? ''), 'score' => 1.0, 'manual' => TRUE,
            ];
            Model_Prx400_Row::set_manual_match((int) $row_id, $actor->id(), $match);

            return;
        }

        Model_Prx400_Row::set_verified((int) $row_id, $actor->id(), TRUE);
    }

    /** Завершение проверки оператором (HITL): review → done. */
    public function finish($actor, $spec_id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');
        $spec = Model_Prx400_Spec::get($spec_id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }
        if ( ! in_array($spec['status'], ['review'], TRUE))
        {
            throw new Prx400_Error('wrong_state', 409);
        }

        Model_Prx400_Spec::set_status((int) $spec_id, 'done', NULL);
    }
}
