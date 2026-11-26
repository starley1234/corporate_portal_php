<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сценарии пакета договоров: создать, запустить пайплайн, переставить ДС,
 * повторить файл, удалить. Права — здесь (deny by default); SQL — в моделях;
 * HTTP/CSRF — в контроллере.
 */
class Service_Prx399_Contracts {

    /** @var array Класс ошибки, который контроллер показывает как flash. */
    const ERROR = 'Prx399_Error';

    private $_limits;

    public function __construct()
    {
        $this->_limits = Kohana::$config->load('prx399_docmerge');
    }

    public function limits()
    {
        return $this->_limits;
    }

    // ---------------------------------------------------------------- list

    public function contracts()
    {
        return Model_Prx399_Contract::list();
    }

    public function contract($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx399.view');
        $contract = Model_Prx399_Contract::get($id);
        if ( ! $contract)
        {
            throw new Prx399_Error('contract_not_found', 404);
        }

        return [
            'contract' => $contract,
            'files' => Model_Prx399_File::for_contract($id),
            'deltas' => Model_Prx399_Delta::for_contract($id),
            'risks' => Model_Prx399_Risk::for_contract($id),
        ];
    }

    // -------------------------------------------------------------- create

    /**
     * Создать пакет: один базовый договор + цепочка ДС (порядок = порядок
     * загрузки, потом можно переставить). Файлы только PDF — сканы и PDF с
     * текстовым слоем (DOCX/повторная заливка — планируемое расширение).
     */
    public function create($actor, array $post, array $files)
    {
        ($actor ?? Prx_Actor::guest())->require('prx399.run');

        $focus = (string) ($post['focus'] ?? 'balanced');
        if ( ! isset($this->_limits['focus'][$focus]))
        {
            throw new Prx399_Error('bad_focus', 400);
        }

        $policy = mb_substr(trim((string) ($post['policy'] ?? '')), 0, (int) $this->_limits['max_policy']);
        $policy_json = json_encode(['policy' => $policy], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ds = isset($files['ds']) ? $this->_normalize_uploads($files['ds']) : [];
        $base = $this->_normalize_uploads(isset($files['base']) ? $files['base'] : []);
        if (count($base) !== 1)
        {
            throw new Prx399_Error('base_required', 400);
        }

        // Название не заполнено — определяем сами: имя базового PDF (без
        // расширения), иначе «Пакет от …». Пользовательский ввод всё равно
        // ограничен max_title.
        $title = trim((string) ($post['title'] ?? ''));
        if ($title === '')
        {
            $title = (string) pathinfo($base[0]['name'], PATHINFO_FILENAME);
            $title = trim(preg_replace('/[\x00-\x1f]/u', ' ', $title));
            if ($title === '' OR $title === '_')
            {
                $title = 'Пакет от '.date('d.m.Y H:i');
            }
        }
        if (mb_strlen($title) > (int) $this->_limits['max_title'])
        {
            throw new Prx399_Error('title_too_long', 400);
        }
        if (count($base) + count($ds) > (int) $this->_limits['max_files'])
        {
            throw new Prx399_Error('too_many_files', 400);
        }

        foreach (array_merge($base, $ds) as $upload)
        {
            $this->_assert_pdf($upload);
        }

        // absolute(), а не relocate_legacy(): относительный путь оставался
        // относительным и mkdir шёл от CWD процесса (на FPM — public_html,
        // куда писать нельзя) — «Хранилище недоступно» при рабочем storage/.
        // prx390 решает это тем же absolute() от корня проекта.
        $storage = rtrim(Storage_Path::absolute(getenv('PRX399_STORAGE_DIR') ?: 'storage/docmerge'), '/');

        return Prx_Db::transaction(function () use ($actor, $title, $focus, $policy_json, $base, $ds, $storage)
        {
            $contract = Model_Prx399_Contract::insert([
                'title' => $title,
                'focus' => $focus,
                'policy' => $policy_json,
                'created_by' => $actor->id(),
            ]);

            foreach ([['base', $base, 0], ['ds', $ds, 1]] as $kind_group)
            {
                [$kind, $uploads, $first] = $kind_group;
                foreach ($uploads as $i => $upload)
                {
                    $path = $this->_store($storage, $contract['id'], $kind, $i, $upload);
                    Model_Prx399_File::insert([
                        'contract_id' => $contract['id'],
                        'kind' => $kind,
                        'ordinal' => $kind === 'ds' ? $first + $i : 0,
                        'original_name' => $upload['name'],
                        'storage_path' => $path,
                    ]);
                }
            }

            return $contract;
        });
    }

    // -------------------------------------------------------------- launch

    /**
     * Запуск пайплайна: base-задача с приоритетом выше (её карточка сделки
     * обогащает промпты дельт), ДС — следом. Идемпотентность достигается
     * статусами: запуск возможен только из draft/failed.
     */
    public function launch($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx399.run');
        $contract = Model_Prx399_Contract::get($id);
        if ( ! $contract)
        {
            throw new Prx399_Error('contract_not_found', 404);
        }
        if ( ! in_array($contract['status'], ['draft', 'failed'], TRUE))
        {
            throw new Prx399_Error('wrong_state', 409);
        }

        $files = Model_Prx399_File::for_contract($id);
        foreach ($files as $file)
        {
            $job_id = Service_Prx120_JobQueue::push('doc.merge.delta', [
                'contract_id' => (int) $id,
                'file_id' => (int) $file['id'],
            ], [
                'module' => 'prx399',
                'priority' => $file['kind'] === 'base' ? 30 : 40,
                'max_attempts' => 2,
                'timeout_sec' => 1800,
                'created_by' => $actor->id(),
            ]);
            Model_Prx399_File::set_job($file['id'], $job_id);
            Model_Prx399_File::set_status($file['id'], 'queued');
        }

        Model_Prx399_Risk::delete_for_contract($id);
        Model_Prx399_Contract::set_status($id, 'parsing', NULL);
    }

    // ------------------------------------------------------------- reorder

    /** Переставить ДС: swap ординалов внутри одной пары (хронология цепочки). */
    public function move_ds($actor, $file_id, $direction)
    {
        ($actor ?? Prx_Actor::guest())->require('prx399.run');
        $file = Model_Prx399_File::get($file_id);
        if ( ! $file OR $file['kind'] !== 'ds')
        {
            throw new Prx399_Error('not_found', 404);
        }
        $contract = Model_Prx399_Contract::get($file['contract_id']);
        if ( ! in_array($contract['status'], ['draft', 'failed'], TRUE))
        {
            throw new Prx399_Error('wrong_state', 409);
        }

        $siblings = array_values(array_filter(
            Model_Prx399_File::for_contract($file['contract_id']),
            static fn ($f) => $f['kind'] === 'ds'));
        $index = NULL;
        foreach ($siblings as $i => $sibling)
        {
            if ((int) $sibling['id'] === (int) $file_id)
            {
                $index = $i;
            }
        }
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($index === NULL OR ! isset($siblings[$swap]))
        {
            return; // крайний элемент — переставлять некуда, не ошибка
        }

        Prx_Db::transaction(function () use ($siblings, $index, $swap)
        {
            $this->_set_ordinal($siblings[$index]['id'], (int) $siblings[$swap]['ordinal']);
            $this->_set_ordinal($siblings[$swap]['id'], (int) $siblings[$index]['ordinal']);
        });
    }

    private function _set_ordinal($file_id, $ordinal)
    {
        return Prx_Db::execute(
            'UPDATE prx_399_files SET ordinal = :o WHERE id = :id',
            [':id' => (int) $file_id, ':o' => (int) $ordinal]);
    }

    // --------------------------------------------------------------- retry

    /** Повтор файла после сбоя воркера; дельты этого файла перезаписываются. */
    public function retry_file($actor, $file_id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx399.run');
        $file = Model_Prx399_File::get($file_id);
        if ( ! $file)
        {
            throw new Prx399_Error('not_found', 404);
        }
        $contract = Model_Prx399_Contract::get($file['contract_id']);
        if ( ! in_array($contract['status'], ['failed', 'parsing'], TRUE))
        {
            throw new Prx399_Error('wrong_state', 409);
        }

        $job_id = Service_Prx120_JobQueue::push('doc.merge.delta', [
            'contract_id' => (int) $file['contract_id'],
            'file_id' => (int) $file_id,
        ], [
            'module' => 'prx399',
            'priority' => $file['kind'] === 'base' ? 30 : 40,
            'max_attempts' => 2,
            'timeout_sec' => 1800,
            'created_by' => $actor->id(),
        ]);
        Model_Prx399_File::set_job($file_id, $job_id);
        Model_Prx399_File::set_status($file_id, 'queued');
        Model_Prx399_Contract::set_status($file['contract_id'], 'parsing', NULL);
    }

    public function delete($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx399.run');
        $contract = Model_Prx399_Contract::get($id);
        if ( ! $contract)
        {
            throw new Prx399_Error('contract_not_found', 404);
        }

        // Каскад очистит files/deltas/risks; файлы на диске убираем вручную.
        Prx_Db::transaction(function () use ($id)
        {
            foreach (Model_Prx399_File::for_contract($id) as $file)
            {
                $raw = (string) $file['storage_path'];
                if ($raw === '')
                {
                    continue;
                }
                $path = Storage_Path::absolute($raw);
                if (is_file($path))
                {
                    @unlink($path);
                }
            }
            Model_Prx399_Contract::delete($id);
        });
    }

    // ------------------------------------------------------------ internal

    private function _normalize_uploads($raw)
    {
        if (empty($raw['name']) OR is_string($raw['name']))
        {
            return empty($raw['name']) ? [] : [$raw];
        }

        $out = [];
        foreach ($raw['name'] as $i => $name)
        {
            if ((int) $raw['error'][$i] === UPLOAD_ERR_NO_FILE)
            {
                continue;
            }
            $out[] = [
                'name' => (string) $name,
                'type' => (string) $raw['type'][$i],
                'tmp_name' => (string) $raw['tmp_name'][$i],
                'error' => (int) $raw['error'][$i],
                'size' => (int) $raw['size'][$i],
            ];
        }

        return $out;
    }

    private function _assert_pdf(array $upload)
    {
        if ($upload['error'] !== UPLOAD_ERR_OK)
        {
            throw new Prx399_Error('upload_error', 400);
        }
        if ( ! is_uploaded_file($upload['tmp_name']))
        {
            throw new Prx399_Error('upload_error', 400);
        }
        if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf')
        {
            throw new Prx399_Error('pdf_required', 400);
        }
        // Размер сверяется воркером ещё раз при скачивании (limits.py) —
        // здесь достаточно грубой отсечки, чтобы не тянуть гиганты в tmp.
        if ($upload['size'] > 200 * 1024 * 1024)
        {
            throw new Prx399_Error('file_too_large', 413);
        }
    }

    private function _store($storage, $contract_id, $kind, $index, array $upload)
    {
        // @ обязателен: Kohana превращает PHP-warning файловых операций в
        // ErrorException (на проде это выглядело «Сервис недоступен» вместо
        // честной ошибки). Паттерн — как в prx212/prx390: подавить предупреждение,
        // решить по факту состояния и бросить чистую Prx399_Error.
        $dir = rtrim($storage, '/').'/'.(int) $contract_id;
        if ( ! is_dir($dir) AND ! @mkdir($dir, 0770, TRUE) AND ! is_dir($dir))
        {
            throw new Prx399_Error('storage_unavailable', 503);
        }
        $ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $name = $kind.($kind === 'ds' ? '-'.($index + 1) : '').'-'.bin2hex(random_bytes(6)).'.'.$ext;
        $target = $dir.'/'.$name;

        if ( ! @move_uploaded_file($upload['tmp_name'], $target))
        {
            throw new Prx399_Error('storage_unavailable', 503);
        }

        // В БД — путь относительно корня проекта (переносимость между машинами).
        return 'storage/docmerge/'.(int) $contract_id.'/'.$name;
    }
}
