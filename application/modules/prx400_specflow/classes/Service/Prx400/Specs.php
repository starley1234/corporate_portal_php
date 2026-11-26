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

    /**
     * Список спецификаций (+ счётчики строк, имя владельца). Модуль
     * admin-аудитории — видимы все спецификации (как пакеты prx399);
     * владелец записан в БД (owner_id, и user_id у файла prx212) —
     * показываем имя через публичный API владельца профилей.
     */
    public function list($actor): array
    {
        $specs = Model_Prx400_Spec::all_specs();
        $names = [];
        if (class_exists('Model_Prx102_User'))
        {
            try
            {
                foreach (Model_Prx102_User::get_all_names() as $uid => $p)
                {
                    $names[(int) $uid] = trim((string) ($p['full_name'] ?? ''));
                }
            }
            catch (Throwable $e) { /* имена не критичны для списка */ }
        }
        $out = [];
        foreach ($specs as $s)
        {
            $s = (array) $s;
            $s['owner_name'] = $names[(int) $s['owner_id']] ?? ('user #'.(int) $s['owner_id']);
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
    public function create($actor, array $files, array $post = [])
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');

        return $this->_ingest((int) $actor->id(), $files, $post);
    }

    /**
     * Внешнее API (токен PRX400_API_TOKEN проверен контроллером): та же
     * приёмка, что и в UI, владелец записи — 0 (машинный интегратор).
     */
    public function api_create(array $files, array $post = [])
    {
        $spec = $this->_ingest(0, $files, $post);
        $fresh = Model_Prx400_Spec::get((int) $spec['id']);

        return is_array($fresh) ? $fresh : $spec; // parse_mode уже записан пушем
    }

    /** Общая приёмка PDF: валидация → prx212 → запись → задача разбора. */
    protected function _ingest(int $owner_id, array $files, array $post)
    {
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
            // Артефакт пайплайна принадлежит МОДУЛЮ (user_id NULL): read_owned/
            // delete_owned prx212 определены только для «файлов без
            // пользователя». Загрузка от user_id делала файл «личным» —
            // read_owned его не отдавал (file_unavailable воркера,
            // лог prx400_diag 26.11); владелец записи — prx_400_specs.owner_id.
            $file_id = Storage::put($content, $name, 'prx400', NULL, FALSE, 'application/pdf');
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx400 storage put failed: :m', [':m' => $e->getMessage()]);
            throw new Prx400_Error('storage_unavailable', 503);
        }

        $spec = Model_Prx400_Spec::insert([
            'owner_id' => $owner_id,
            'original_name' => $name,
            'storage_path' => 'prx212:'.$file_id, // маркер фасада, не путь ФС
            'file_sha256' => $sha,
        ]);

        Log_Plain::event(Log::INFO, 'prx400_diag stage=ingest spec=:s owner=:o mode=:m tpl=:t', [
            ':s' => (int) $spec['id'], ':o' => $owner_id,
            ':m' => $this->_parse_mode($post), ':t' => $this->_template_id($post) ?: 'auto',
        ]);
        $this->_push_job((int) $spec['id'], $owner_id, $this->_parse_mode($post), $this->_template_id($post));

        return $spec;
    }

    /** Повторный запуск разбора (упавшая/отменённая задача). */
    public function launch($actor, $spec_id, array $post = [])
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

        // Повтор: явный режим из POST (селектор), иначе сохранённый на
        // спецификации — старая открытая страница без селектора не откатывает
        // разбор скана в OCR молча (кейс job 91: mode=ocr при выборе Vision).
        // template_id: «» = авто-детекция; явный код → воркер без детекции.
        $this->_push_job((int) $spec_id, $actor->id(), $this->_mode_for_retry($spec, $post), $this->_template_id($post));
        Model_Prx400_Spec::set_status((int) $spec_id, 'queued', NULL);
    }

    /** Режим разбора из POST: валидируется по списку из конфига, чужое → ocr. */
    protected function _parse_mode(array $post): string
    {
        $modes = (array) (Kohana::$config->load('prx400_specflow')->get('parse_modes') ?: ['ocr', 'ocr_vision', 'vision']);
        $mode = trim((string) ($post['parse_mode'] ?? ''));

        return in_array($mode, $modes, TRUE) ? $mode : 'ocr';
    }

    /** Режим повтора: явный из POST, иначе сохранённый на спецификации. */
    protected function _mode_for_retry(array $spec, array $post): string
    {
        if (array_key_exists('parse_mode', $post))
        {
            return $this->_parse_mode($post);
        }
        $modes = (array) (Kohana::$config->load('prx400_specflow')->get('parse_modes') ?: ['ocr', 'ocr_vision', 'vision']);
        $stored = trim((string) ($spec['parse_mode'] ?? ''));

        return in_array($stored, $modes, TRUE) ? $stored : 'ocr';
    }

    /** Шаблон из POST: ''/отсутствует = авто-детекция; код проверен по библиотеке. */
    protected function _template_id(array $post): string
    {
        $tpl = trim((string) ($post['template_id'] ?? ''));
        if ($tpl === '')
        {
            return '';
        }

        return Service_Prx400_Templates::get($tpl) !== NULL ? $tpl : '';
    }

    protected function _push_job($spec_id, $actor_id, $mode = 'ocr', $template_id = '')
    {
        $templates = Service_Prx400_Templates::for_payload();
        if (empty($templates))
        {
            // Пустая библиотека = обречённая задача (воркер вернёт
            // template_missing). Честная ошибка сразу, на стороне сайта.
            throw new Prx400_Error('template_library_empty', 503);
        }

        // Режим живёт на спецификации: повторы и карточка видят его всегда.
        Model_Prx400_Spec::set_parse_mode((int) $spec_id, $mode);
        $job_id = Service_Prx120_JobQueue::push('specflow.parse', [
            'spec_id' => (int) $spec_id,
            'templates' => $templates,
            'parse_mode' => (string) $mode,
            'template_id' => (string) $template_id, // '' = авто-детекция по якорям
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
        $spec['parse_mode'] = (string) ($spec['parse_mode'] ?? 'ocr');

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
            'templates' => Service_Prx400_Templates::options(),
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
            $bytes = $file_id > 0 ? Storage::read_owned($file_id, 'prx400', $this->_max_upload) : NULL;
            $mode = 'owned';
            if ($bytes === NULL AND $file_id > 0)
            {
                // Ранние загрузки prx400 привязывали артефакт к пользователю
                // (user_id != NULL): read_owned их не отдаёт (в журнале
                // «чужой артефакт»/WARNING) — читаем bytes(), право
                // подтверждено строкой prx_400_specs, которой принадлежит файл.
                $bytes = Storage::bytes($file_id);
                $mode = 'bytes';
            }
            Log_Plain::event(Log::INFO, 'prx400_diag stage=pdf_resolve spec=:id via=prx212 file_id=:f mode=:m result=:r', [
                ':id' => (int) $spec['id'], ':f' => $file_id, ':m' => $mode,
                ':r' => $bytes !== NULL ? 'ok bytes='.strlen((string) $bytes) : 'missing',
            ]);

            return $bytes;
        }

        [$path, $healed] = self::resolve_pdf($raw);
        Log_Plain::event(Log::INFO, 'prx400_diag stage=pdf_resolve spec=:id via=legacy result=:r healed=:h path=:p', [
            ':id' => (int) $spec['id'],
            ':r' => $path !== NULL ? 'ok' : 'missing',
            ':h' => $healed ?? '-',
            ':p' => $path ?? $raw,
        ]);
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
            $ok = Storage::metadata((int) substr($raw, 7)) !== NULL;
            Log_Plain::event(Log::INFO, 'prx400_diag stage=file_ok spec=:id via=prx212 file_id=:file result=:r', [
                ':id' => (int) $spec['id'], ':file' => (int) substr($raw, 7), ':r' => $ok ? 'ok' : 'missing',
            ]);

            return $ok;
        }
        [$path, $healed] = self::resolve_pdf($raw);
        Log_Plain::event(Log::INFO, 'prx400_diag stage=file_ok spec=:id via=legacy result=:r healed=:h path=:p', [
            ':id' => (int) $spec['id'],
            ':r' => $path !== NULL ? 'ok' : 'missing',
            ':h' => $healed ?? '-',
            ':p' => $path ?? $raw,
        ]);
        if ($healed !== NULL)
        {
            // Самолечение при простом показе карточки: перепривязанный путь
            // записываем навсегда, даже если разбор ещё не перезапускали.
            Prx_Db::execute(
                'UPDATE prx_400_specs SET storage_path = :p WHERE id = :id',
                [':id' => (int) $spec['id'], ':p' => $healed]);
        }

        return $path !== NULL;
    }

    /**
     * Перезалить файл СУЩЕСТВУЮЩЕЙ спецификации (карточка → «Перезалить»):
     * валидация как в create(), файл — в prx212 (маркер prx212:<id>),
     * старый файл убирается, задача разбора ставится заново. ID и история
     * карточки сохраняются — в отличие от создания новой записи.
     */
    public function reupload($actor, $spec_id, array $files, array $post = [])
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');
        $spec = Model_Prx400_Spec::get($spec_id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }

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

        $content = (string) file_get_contents($upload['tmp_name']);
        if ($content === '')
        {
            throw new Prx400_Error('upload_error', 400);
        }
        try
        {
            // Артефакт модуля (user_id NULL) — см. комментарий в create().
            $file_id = Storage::put($content, mb_substr(trim((string) $upload['name']), 0, 255), 'prx400', NULL, FALSE, 'application/pdf');
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx400 storage put failed: :m', [':m' => $e->getMessage()]);
            throw new Prx400_Error('storage_unavailable', 503);
        }

        // Старый файл убираем ПОСЛЕ успешной записи нового (без «дыры»).
        $raw = (string) $spec['storage_path'];
        if (strpos($raw, 'prx212:') === 0)
        {
            $this->_prx212_cleanup($actor, (int) substr($raw, 7));
        }
        else
        {
            [$old] = self::resolve_pdf($raw);
            if ($old !== NULL AND is_file($old))
            {
                @unlink($old);
            }
        }

        Model_Prx400_Spec::set_file((int) $spec_id, 'prx212:'.$file_id, hash('sha256', $content));
        $this->_push_job((int) $spec_id, $actor->id(), $this->_mode_for_retry($spec, $post), $this->_template_id($post));
        Model_Prx400_Spec::set_status((int) $spec_id, 'queued', NULL);
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

        // Пустой разбор — не успех: старый воркер мог привезти 0 строк
        // (карточка «готово» без позиций, кейс spec 10). Честный failed с
        // подсказкой; протокол отвечаем по канону prx397 (failed + error_code).
        if (empty($rows))
        {
            Log_Plain::event(Log::WARNING, 'prx400_diag stage=result spec=:s rows=0 → failed(no_rows)', [':s' => (int) $spec_id]);
            Model_Prx400_Spec::set_status((int) $spec_id, 'failed', 'no_rows: строки не найдены — перезапустите разбор с режимом «Vision»');

            return ['status' => 'failed', 'error_code' => 'no_rows'];
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

        // «Проверено» — переключатель: повторное нажатие снимает отметку,
        // статус строки пересчитывается из её же данных (ошибка/ок/ревью/новая).
        // Оператор не может застрять: нажал с ошибкой — можно откатить.
        if ((string) $row['verified'] === 't')
        {
            Model_Prx400_Row::set_verified((int) $row_id, $actor->id(), FALSE);
            Model_Prx400_Row::recompute_status((int) $row_id);

            return;
        }

        Model_Prx400_Row::set_verified((int) $row_id, $actor->id(), TRUE);
    }

    /**
     * Удалить спецификацию: позиции — каскадом (FK), файл — из хранилища
     * (prx212-файл через delete_owned; легаси-локальный — unlink). Файл
     * убираем ПОСЛЕ записи в БД: падение файловой операции не оставляет
     * живую запись без файла (осиротевший файл безвреден).
     */
    public function delete($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');
        $spec = Model_Prx400_Spec::get($id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }

        $raw = (string) $spec['storage_path'];
        Model_Prx400_Spec::delete((int) $id);

        if (strpos($raw, 'prx212:') === 0)
        {
            $this->_prx212_cleanup($actor, (int) substr($raw, 7));

            return;
        }
        [$path] = self::resolve_pdf($raw);
        if ($path !== NULL AND is_file($path))
        {
            @unlink($path);
        }
    }

    /**
     * Убрать артефакт prx400 из хранилища: штатно delete_owned («файл без
     * пользователя»). Ранние загрузки привязывали артефакт к пользователю —
     * delete_owned отказывает («чужой артефакт», лог 26.11 23:01) — тогда
     * удаляет владелец файла. Уборка НИКОГДА не валит операцию: осиротевший
     * файл безвреден, и падение после удаления записи честнее 500.
     */
    private function _prx212_cleanup($actor, int $file_id)
    {
        try
        {
            if (Storage::delete_owned($file_id, 'prx400'))
            {
                Log_Plain::event(Log::INFO, 'prx400_diag stage=file_delete file_id=:f result=ok mode=owned', [':f' => $file_id]);
            }
            else
            {
                Log_Plain::event(Log::INFO, 'prx400_diag stage=file_delete file_id=:f result=missing', [':f' => $file_id]);
            }

            return;
        }
        catch (Storage_Exception $e)
        {
            try
            {
                Storage::delete($file_id, ($actor ?? Prx_Actor::guest())->id());
                Log_Plain::event(Log::INFO, 'prx400_diag stage=file_delete file_id=:f result=ok mode=owner', [':f' => $file_id]);
            }
            catch (Throwable $e2)
            {
                Log_Plain::event(Log::WARNING, 'prx400_diag stage=file_delete file_id=:f result=orphan :m', [
                    ':f' => $file_id, ':m' => $e2->getMessage(),
                ]);
            }
        }
    }

    /**
     * Правка полей строки оператором (qty/unit/наименование/марка):
     * журнал правок — prx_400_edits, статус пересчитывается. Возвращает
     * spec_id для редиректа на карточку.
     */
    public function row_edit($actor, $row_id, array $post)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');
        $row = Model_Prx400_Row::get($row_id);
        if ( ! $row)
        {
            throw new Prx400_Error('not_found', 404);
        }

        $fields = [];
        if (array_key_exists('raw_name', $post))
        {
            $fields['raw_name'] = mb_substr(trim((string) $post['raw_name']), 0, 4000);
        }
        if (array_key_exists('code_doc', $post))
        {
            $fields['code_doc'] = mb_substr(trim((string) $post['code_doc']), 0, 255);
        }
        if (array_key_exists('unit', $post))
        {
            $fields['unit'] = mb_substr(trim((string) $post['unit']), 0, 16);
        }
        if (array_key_exists('qty', $post))
        {
            $qty = trim((string) $post['qty']);
            if ($qty === '')
            {
                $fields['qty'] = NULL;
            }
            elseif (is_numeric(str_replace([' ', ','], ['', '.'], $qty)))
            {
                $fields['qty'] = (float) str_replace([' ', ','], ['', '.'], $qty);
            }
            else
            {
                throw new Prx400_Error('qty_invalid', 400);
            }
        }
        if ($fields !== [])
        {
            Model_Prx400_Row::apply_edit((int) $row_id, $actor->id(), $fields);
        }

        return (int) $row['spec_id'];
    }

    /** Добавить позицию в справочник НСИ (MVP-наполнение через UI). */
    public function catalog_add($actor, array $post)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');

        $code = mb_substr(trim((string) ($post['code'] ?? '')), 0, 64);
        $name = mb_substr(trim((string) ($post['name'] ?? '')), 0, 500);
        if ($code === '' OR $name === '')
        {
            throw new Prx400_Error('catalog_fields_required', 400);
        }

        Model_Prx400_Catalog::insert([
            'code' => $code,
            'name' => $name,
            'unit' => mb_substr(trim((string) ($post['unit'] ?? 'шт.')) ?: 'шт.', 0, 16),
            'okei_code' => mb_substr(trim((string) ($post['okei_code'] ?? '')), 0, 8),
            'keywords' => mb_substr(trim((string) ($post['keywords'] ?? '')), 0, 500),
        ]);
    }

    /** Убрать позицию из справочника НСИ. */
    public function catalog_delete($actor, $id)
    {
        ($actor ?? Prx_Actor::guest())->require('prx400.run');
        Model_Prx400_Catalog::delete((int) $id);
    }

    /**
     * Исходный PDF по id ЗАДАЧИ prx120: URL воркера несёт job_id, а не
     * spec_id (канон prx397: WHERE job_id =). Раньше job_id трактовался как
     * spec_id — совпадали они только в первых тестах (spec 1 ↔ job 1),
     * дальше все скачивания падали 404 file_unavailable при живом файле.
     */
    public function read_pdf_for_job($job_id)
    {
        $spec = Model_Prx400_Spec::get_by_job($job_id);
        if ( ! $spec)
        {
            Log_Plain::event(Log::WARNING, 'prx400_diag stage=worker_file job=:j result=no_spec', [':j' => (int) $job_id]);
            throw new Prx400_Error('file_unavailable', 404);
        }
        $pdf = $this->read_pdf((int) $spec['id']);
        Log_Plain::event(Log::INFO, 'prx400_diag stage=worker_file job=:j spec=:s result=ok bytes=:b', [
            ':j' => (int) $job_id, ':s' => (int) $spec['id'], ':b' => strlen((string) $pdf),
        ]);

        return $pdf;
    }

    /** Результат разбора по id задачи prx120 (см. read_pdf_for_job). */
    public function result_for_job($job_id, array $body)
    {
        $spec = Model_Prx400_Spec::get_by_job($job_id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }

        return $this->result((int) $spec['id'], $body);
    }

    /**
     * Статус/результат для внешнего API: queued/running → задача в работе;
     * failed → код ошибки; review/done → СТРУКТУРИРОВАННЫЙ JSON той же
     * схемы, что экспорт ERP (meta/positions/audit; review помечен
     * awaiting_operator — строки есть, но ждут оператора).
     */
    public function api_status($id): array
    {
        $spec = Model_Prx400_Spec::get($id);
        if ( ! $spec)
        {
            throw new Prx400_Error('not_found', 404);
        }

        $status = (string) $spec['status'];
        $out = [
            'spec_id' => (int) $spec['id'],
            'status' => $status,
            'parse_mode' => (string) ($spec['parse_mode'] ?? 'ocr'),
            'poll_url' => '/prx400_specflow/api/spec/'.(int) $spec['id'],
        ];

        if (in_array($status, ['queued', 'running'], TRUE))
        {
            $out['job_id'] = (int) ($spec['job_id'] ?? 0) ?: NULL;

            return $out;
        }
        if ($status === 'failed')
        {
            $out['error'] = ['code' => (string) $spec['error']];

            return $out;
        }

        $out['result'] = Service_Prx400_Export::json((array) $spec, Model_Prx400_Row::for_spec((int) $spec['id']));
        $out['awaiting_operator'] = $status === 'review';

        return $out;
    }

    /** Завершение проверки оператором (HITL): review → done. */
    public function finish($actor, $spec_id, array $post = [])
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

        // Гейт качества: с неподтверждёнными расхождениями завершение только
        // осознанное — чекбокс «принимаю с расхождениями» в форме карточки.
        $issues = 0;
        foreach (Model_Prx400_Row::for_spec((int) $spec_id) as $r)
        {
            if ((string) $r['status'] !== 'ok' AND (string) $r['verified'] !== 't')
            {
                $issues++;
            }
        }
        if ($issues > 0 AND trim((string) ($post['accept_issues'] ?? '')) !== '1')
        {
            throw new Prx400_Error('issues_pending', 409, ['issues' => $issues]);
        }

        Model_Prx400_Spec::set_status((int) $spec_id, 'done', NULL);
    }
}
