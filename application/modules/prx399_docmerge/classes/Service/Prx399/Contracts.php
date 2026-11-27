<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сценарии пакета договоров: создать, запустить пайплайн, переставить ДС,
 * повторить файл, удалить. Права — здесь (deny by default); SQL — в моделях;
 * HTTP/CSRF — в контроллере.
 */
class Service_Prx399_Contracts {

    /** @var array Класс ошибки, который контроллер показывает как flash. */
    const ERROR = 'Prx399_Error';
    /** Префикс модуля в prx212 (Storage::). */
    const MODULE = 'prx399';

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
            // В сообщении — только сбойные файлы (раньше перечислялись все).
            $failed = array_filter($files, static fn ($f) => (string) $f['status'] === 'failed');
            $names = implode(', ', array_map(static fn ($f) => (string) $f['original_name'], $failed));
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
                $names = implode(', ', array_map(static fn ($f) => (string) $f['original_name'], $failed));
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

        $base = array_map(fn (array $upload) => $this->_assert_pdf($upload), $base);
        $ds = array_map(fn (array $upload) => $this->_assert_pdf($upload), $ds);

        // Файлы — через фасад Storage:: (prx212), приватные, без пользователя:
        // доступ к ним даёт право на пакет, а не владение файлом. Сохраняем ДО
        // транзакции (хранилище не откатывается); сбой записи пакета — файлы
        // снимаем сразу, сироту после падения процесса уберёт prx399:maintain.
        $stored = [];
        try
        {
            foreach ([['base', $base], ['ds', $ds]] as [$kind, $uploads])
            {
                foreach ($uploads as $i => $upload)
                {
                    $stored[] = [$kind, $i, $upload['name'], $this->_store($upload)];
                }
            }

            return Prx_Db::transaction(function () use ($actor, $title, $focus, $policy_json, $stored)
            {
                $contract = Model_Prx399_Contract::insert([
                    'title' => $title,
                    'focus' => $focus,
                    'policy' => $policy_json,
                    'created_by' => $actor->id(),
                ]);
                foreach ($stored as [$kind, $i, $name, $file_id])
                {
                    Model_Prx399_File::insert([
                        'contract_id' => $contract['id'],
                        'kind' => $kind,
                        'ordinal' => $kind === 'ds' ? 1 + $i : 0,
                        'original_name' => $name,
                        'file_id' => $file_id,
                    ]);
                }

                return $contract;
            });
        }
        catch (Throwable $e)
        {
            foreach ($stored as [, , , $file_id])
            {
                $this->_discard($file_id);
            }
            throw $e;
        }
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

        // Под локом пакета и в одной транзакции с задачами очереди: двойной
        // клик (или две вкладки) раньше ставил каждому файлу по две задачи.
        Prx_Db::transaction(function () use ($actor, $id)
        {
            Model_Prx399_Contract::lock($id);
            $contract = Model_Prx399_Contract::get($id);
            if ( ! $contract)
            {
                throw new Prx399_Error('contract_not_found', 404);
            }
            if ( ! in_array($contract['status'], ['draft', 'failed'], TRUE))
            {
                throw new Prx399_Error('wrong_state', 409);
            }

            foreach (Model_Prx399_File::for_contract($id) as $file)
            {
                $this->_queue_file($actor, $file);
            }

            Model_Prx399_Risk::delete_for_contract($id);
            Model_Prx399_Contract::set_status($id, 'parsing', NULL);
        });
    }

    /** Задача doc.merge.delta на один файл; вызывается под локом пакета. */
    private function _queue_file($actor, array $file)
    {
        $job_id = Service_Prx120_JobQueue::push('doc.merge.delta', [
            'contract_id' => (int) $file['contract_id'],
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
        if ( ! $contract OR ! in_array($contract['status'], ['draft', 'failed'], TRUE))
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

        Prx_Db::transaction(function () use ($actor, $file_id, $file)
        {
            Model_Prx399_Contract::lock($file['contract_id']);
            $contract = Model_Prx399_Contract::get($file['contract_id']);
            $file = Model_Prx399_File::get($file_id);
            if ( ! $contract OR ! $file OR ! in_array($contract['status'], ['failed', 'parsing'], TRUE))
            {
                throw new Prx399_Error('wrong_state', 409);
            }
            // Повторять можно только сбойный файл: повтор живого (queued/working)
            // давал вторую задачу на тот же файл, разобранного — затирал дельты
            // вместе с решениями человека «исключить/вернуть».
            if ((string) $file['status'] !== 'failed')
            {
                throw new Prx399_Error('wrong_state', 409);
            }

            $this->_queue_file($actor, $file);
            Model_Prx399_Contract::set_status($file['contract_id'], 'parsing', NULL);
        });
    }

    public function delete($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx399.run');
        $contract = Model_Prx399_Contract::get($id);
        if ( ! $contract)
        {
            throw new Prx399_Error('contract_not_found', 404);
        }

        // Каскад очистит files/deltas/risks. Файлы — ПОСЛЕ фиксации: раньше
        // они удалялись внутри транзакции, и сбой DELETE оставлял пакет без PDF.
        // Не удалённый сейчас файл prx212 станет сиротой — его уберёт
        // prx399:maintain (Storage::sweep_orphans).
        $files = Prx_Db::transaction(function () use ($id)
        {
            Model_Prx399_Contract::lock($id);
            $files = Model_Prx399_File::for_contract($id);
            Model_Prx399_Contract::delete($id);

            return $files;
        });
        foreach ($files as $file)
        {
            if ((int) ($file['file_id'] ?? 0) > 0)
            {
                $this->_discard((int) $file['file_id']);
            }
            elseif ((string) ($file['storage_path'] ?? '') !== '')
            {
                // Запись до перехода на Storage:: (миграция 399_004).
                $path = Storage_Path::absolute((string) $file['storage_path']);
                if (is_file($path) AND ! @unlink($path))
                {
                    Log_Plain::event(Log::ERROR, 'prx399 stage=delete legacy_file=:id', [':id' => (int) $file['id']]);
                }
            }
        }
    }

    // -------------------------------------------------------- maintenance

    /**
     * Обслуживание по расписанию (prx399:maintain): самолечение идущих
     * пакетов — раньше оно срабатывало, только если кто-то открыл карточку,
     * и пакет с потерянным результатом последнего файла висел «идёт» без
     * сборки, — и уборка сирот prx212.
     *
     * @return array{reconciled: int, orphans: int, errors: int}
     */
    public function maintain(): array
    {
        $report = ['reconciled' => 0, 'orphans' => 0, 'errors' => 0];
        foreach (Model_Prx399_Contract::active_ids() as $id)
        {
            try
            {
                $contract = Model_Prx399_Contract::get($id);
                if ( ! $contract)
                {
                    continue;
                }
                $files = Model_Prx399_File::for_contract($id);
                $this->_reconcile_files($id, $contract, $files);
                $this->_reconcile_pipeline($id, Model_Prx399_Contract::get($id) ?? $contract, $files);
                $report['reconciled']++;
            }
            catch (Throwable $e)
            {
                $report['errors']++;
                Log_Plain::event(Log::ERROR, 'prx399 contract=:id stage=maintain error_type=:type', [':id' => $id, ':type' => get_class($e)]);
            }
        }

        $sweep = Storage::sweep_orphans(self::MODULE, [Model_Prx399_File::class, 'referenced_files']);
        $report['orphans'] = $sweep['orphans'];
        $report['errors'] += $sweep['errors'];

        return $report;
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

    /** Проверенная загрузка: настоящий PDF (сигнатура, не расширение) не больше лимита воркера. */
    private function _assert_pdf(array $upload)
    {
        if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE))
        {
            throw new Prx399_Error('file_too_large', 413);
        }
        if ($upload['error'] !== UPLOAD_ERR_OK OR ! is_uploaded_file($upload['tmp_name']))
        {
            throw new Prx399_Error('upload_error', 400);
        }
        $size = filesize($upload['tmp_name']);
        if ($size === FALSE)
        {
            throw new Prx399_Error('upload_error', 400);
        }
        // Тот же лимит, что у воркера (PRX399_MAX_PDF_BYTES, limits.py): больший
        // файл воркер всё равно отверг бы — после часа в очереди.
        if ($size > (int) $this->_limits['max_pdf_bytes'])
        {
            throw new Prx399_Error('file_too_large', 413);
        }
        if (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf'
            OR file_get_contents($upload['tmp_name'], FALSE, NULL, 0, 5) !== '%PDF-')
        {
            throw new Prx399_Error('pdf_required', 400);
        }

        return ['tmp_name' => $upload['tmp_name'], 'name' => $upload['name'], 'size' => $size, 'type' => 'application/pdf', 'error' => UPLOAD_ERR_OK];
    }

    /** PDF пакета в prx212: приватный, без пользователя, модуль prx399. */
    private function _store(array $upload)
    {
        try
        {
            return Storage::save($upload, self::MODULE, NULL, FALSE, (int) $this->_limits['max_pdf_bytes']);
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx399 stage=store error_type=:type', [':type' => get_class($e)]);
            throw new Prx399_Error('storage_unavailable', 503);
        }
    }

    private function _discard($file_id)
    {
        try
        {
            if (Storage::delete_owned((int) $file_id, self::MODULE)) return;
        }
        catch (Throwable $e) {}
        Log_Plain::event(Log::ERROR, 'prx399 stage=discard file_id=:id', [':id' => (int) $file_id]);
    }
}
