<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис 3D-конструктора (NJ-570, «Sketch 2 Solid», /3d).
 *
 * Поток: модель → ревизии (промпт+изображение+код+модели LLM — датасет для
 * дообучения) → задачи воркеров nj120 (каналы nj570.generate / nj570.clarify /
 * nj570.render) → файлы STL/PNG (воркер загружает их сам через /3d/api/upload).
 *
 * RAG: завершённые ревизии складываем в базу знаний nj560 (комбинированный
 * поиск — семантика+векторы) для «похожих моделей» и будущих пайплайнов.
 */
class Service_Nj570_Models {

    const CHANNEL_GENERATE = 'nj570.generate';
    const CHANNEL_CLARIFY  = 'nj570.clarify';
    const CHANNEL_RENDER   = 'nj570.render';

    /** @var Database */
    protected static $_db;

    protected static function _db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('nj570_models');
        }

        $value = $cfg;

        foreach (explode('.', $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value))
            {
                return $default;
            }

            $value = $value[$part];
        }

        return $value === NULL ? $default : $value;
    }

    /**
     * Хранилище файлов: public_html/storage/nj570 — bin/setup.sh на каждом
     * деплое создаёт public_html/storage с правами на запись (php-fpm сможет
     * писать). Вне-докрутный storage/ корня репо недоступен php-fpm на запись.
     */
    public static function storage_dir()
    {
        $dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj570';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        return $dir;
    }

    // ------------------------------------------------------------------
    // Создание и ревизии
    // ------------------------------------------------------------------

    /**
     * Создать модель и первую ревизию, поставить задачу воркеру.
     *
     * @param   int    $user_id
     * @param   string $prompt
     * @param   string $imageB64  base64 изображения (уже уменьшенного) или ''
     * @param   array  $opts      iterations, use_search, clarify
     * @return  array  ['code'=>…] | ['error'=>…]
     */
    public static function create($user_id, $prompt, $imageB64 = '', array $opts = [])
    {
        $prompt = trim((string) $prompt);

        if ($prompt === '' AND $imageB64 === '')
        {
            return ['error' => 'Опишите конструкцию текстом или приложите изображение.'];
        }

        $iterations = max(1, min((int) self::_cfg('iterations_max', 5),
            (int) Arr::get($opts, 'iterations', self::_cfg('iterations_default', 3))));

        $db   = self::_db();
        $code = (string) self::_cfg('code_prefix', 'm3d').bin2hex(random_bytes((int) self::_cfg('code_bytes', 4)));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_570_models (code, user_id, iterations, use_search)
             VALUES ('.$db->quote($code).', '.(int) $user_id.', '.$iterations.', '.($opts['use_search'] ? 'TRUE' : 'FALSE').')
             RETURNING id, code', FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось создать модель — попробуйте ещё раз.'];
        }

        $revision = self::_add_revision((int) $row['id'], 'create', $prompt, $imageB64);

        if (isset($revision['error']))
        {
            return $revision;
        }

        $job = Arr::get($opts, 'clarify')
            ? self::_dispatch(self::CHANNEL_CLARIFY, (int) $row['id'], $revision['id'], $prompt, $imageB64, [])
            : self::_dispatch(self::CHANNEL_GENERATE, (int) $row['id'], $revision['id'], $prompt, $imageB64, [
                'iterations' => $iterations,
                'use_search' => (bool) Arr::get($opts, 'use_search'),
            ]);

        if ($job === 0)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_570_revisions SET status = 'error', error = 'Очередь задач недоступна'
                 WHERE id = ".(int) $revision['id']);

            return ['error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        return ['code' => $code];
    }

    /** Новая ревизия (правка текстом/изображением) + задача генерации. */
    public static function edit($user_id, $code, $prompt, $imageB64 = '')
    {
        $model = self::by_code($code);

        if ($model === NULL OR (int) $model['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Модель не найдена или не ваша'];
        }

        $prompt = trim((string) $prompt);

        if ($prompt === '' AND $imageB64 === '')
        {
            return ['error' => 'Пустая правка: нужен текст или изображение.'];
        }

        $source = ($imageB64 !== '') ? 'edit_image' : 'edit_text';
        $prev   = self::_latest_done_code((int) $model['id']);

        $revision = self::_add_revision((int) $model['id'], $source, $prompt, $imageB64);

        if (isset($revision['error']))
        {
            return $revision;
        }

        $job = self::_dispatch(self::CHANNEL_GENERATE, (int) $model['id'], $revision['id'], $prompt, $imageB64, [
            'iterations' => (int) $model['iterations'],
            'use_search' => FALSE,          // правки — без поиска, только контекст
            'prev_code'  => $prev,
        ]);

        if ($job === 0)
        {
            self::_db()->query(Database::UPDATE,
                "UPDATE nj_570_revisions SET status = 'error', error = 'Очередь задач недоступна'
                 WHERE id = ".(int) $revision['id']);

            return ['error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        return ['ok' => TRUE, 'revision_id' => $revision['id']];
    }

    /** Ответы на уточняющие вопросы → запуск генерации. */
    public static function answer_clarify($user_id, $code, array $answers)
    {
        $model = self::by_code($code);

        if ($model === NULL OR (int) $model['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Модель не найдена или не ваша'];
        }

        $db    = self::_db();
        $row   = $db->query(Database::SELECT,
            'SELECT clarify FROM nj_570_models WHERE id = '.(int) $model['id'], FALSE)->current();
        $data  = $row ? json_decode((string) $row['clarify'], TRUE) : NULL;

        if ( ! is_array($data) OR empty($data['questions']))
        {
            return ['error' => 'Уточнения не запрашивались'];
        }

        $clean = [];

        foreach ($data['questions'] as $i => $q)
        {
            if (isset($answers[$i]) AND trim((string) $answers[$i]) !== '')
            {
                $clean[$i] = mb_substr(trim((string) $answers[$i]), 0, 200);
            }
        }

        $json = json_encode(['questions' => $data['questions'], 'answers' => $clean], JSON_UNESCAPED_UNICODE);

        $db->query(Database::UPDATE,
            'UPDATE nj_570_models
             SET clarify = '.$db->quote($json).'::jsonb WHERE id = '.(int) $model['id']);

        $rev = $db->query(Database::SELECT,
            'SELECT id, prompt, image_b64 FROM nj_570_revisions
             WHERE model_id = '.(int) $model['id'].' ORDER BY rev_no DESC LIMIT 1', FALSE)->current();

        if ( ! $rev)
        {
            return ['error' => 'Ревизия не найдена'];
        }

        $job = self::_dispatch(self::CHANNEL_GENERATE, (int) $model['id'], (int) $rev['id'],
            (string) $rev['prompt'], (string) $rev['image_b64'], [
                'iterations' => (int) $model['iterations'],
                'use_search' => (bool) $model['use_search'],
                'answers'    => $clean,
                'questions'  => $data['questions'],
            ]);

        if ($job === 0)
        {
            return ['error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        $db->query(Database::UPDATE,
            "UPDATE nj_570_revisions SET status = 'new', error = NULL, job_id = ".(int) $job."
             WHERE id = ".(int) $rev['id']);

        return ['ok' => TRUE];
    }

    /** Пересобрать STL последней готовой ревизии с другими значениями ползунков. */
    public static function rerender($user_id, $code, array $overrides)
    {
        $model = self::by_code($code);

        if ($model === NULL OR ((int) $model['user_id'] !== (int) $user_id AND ! Auth::instance()->logged_in('admin')))
        {
            return ['error' => 'Модель не найдена или нет доступа'];
        }

        $rev = self::_db()->query(Database::SELECT,
            "SELECT id, code FROM nj_570_revisions
             WHERE model_id = ".(int) $model['id']." AND status = 'done' AND code IS NOT NULL
             ORDER BY rev_no DESC LIMIT 1", FALSE)->current();

        if ( ! $rev)
        {
            return ['error' => 'Нет готового кода — дождитесь генерации'];
        }

        $clean = [];

        foreach ($overrides as $name => $value)
        {
            if (is_string($name) AND preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,40}$/', $name)
                AND preg_match('/^-?\d+(\.\d+)?$/', (string) $value))
            {
                $clean[$name] = (string) $value;
            }
        }

        if ( ! $clean)
        {
            return ['error' => 'Нет корректных параметров'];
        }

        $job = self::_dispatch(self::CHANNEL_RENDER, (int) $model['id'], (int) $rev['id'], '', '', [
            'code'      => (string) $rev['code'],
            'overrides' => $clean,
        ]);

        return $job === 0 ? ['error' => 'Очередь задач недоступна'] : ['ok' => TRUE];
    }

    protected static function _add_revision($model_id, $source, $prompt, $imageB64)
    {
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_570_revisions (model_id, rev_no, source, prompt, image_b64)
             VALUES ('.(int) $model_id.',
                     COALESCE((SELECT MAX(rev_no) + 1 FROM nj_570_revisions WHERE model_id = '.(int) $model_id.'), 1),
                     '.$db->quote((string) $source).', '.$db->quote((string) $prompt).',
                     '.($imageB64 !== '' ? $db->quote((string) $imageB64) : 'NULL').')
             RETURNING id, rev_no', FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось сохранить ревизию'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_570_models SET current_rev = '.(int) $row['rev_no'].', updated_at = now() WHERE id = '.(int) $model_id);

        return ['id' => (int) $row['id'], 'rev_no' => (int) $row['rev_no']];
    }

    protected static function _latest_done_code($model_id)
    {
        $row = self::_db()->query(Database::SELECT,
            "SELECT code FROM nj_570_revisions
             WHERE model_id = ".(int) $model_id." AND status = 'done' AND code IS NOT NULL
             ORDER BY rev_no DESC LIMIT 1", FALSE)->current();

        return $row ? (string) $row['code'] : '';
    }

    /**
     * Поставить задачу в очередь. created_by = владелец модели →
     * подписка erp217 «Задача выполнена» придёт именно ему.
     */
    protected static function _dispatch($channel, $model_id, $revision_id, $prompt, $imageB64, array $extra)
    {
        $model_code = self::_db()->query(Database::SELECT,
            'SELECT code FROM nj_570_models WHERE id = '.(int) $model_id, FALSE)->get('code');

        $payload = array_merge([
            'code'        => (string) $model_code,
            'revision_id' => $revision_id,
            'model_id'    => $model_id,
            'prompt'      => (string) $prompt,
            'image_b64'   => (string) $imageB64,
        ], $extra);

        $user_id = self::_db()->query(Database::SELECT,
            'SELECT user_id FROM nj_570_models WHERE id = '.(int) $model_id, FALSE)->current();

        try
        {
            $job = Service_Nj120_JobQueue::push($channel, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj570',
                'created_by'   => $user_id ? (int) $user_id['user_id'] : NULL,
                'timeout_sec'  => 1500,
            ]);

            self::_db()->query(Database::UPDATE,
                'UPDATE nj_570_revisions SET job_id = '.(int) $job.", status = 'coding'
                 WHERE id = ".(int) $revision_id);

            return (int) $job;
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj570: не удалось поставить задачу: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Reconcile (лениво при чтении статуса)
    // ------------------------------------------------------------------

    public static function reconcile(array $rev)
    {
        if ( ! in_array($rev['status'], ['new', 'coding', 'rendering'], TRUE) OR empty($rev['job_id']))
        {
            return $rev;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $rev['job_id']);

        if ($job === NULL)
        {
            return $rev;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode((string) $res, TRUE);
            $res = is_array($res) ? $res : [];

            self::_finish_revision((int) $rev['id'], (int) $rev['model_id'], $res, (int) $rev['job_id']);
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_570_revisions SET status = 'error', error = ".$db->quote(mb_substr((string) $job['error'], 0, 900)).",
                 finished_at = now() WHERE id = ".(int) $rev['id']);
        }
        elseif (in_array($job['status'], [Service_Nj120_JobQueue::STATUS_PENDING, Service_Nj120_JobQueue::STATUS_PROCESSING], TRUE))
        {
            // Зависшая задача (воркер убит рестартом и т.п.): держать «в работе»
            // вечно нельзя — по истечении timeout_sec + 5 минут помечаем ошибкой.
            $started = $job['claimed_at'] ?: $job['created_at'];
            $limit   = (int) ($job['timeout_sec'] ?: 600) + 300;

            if ($started AND (time() - strtotime((string) $started)) > $limit)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_570_revisions SET status = 'error',
                        error = 'Задача зависла (воркер недоступен или перезапущен) — попробуйте создать модель заново',
                        finished_at = now() WHERE id = ".(int) $rev['id']);
            }
        }

        return self::revision_by_id((int) $rev['id']);
    }

    protected static function _finish_revision($rev_id, $model_id, array $res, $job_id)
    {
        $db = self::_db();

        // Кларификация: воркер вернул вопросы
        if (isset($res['questions']) AND is_array($res['questions']))
        {
            $json = json_encode(['questions' => $res['questions'], 'answers' => NULL], JSON_UNESCAPED_UNICODE);

            $db->query(Database::UPDATE,
                'UPDATE nj_570_models SET clarify = '.$db->quote($json).'::jsonb WHERE id = '.(int) $model_id);

            $db->query(Database::UPDATE,
                "UPDATE nj_570_revisions SET status = 'clarify', finished_at = now() WHERE id = ".(int) $rev_id);

            return;
        }

        $code = (string) Arr::get($res, 'code', '');

        if ($code === '')
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_570_revisions SET status = 'error', error = 'Воркер не вернул код', finished_at = now()
                 WHERE id = ".(int) $rev_id);

            return;
        }

        // Пересборка по ползункам (rerendered): воркер не возвращает summary —
        // обновляем ТОЛЬКО код/параметры/затраты, иначе затираем описание ревизии
        // и описание модели, полученные при генерации.
        if ( ! empty($res['rerendered']))
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_570_revisions SET
                    code = '.$db->quote($code).',
                    params = '.(isset($res['params']) ? $db->quote(json_encode($res['params'], JSON_UNESCAPED_UNICODE)).'::jsonb' : 'params').',
                    usage = '.(isset($res['usage']) ? $db->quote(json_encode($res['usage'], JSON_UNESCAPED_UNICODE)).'::jsonb' : 'usage').",
                    status = 'done', error = NULL, finished_at = now()
                 WHERE id = ".(int) $rev_id);

            self::_usage_rows($db, $model_id, $rev_id, $job_id, Arr::get($res, 'usage', []));

            return;
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_570_revisions SET
                code = '.$db->quote($code).',
                code_model = '.$db->quote((string) Arr::get($res, 'code_model', '')).',
                desc_model = '.$db->quote((string) Arr::get($res, 'desc_model', '')).',
                summary = '.$db->quote(mb_substr((string) Arr::get($res, 'summary', ''), 0, 2000)).',
                params = '.(isset($res['params']) ? $db->quote(json_encode($res['params'], JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL').',
                usage = '.(isset($res['usage']) ? $db->quote(json_encode($res['usage'], JSON_UNESCAPED_UNICODE)).'::jsonb' : 'NULL').",
                status = 'done', error = NULL, finished_at = now()
             WHERE id = ".(int) $rev_id);

        // ресурсные затраты — построчно
        self::_usage_rows($db, $model_id, $rev_id, $job_id, Arr::get($res, 'usage', []));

        // заголовок/описание модели — из первой ревизии
        $cnt = $db->query(Database::SELECT,
            "SELECT COUNT(*) AS c, MAX(summary) AS s FROM nj_570_revisions
             WHERE model_id = ".(int) $model_id." AND status = 'done'", FALSE)->current();

        if ($cnt AND (int) $cnt['c'] === 1)
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_570_models SET description = '.$db->quote(mb_substr((string) Arr::get($res, 'summary', ''), 0, 2000)).'
                 WHERE id = '.(int) $model_id." AND (title = '' OR description = '')");

            $db->query(Database::UPDATE,
                'UPDATE nj_570_models SET title = '.$db->quote(mb_substr(Arr::get($res, 'title', ''), 0, 200)).'
                 WHERE id = '.(int) $model_id." AND title = '' AND ".(Arr::get($res, 'title') ? 'TRUE' : 'FALSE'));
        }

        // наработка — в общую базу знаний (RAG: семантика+векторы, nj560)
        try
        {
            if (class_exists('Service_Nj560_Knowledge'))
            {
                Service_Nj560_Knowledge::remember('nj570', 'scad_model',
                    Service_Nj560_Knowledge::make_key(Arr::get($res, 'summary', '').'|'.$code),
                    (string) Arr::get($res, 'title', '3D-модель'),
                    $code,
                    ['summary' => Arr::get($res, 'summary', ''), 'revision_id' => $rev_id]);
            }
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj570: база знаний недоступна: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Затраты LLM построчно (http-режим: строки приходят в job.result). */
    protected static function _usage_rows($db, $model_id, $rev_id, $job_id, $usage)
    {
        foreach ((array) $usage as $u)
        {
            if ( ! is_array($u))
            {
                continue;
            }

            $db->query(Database::SELECT,
                'INSERT INTO nj_570_usage (model_id, revision_id, job_id, phase, model, prompt_tokens, completion_tokens, duration_ms)
                 VALUES ('.(int) $model_id.', '.(int) $rev_id.', '.(int) $job_id.',
                         '.$db->quote((string) Arr::get($u, 'phase', '')).',
                         '.$db->quote(mb_substr((string) Arr::get($u, 'model', ''), 0, 120)).',
                         '.(int) Arr::get($u, 'prompt_tokens', 0).', '.(int) Arr::get($u, 'completion_tokens', 0).',
                         '.(int) Arr::get($u, 'duration_ms', 0).') RETURNING id', FALSE)->current();
        }
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function by_code($code)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_570_models WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function by_id($id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_570_models WHERE id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function revision_by_id($id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_570_revisions WHERE id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Ревизии модели (свежие сверху) + reconcile «висящих». */
    public static function revisions($model_id, $reconcile = TRUE)
    {
        $rows = self::_db()->query(Database::SELECT,
            'SELECT id, model_id, rev_no, source, prompt, status, error, summary, params, code_model, desc_model, usage, job_id,
                    (image_b64 IS NOT NULL) AS has_image, created_at, finished_at
             FROM nj_570_revisions WHERE model_id = '.(int) $model_id.'
             ORDER BY rev_no DESC LIMIT '.(int) self::_cfg('max_revisions', 30), FALSE)->as_array();

        $out = [];

        foreach ($rows as $r)
        {
            $r = (array) $r;

            if ($reconcile)
            {
                $r = self::reconcile($r);
            }

            // reconcile перечитывает строку через SELECT * — приводим к одной форме:
            // image_b64 не тянем в представления, заменяем флагом has_image
            if (array_key_exists('image_b64', $r))
            {
                $r['has_image'] = ! empty($r['image_b64']);
                unset($r['image_b64']);
            }

            foreach (['model_id', 'prompt', 'summary', 'error', 'has_image', 'params'] as $key)
            {
                if ( ! array_key_exists($key, $r))
                {
                    $r[$key] = NULL;
                }
            }

            $r['files'] = self::_files((int) $r['id']);
            $out[] = $r;
        }

        return $out;
    }

    protected static function _files($rev_id)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT id, kind, size FROM nj_570_files WHERE revision_id = '.(int) $rev_id.' ORDER BY id',
            FALSE)->as_array();
    }

    public static function file($id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT f.*, r.model_id FROM nj_570_files f
             JOIN nj_570_revisions r ON r.id = f.revision_id WHERE f.id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Мои модели. */
    public static function list_user($user_id, $limit = 30)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT m.*, m.current_rev,
                    (SELECT f.id FROM nj_570_files f
                     JOIN nj_570_revisions r ON r.id = f.revision_id
                     WHERE r.model_id = m.id AND f.kind = \'png\'
                     ORDER BY r.rev_no DESC, f.id DESC LIMIT 1) AS png_file_id
             FROM nj_570_models m WHERE m.user_id = '.(int) $user_id.'
             ORDER BY m.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    /** Публичные (published): текстовый поиск + комбинированный RAG (nj560). */
    public static function list_public($q = '', $limit = 24)
    {
        $db  = self::_db();
        $sql = 'SELECT m.*, m.current_rev,
                       (SELECT ROUND(AVG(score)::numeric, 1) FROM nj_570_ratings rt WHERE rt.model_id = m.id) AS rating,
                       (SELECT COUNT(*) FROM nj_570_ratings rt WHERE rt.model_id = m.id) AS votes,
                       (SELECT f.id FROM nj_570_files f
                        JOIN nj_570_revisions r ON r.id = f.revision_id
                        WHERE r.model_id = m.id AND f.kind = \'png\'
                        ORDER BY r.rev_no DESC, f.id DESC LIMIT 1) AS png_file_id
                FROM nj_570_models m WHERE m.status = \'published\'';
        $q   = trim((string) $q);
        $ids = [];

        if ($q !== '')
        {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

            // RAG: комбинированный поиск — текст (nj560_docs) + векторы (nj115)
            if (class_exists('Service_Nj560_Knowledge'))
            {
                try
                {
                    $hits = Service_Nj560_Knowledge::search($q, ['source' => 'nj570', 'limit' => 12, 'semantic' => TRUE]);

                    foreach ((array) $hits['semantic'] as $h)
                    {
                        $ids[] = (int) $h['doc_id'];
                    }
                }
                catch (Exception $e)
                {
                    // семантика недоступна — останется текстовый поиск
                }
            }

            $sql .= ' AND (m.title ILIKE '.$db->quote($like).' OR m.description ILIKE '.$db->quote($like).')';

            if ($ids)
            {
                // семантические совпадения — первыми
                $sql .= ' ORDER BY CASE WHEN m.id IN ('.implode(',', array_map('intval', array_unique($ids))).')
                          THEN 0 ELSE 1 END, m.id DESC';
            }
            else
            {
                $sql .= ' ORDER BY m.id DESC';
            }
        }
        else
        {
            $sql .= ' ORDER BY m.id DESC';
        }

        $sql .= ' LIMIT '.(int) $limit;

        return $db->query(Database::SELECT, $sql, FALSE)->as_array();
    }

    /** Средний рейтинг модели. */
    public static function rating($model_id)
    {
        $row = self::_db()->query(Database::SELECT,
            'SELECT COALESCE(ROUND(AVG(score)::numeric, 1), 0) AS avg, COUNT(*) AS votes
             FROM nj_570_ratings WHERE model_id = '.(int) $model_id, FALSE)->current();

        return $row ? ['avg' => (float) $row['avg'], 'votes' => (int) $row['votes']] : ['avg' => 0.0, 'votes' => 0];
    }

    public static function rate($user_id, $model_id, $score, $comment = '')
    {
        $score = (int) $score;

        if ($score < 1 OR $score > 5)
        {
            return ['error' => 'Оценка от 1 до 5'];
        }

        $db = self::_db();

        $db->query(Database::SELECT,
            'INSERT INTO nj_570_ratings (model_id, user_id, score, comment)
             VALUES ('.(int) $model_id.', '.(int) $user_id.', '.$score.', '.$db->quote(mb_substr(trim((string) $comment), 0, 500)).')
             ON CONFLICT (model_id, user_id) DO UPDATE SET score = EXCLUDED.score, comment = EXCLUDED.comment, created_at = now()
             RETURNING id', FALSE)->current();

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------------------
    // Публикация и модерация
    // ------------------------------------------------------------------

    public static function publish($user_id, $code)
    {
        $model = self::by_code($code);

        if ($model === NULL OR (int) $model['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Модель не найдена или не ваша'];
        }

        if ($model['status'] !== 'private')
        {
            return ['error' => 'Модель уже на модерации или опубликована'];
        }

        self::_db()->query(Database::UPDATE,
            "UPDATE nj_570_models SET status = 'pending', updated_at = now() WHERE id = ".(int) $model['id']);

        return ['ok' => TRUE];
    }

    public static function moderate($code, $approve)
    {
        $model = self::by_code($code);

        if ($model === NULL)
        {
            return ['error' => 'Модель не найдена'];
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_570_models SET status = '.self::_db()->quote($approve ? 'published' : 'rejected').',
             updated_at = now() WHERE id = '.(int) $model['id']);

        return ['ok' => TRUE];
    }

    public static function pending_list($limit = 50)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT m.*, u.username FROM nj_570_models m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.status = \'pending\' ORDER BY m.updated_at DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    // ------------------------------------------------------------------
    // MCP (хаб nj160_mcp, декларации в config/mcp.php)
    // ------------------------------------------------------------------

    public static function mcp_list(array $args)
    {
        $limit = max(1, min(24, (int) Arr::get($args, 'limit', 12)));
        $out   = [];

        foreach (self::list_public(trim((string) Arr::get($args, 'q', '')), $limit) as $m)
        {
            $out[] = [
                'code'        => (string) $m['code'],
                'title'       => (string) $m['title'],
                'description' => mb_substr((string) $m['description'], 0, 300),
                'rating'      => $m['rating'] !== NULL ? (float) $m['rating'] : NULL,
                'votes'       => (int) $m['votes'],
                'url'         => '/3d/'.(string) $m['code'],
            ];
        }

        return ['count' => count($out), 'models' => $out];
    }

    public static function mcp_get(array $args)
    {
        $model = self::by_code((string) Arr::get($args, 'code', ''));

        if ($model === NULL OR $model['status'] !== 'published')
        {
            return ['error' => 'модель не найдена или не опубликована'];
        }

        $stl = $png = NULL;
        $revs = [];

        foreach (self::revisions((int) $model['id']) as $r)
        {
            $revs[] = [
                'rev_no'  => (int) $r['rev_no'],
                'status'  => (string) $r['status'],
                'summary' => (string) $r['summary'],
                'params'  => $r['params'] ? json_decode((string) $r['params'], TRUE) : NULL,
            ];

            foreach ($r['files'] as $f)
            {
                if ($f['kind'] === 'stl') $stl = '/3d/file/'.(int) $f['id'];
                if ($f['kind'] === 'png' AND $png === NULL) $png = '/3d/file/'.(int) $f['id'];
            }
        }

        return [
            'code'        => (string) $model['code'],
            'title'       => (string) $model['title'],
            'description' => (string) $model['description'],
            'url'         => '/3d/'.(string) $model['code'],
            'stl_url'     => $stl,
            'png_url'     => $png,
            'rating'      => self::rating((int) $model['id']),
            'revisions'   => array_slice(array_reverse($revs), 0, 5),
        ];
    }

    // ------------------------------------------------------------------
    // Ресурсы (затраты воркеров)
    // ------------------------------------------------------------------

    public static function usage_summary()
    {
        $by_phase = self::_db()->query(Database::SELECT,
            'SELECT phase, COUNT(*) AS calls, COALESCE(SUM(prompt_tokens), 0) AS pt,
                    COALESCE(SUM(completion_tokens), 0) AS ct, COALESCE(SUM(duration_ms), 0) AS ms
             FROM nj_570_usage GROUP BY phase ORDER BY phase', FALSE)->as_array();

        $by_model = self::_db()->query(Database::SELECT,
            'SELECT model, COUNT(*) AS calls, COALESCE(SUM(prompt_tokens), 0) AS pt,
                    COALESCE(SUM(completion_tokens), 0) AS ct
             FROM nj_570_usage
             GROUP BY model
             ORDER BY COALESCE(SUM(prompt_tokens), 0) + COALESCE(SUM(completion_tokens), 0) DESC
             LIMIT 15', FALSE)->as_array();

        $total = self::_db()->query(Database::SELECT,
            'SELECT COALESCE(SUM(prompt_tokens), 0) AS pt, COALESCE(SUM(completion_tokens), 0) AS ct,
                    COALESCE(SUM(duration_ms), 0) AS ms, COUNT(*) AS calls FROM nj_570_usage', FALSE)->current();

        return ['by_phase' => $by_phase, 'by_model' => $by_model, 'total' => (array) $total];
    }

    public static function usage_recent($limit = 30)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT u.*, m.code AS model_code FROM nj_570_usage u
             LEFT JOIN nj_570_models m ON m.id = u.model_id
             ORDER BY u.id DESC LIMIT '.(int) $limit, FALSE)->as_array();
    }

    // ------------------------------------------------------------------
    // Файлы (загрузка воркером, раздача контроллером)
    // ------------------------------------------------------------------

    public static function store_file($revision_id, $kind, $bytes)
    {
        $max = (int) self::_cfg('max_file_bytes', 62914560);

        if ($bytes === '' OR strlen($bytes) > $max)
        {
            return ['error' => 'Пустой или слишком большой файл'];
        }

        $rev = self::revision_by_id($revision_id);

        if ( ! $rev)
        {
            return ['error' => 'Ревизия не найдена'];
        }

        $ext = ($kind === 'stl') ? 'stl' : 'png';
        $dir = self::storage_dir().DIRECTORY_SEPARATOR.date('Ym');

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        $name = bin2hex(random_bytes(10)).'.'.$ext;
        file_put_contents($dir.DIRECTORY_SEPARATOR.$name, $bytes);
        @chmod($dir.DIRECTORY_SEPARATOR.$name, 0664);

        $rel = date('Ym').'/'.$name;
        $db  = self::_db();

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_570_files (revision_id, kind, path, size)
             VALUES ('.(int) $revision_id.', '.$db->quote($kind).', '.$db->quote($rel).', '.strlen($bytes).')
             RETURNING id', FALSE)->current();

        return ['id' => (int) $row['id']];
    }

    public static function file_path($rel)
    {
        $rel = str_replace(['..', "\0"], '', (string) $rel);

        return self::storage_dir().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }
}
