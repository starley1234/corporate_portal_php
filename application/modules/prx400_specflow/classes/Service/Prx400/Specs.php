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

    protected function _storage(): string
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

        $storage = $this->_storage();
        $sha = hash_file('sha256', $upload['tmp_name']);
        $name = mb_substr(trim((string) $upload['name']), 0, 255);

        $spec = Prx_Db::transaction(function () use ($actor, $storage, $sha, $name)
        {
            $spec = Model_Prx400_Spec::insert([
                'owner_id' => $actor->id(),
                'original_name' => $name,
                'storage_path' => '', // заполним после mkdir
                'file_sha256' => $sha,
            ]);
            $dir = rtrim($storage, '/').'/'.(int) $spec['id'];
            if ( ! is_dir($dir) AND ! @mkdir($dir, 0770, TRUE) AND ! is_dir($dir))
            {
                throw new Prx400_Error('storage_unavailable', 503);
            }
            $file = $dir.'/spec-'.bin2hex(random_bytes(6)).'.pdf';
            if ( ! @move_uploaded_file($_FILES['pdf']['tmp_name'], $file))
            {
                throw new Prx400_Error('storage_unavailable', 503);
            }
            Prx_Db::execute(
                'UPDATE prx_400_specs SET storage_path = :p WHERE id = :id',
                [':id' => (int) $spec['id'], ':p' => $file]);

            return Model_Prx400_Spec::get($spec['id']);
        });

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
        $job_id = Service_Prx120_JobQueue::push('specflow.parse', [
            'spec_id' => (int) $spec_id,
            'templates' => Service_Prx400_Templates::for_payload(),
        ], [
            'module' => 'prx400',
            'priority' => 40,
            'max_attempts' => 2,
            'timeout_sec' => 1800,
            'created_by' => (int) $actor_id,
        ]);
        Model_Prx400_Spec::set_job($spec_id, $job_id);
    }

    /** Карточка: спецификация + позиции (+ задача для индикации). */
    public function spec($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.view');
        $spec = Model_Prx400_Spec::get($id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }

        $job = NULL;
        if ((int) ($spec['job_id'] ?? 0) > 0 AND class_exists('Service_Prx120_JobQueue'))
        {
            try { $job = Service_Prx120_JobQueue::instance()->get((int) $spec['job_id']); }
            catch (Throwable $e) { /* индикация не должна ломать карточку */ }
        }

        return [
            'spec' => $spec,
            'rows' => Model_Prx400_Row::for_spec($id),
            'catalog_count' => Model_Prx400_Catalog::count(),
            'job' => $job,
            'math' => json_decode((string) ($spec['math'] ?? 'null'), TRUE),
        ];
    }

    /** Исходный PDF для воркера и для карточки. */
    public function read_pdf($spec_id)
    {
        $spec = Model_Prx400_Spec::get($spec_id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }
        $path = Storage_Path::absolute((string) $spec['storage_path']);
        if ( ! is_file($path))
        {
            throw new Prx400_Error('file_unavailable', 404);
        }

        return (string) file_get_contents($path);
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
