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

        $files = Model_Prx399_File::for_contract($id);
        $this->_reconcile_files($id, $contract, $files);
        $contract = Model_Prx399_Contract::get($id);
        $this->_reconcile_pipeline($id, $contract, $files);
        $contract = Model_Prx399_Contract::get($id);

        $jobs = [];
        if (class_exists('Service_Prx120_JobQueue'))
        {
            try
            {
                foreach (Service_Prx120_JobQueue::instance()->payload_jobs('prx399', 'contract_id', (int) $id, 20) as $job)
                {
                    $payload = is_array($job['payload'] ?? NULL) ? $job['payload']
                        : json_decode((string) ($job['payload'] ?? ''), TRUE);
                    $file_id = (int) ($payload['file_id'] ?? 0);
                    if ($file_id > 0 AND ! isset($jobs[$file_id]))
                    {
                        $jobs[$file_id] = $job; // первый = последний по времени
                    }
                }
            }
            catch (Throwable $e) { /* индикация не должна ломать карточку */ }
        }

        return [
            'contract' => $contract,
            'files' => $files,
            'deltas' => Model_Prx399_Delta::for_contract($id),
            'risks' => Model_Prx399_Risk::for_contract($id),
            'jobs' => $jobs,
        ];
    }

    /**
     * Самолечение зомби-файлов: launch() не ставит callback, поэтому файл,
     * чья задача очереди пала НАВСЕГДА (attempts исчерпаны), оставался
     * «В очереди» без кнопки «повторить». Файл в queued/working сверяем
     * с его задачей (job_id, публичный API владельца очереди): задача
     * failed/cancelled → файл failed (появляются честный статус и «повторить»).
     * Если из-за этого в пакете не осталось рабочих файлов — пакет failed
     * («Запустить пайплайн» снова доступен).
     */
    private function _reconcile_files($id, $contract, array &$files)
    {
        if ( ! class_exists('Service_Prx120_JobQueue'))
        {
            return;
        }

        $queue = NULL;
        $changed = FALSE;
        $pending = FALSE;

        foreach ($files as $i => $file)
        {
            if ( ! in_array($file['status'], ['queued', 'working'], TRUE))
            {
                continue;
            }
            if ((int) ($file['job_id'] ?? 0) <= 0)
            {
                $pending = TRUE; // задача ещё не записана — считать рабочей
                continue;
            }
            try
            {
                $queue = $queue ?: Service_Prx120_JobQueue::instance();
                $job = $queue->get((int) $file['job_id']);
            }
            catch (Throwable $e)
            {
                $pending = TRUE;
                continue;
            }
            if ($job === NULL)
            {
                // Задача удалена из очереди (чистка/админ) или не была создана:
                // ждать бессмысленно — файл-зомби.
                Model_Prx399_File::set_status((int) $file['id'], 'failed',
                    'Задача очереди не найдена (удалена или не была создана) — запустите файл повторно');
                $files[$i]['status'] = 'failed';
                $changed = TRUE;
                continue;
            }
            if ( ! in_array($job['status'], ['failed', 'cancelled', 'done'], TRUE))
            {
                $pending = TRUE; // задача жива (pending/processing) — ждать
                continue;
            }

            $error = trim((string) ($job['error'] ?? ''));
            if ($job['status'] === 'done')
            {
                // Воркер вернул задачу как выполненную, но результат файла
                // не был записан (сбой POST результата) — ждать нечего.
                Model_Prx399_File::set_status((int) $file['id'], 'failed',
                    'Задача завершилась, но результат файла не был записан — запустите файл повторно');
            }
            else
            {
                Model_Prx399_File::set_status((int) $file['id'], 'failed',
                    'Задача очереди завершилась ошибкой'.($error !== '' ? ': '.mb_substr($error, 0, 200) : ''));
            }
            $files[$i]['status'] = 'failed';
            $changed = TRUE;
        }

        if ($changed AND ! $pending AND (string) $contract['status'] === 'parsing')
        {
            $names = implode(', ', array_map(static fn ($f) => (string) $f['original_name'], $files));
            Model_Prx399_Contract::set_status((int) $id, 'failed', 'Не разобраны файлы: '.$names);
        }
    }

    /**
     * Самолечение уровня пакета: «Пайплайн идёт» не должен гореть, когда
     * в очереди нет живой задачи. parsing без pending-файлов → либо честный
     * failed (есть сбойные), либо дозапуск потерянной сборки; auditing со
     * скончавшейся задачей аудита → failed («Пересобрать» снова доступен).
     */
    private function _reconcile_pipeline($id, $contract, array $files)
    {
        if ( ! class_exists('Service_Prx120_JobQueue'))
        {
            return;
        }

        $status = (string) $contract['status'];

        if ($status === 'parsing')
        {
            $pending = array_filter($files, static fn ($f) => in_array($f['status'], ['queued', 'working'], TRUE));
            if ($pending)
            {
                return; // файлы ещё живы — разбирайтесь через _reconcile_files
            }
            $failed = array_filter($files, static fn ($f) => (string) $f['status'] === 'failed');
            if ($failed)
            {
                $names = implode(', ', array_map(static fn ($f) => (string) $f['original_name'], $files));
                Model_Prx399_Contract::set_status((int) $id, 'failed', 'Не разобраны файлы: '.$names);

                return;
            }
            // Все файлы разобраны, а сборка не случилась (результат последнего
            // файла потерян при сбое записи) — дозапускаем; идемпотентно.
            if ($files AND class_exists('Service_Prx399_Pipeline'))
            {
                try
                {
                    (new Service_Prx399_Pipeline)->ensure_built((int) $id);
                }
                catch (Throwable $e) { /* показ карточки не должен падать */ }
            }

            return;
        }

        if ($status === 'auditing')
        {
            // Summary записан → аудит ФАКТИЧЕСКИ завершён (audit_result сохранил
            // итог, но статус не доехал — баг set_audited до фикса): дофиксим
            // статус вместо ложного «не завершился».
            $has_summary = trim((string) ($contract['summary'] ?? '')) !== '';
            $audit_job = (int) ($contract['audit_job_id'] ?? 0);
            if ($audit_job <= 0)
            {
                if ($has_summary)
                {
                    Model_Prx399_Contract::set_status((int) $id, 'audited', NULL);

                    return;
                }
                // Результат аудита потерян (задача кончилась, итог не записан)
                Model_Prx399_Contract::set_status((int) $id, 'failed',
                    'Риск-аудит не завершился (результат не был записан) — пересоберите редакцию');

                return;
            }
            try
            {
                $job = Service_Prx120_JobQueue::instance()->get($audit_job);
            }
            catch (Throwable $e)
            {
                return; // очередь недоступна — не ломаем показ
            }
            if ($job !== NULL AND in_array($job['status'], ['failed', 'cancelled'], TRUE))
            {
                $error = trim((string) ($job['error'] ?? ''));
                Model_Prx399_Contract::set_status((int) $id, 'failed',
                    'Задача риск-аудита завершилась ошибкой'.($error !== '' ? ': '.mb_substr($error, 0, 200) : '').' — пересоберите редакцию');

                return;
            }
            if ($job === NULL OR $job['status'] === 'done')
            {
                if ($has_summary)
                {
                    Model_Prx399_Contract::set_status((int) $id, 'audited', NULL);

                    return;
                }
                Model_Prx399_Contract::set_status((int) $id, 'failed',
                    'Риск-аудит не завершился (результат не был записан) — пересоберите редакцию');
            }
        }
        elseif ($status === 'failed' AND trim((string) ($contract['summary'] ?? '')) !== ''
            AND strpos((string) ($contract['error'] ?? ''), 'Риск-аудит не завершился') === 0)
        {
            // Пакеты, помеченные failed ложным сообщением до этого фикса, хотя
            // итог аудита записан (у них summary и llm-риски на месте): тихо
            // возвращаем в audited — пересборка не нужна.
            Model_Prx399_Contract::set_status((int) $id, 'audited', NULL);
        }
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
