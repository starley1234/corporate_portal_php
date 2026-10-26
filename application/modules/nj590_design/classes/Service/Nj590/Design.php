<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис «Дизайн страницы» (NJ-590, /design).
 *
 * Поток: прогон (URL + пожелания + N) → задача воркера nj120 (канал
 * nj590.design) → воркер скачивает страницу, LLM разбирает её и пишет
 * N однофайловых HTML-вариантов улучшенного дизайна, выгружает их через
 * /design/api/upload; пользователь выбирает вариант (nj_590_runs.chosen).
 *
 * Затраты LLM — воркер возвращает в job.result.usage, reconcile
 * дозаписывает в nj_590_runs.usage.
 */
class Service_Nj590_Design {

    const CHANNEL_DESIGN = 'nj590.design';

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
            $cfg = (array) Kohana::$config->load('nj590_design');
        }

        return Arr::path($cfg, $key, $default);
    }

    /**
     * Страховка: миграции nj111 применяются на проде вручную, поэтому модуль
     * сам создаёт свои таблицы (идемпотентный CREATE TABLE IF NOT EXISTS,
     * тот же DDL, что в pgMigration_Nj_590_001). Ошибка не валит страницу.
     */
    protected static $_tables_ensured = FALSE;

    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        // каждое утверждение — отдельным запросом: одна упавшая команда
        // не мешает остальным, а в лог уходит текст конкретной ошибки
        $stmts = [];
        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_590_runs" (
    "id"             BIGSERIAL PRIMARY KEY,
    "code"           VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"        BIGINT       NOT NULL,
    "url"            TEXT         NOT NULL,
    "host"           VARCHAR(200) NOT NULL DEFAULT '',
    "title"          VARCHAR(300) NOT NULL DEFAULT '',
    "wishes"         TEXT         NULL,
    "variants_count" SMALLINT     NOT NULL DEFAULT 3,
    "status"         VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"          TEXT         NULL,
    "progress"       VARCHAR(300) NOT NULL DEFAULT '',
    "summary"        TEXT         NULL,
    "chosen"         SMALLINT     NOT NULL DEFAULT 0,
    "job_id"         BIGINT       NULL,
    "usage"          JSONB        NULL,
    "deleted_at"     TIMESTAMPTZ  NULL,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"    TIMESTAMPTZ  NULL
);
SQL;
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_590_runs_user_idx" ON "nj_590_runs" ("user_id", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_590_runs_status_idx" ON "nj_590_runs" ("status", "id" DESC)';
        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_590_variants" (
    "id"         BIGSERIAL PRIMARY KEY,
    "run_id"     BIGINT       NOT NULL,
    "no"         SMALLINT     NOT NULL,
    "title"      VARCHAR(300) NOT NULL DEFAULT '',
    "concept"    TEXT         NULL,
    "path"       VARCHAR(120) NOT NULL DEFAULT '',
    "size"       BIGINT       NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_590_variants_run_no_key" UNIQUE ("run_id", "no")
);
SQL;
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_590_variants_run_idx" ON "nj_590_variants" ("run_id", "no")';
        $stmts[] = 'ALTER TABLE "nj_590_runs" ADD COLUMN IF NOT EXISTS "model" VARCHAR(120) NULL';
        $stmts[] = 'ALTER TABLE "nj_590_runs" ADD COLUMN IF NOT EXISTS "model_used" VARCHAR(120) NULL';

        foreach ($stmts as $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj590: ensure_tables: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Создание прогона
    // ------------------------------------------------------------------

    public static function create($user_id, $url, $wishes, $variants, $model = '')
    {
        self::ensure_tables();

        $url    = trim((string) $url);
        $wishes = trim((string) $wishes);
        $max_n  = (int) self::_cfg('max_variants', 5);
        $n      = (int) $variants ?: (int) self::_cfg('default_variants', 3);
        $n      = max(1, min($max_n, $n));

        if ($wishes !== '' AND mb_strlen($wishes) > (int) self::_cfg('max_wishes_len', 4000))
        {
            $wishes = mb_substr($wishes, 0, (int) self::_cfg('max_wishes_len', 4000));
        }

        if ($url === '')
        {
            return ['error' => 'Укажите адрес страницы (URL) — он обязателен.'];
        }

        if (mb_strlen($url) > (int) self::_cfg('max_url_len', 2000))
        {
            return ['error' => 'Слишком длинный URL.'];
        }

        $p = @parse_url($url);

        if ($p === FALSE OR empty($p['host']) OR ! in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], TRUE))
        {
            return ['error' => 'Похоже, это не адрес страницы. Нужна ссылка вида https://example.com/page'];
        }

        // нормализуем: без хвостовых пробелов, scheme в нижний регистр
        $url  = strtolower((string) $p['scheme']).'://'.(string) $p['host']
              . (isset($p['port']) ? ':'.$p['port'] : '')
              . (string) ($p['path'] ?? '/')
              . (isset($p['query']) ? '?'.$p['query'] : '');

        $code = self::_gen_code();
        $host = mb_substr((string) $p['host'], 0, 200);

        try
        {
            $res = DB::query(Database::INSERT,
                'INSERT INTO nj_590_runs (code, user_id, url, host, wishes, variants_count, model, status)
                 VALUES (:code, :user_id, :url, :host, :wishes, :n, :model, :status)
                 RETURNING id', FALSE)
                ->parameters([
                    ':code'    => $code,
                    ':user_id' => (int) $user_id,
                    ':url'     => $url,
                    ':host'    => $host,
                    ':wishes'  => $wishes !== '' ? $wishes : NULL,
                    ':n'       => $n,
                    ':model'   => trim((string) $model) !== '' ? mb_substr(trim((string) $model), 0, 120) : NULL,
                    ':status'  => 'new',
                ])
                ->execute();
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj590: create: :e', [':e' => $e->getMessage()]);

            // причина — сразу в тексте ошибки, чтобы не гадать по логам
            return ['error' => 'Не удалось создать прогон: '.mb_substr((string) $e->getMessage(), 0, 300)];
        }

        $run_id = (int) (is_array($res) ? reset($res) : $res);
        $payload = [
            'run_id' => $run_id,
            'code'   => $code,
            'url'    => $url,
            'wishes' => $wishes,
            'n'      => $n,
        ];

        // админский выбор модели (пусто — воркер возьмёт модель из env)
        if (trim((string) $model) !== '')
        {
            $payload['model'] = mb_substr(trim((string) $model), 0, 120);
        }

        $job_id = self::_dispatch(self::CHANNEL_DESIGN, $run_id, $payload);

        if ($job_id <= 0)
        {
            return ['error' => 'Не удалось поставить задачу воркеру — попробуйте ещё раз.'];
        }

        return ['code' => $code, 'id' => $run_id];
    }

    protected static function _gen_code()
    {
        $prefix = (string) self::_cfg('code_prefix', 'dsn');
        $bytes  = (int) self::_cfg('code_bytes', 4);

        return $prefix.bin2hex(random_bytes(max(2, $bytes)));
    }

    /** Поставить задачу в очередь (created_by = владелец → ему придёт подписка erp217). */
    protected static function _dispatch($channel, $run_id, array $payload)
    {
        $user = self::_db()->query(Database::SELECT,
            'SELECT user_id FROM nj_590_runs WHERE id = '.(int) $run_id, FALSE)->current();

        try
        {
            $job = Service_Nj120_JobQueue::push($channel, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj590',
                'created_by'   => $user ? (int) $user['user_id'] : NULL,
                // 30 мин: N вариантов по 1 LLM-вызову на каждый + скачивание страницы
                'timeout_sec'  => 1800,
            ]);

            self::_db()->query(Database::UPDATE,
                "UPDATE nj_590_runs SET job_id = ".(int) $job.", status = 'running', updated_at = now()
                 WHERE id = ".(int) $run_id);

            return (int) $job;
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj590: не удалось поставить задачу: :e', [':e' => $e->getMessage()]);

            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function by_id($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_590_runs WHERE id = '.(int) $id." AND deleted_at IS NULL", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function by_code($code)
    {
        self::ensure_tables();

        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_590_runs WHERE code = '.$db->quote((string) $code)
            ." AND deleted_at IS NULL", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function list_user($user_id, $limit = NULL)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT r.*, (SELECT count(*) FROM nj_590_variants v WHERE v.run_id = r.id AND v.path <> \'\') AS variants_ready
             FROM nj_590_runs r
             WHERE r.user_id = '.(int) $user_id.' AND r.deleted_at IS NULL
             ORDER BY r.id DESC LIMIT '.(int) ($limit ?: self::_cfg('list_limit', 50)), FALSE)->as_array();
    }

    public static function list_all($limit = NULL)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT r.*, (SELECT count(*) FROM nj_590_variants v WHERE v.run_id = r.id AND v.path <> \'\') AS variants_ready
             FROM nj_590_runs r
             WHERE r.deleted_at IS NULL
             ORDER BY r.id DESC LIMIT '.(int) ($limit ?: self::_cfg('admin_limit', 100)), FALSE)->as_array();
    }

    public static function variants($run_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_590_variants WHERE run_id = '.(int) $run_id.' ORDER BY no', FALSE)->as_array();
    }

    public static function variant($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_590_variants WHERE id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function storage_dir()
    {
        $dir = (string) self::_cfg('storage_dir', 'storage/nj590');

        // абсолютный путь — хранилище модуля ВНЕ public_html (у каждого модуля
        // своя папка, файлы разных модулей не смешиваются). Каталог создаёт
        // bin/deploy.sh при каждом обновлении (пинок).
        if ($dir !== '' AND $dir[0] === DIRECTORY_SEPARATOR)
        {
            return rtrim($dir, '/\\');
        }

        return DOCROOT.$dir;
    }

    /** Лимиты для UI (форма /design). */
    public static function limits()
    {
        return [
            'max_variants'     => (int) self::_cfg('max_variants', 5),
            'default_variants' => (int) self::_cfg('default_variants', 3),
            'max_wishes_len'   => (int) self::_cfg('max_wishes_len', 4000),
        ];
    }

    public static function file_path($rel)
    {
        $rel = str_replace(['..', "\0"], '', (string) $rel);

        return self::storage_dir().DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rel, '/'));
    }

    // ------------------------------------------------------------------
    // Воркер: файлы вариантов, прогресс, разбор страницы
    // ------------------------------------------------------------------

    /**
     * Сохранить HTML-вариант (вызов воркером через /design/api/upload).
     * Вариант с тем же номером заменяется целиком (строка + файл).
     */
    public static function add_variant($run_id, $no, $title, $concept, $html, $model_used = NULL)
    {
        self::ensure_tables();

        $run = self::by_id((int) $run_id);

        if ($run === NULL)
        {
            return ['error' => 'Прогон не найден'];
        }

        // фактическая модель (воркер сообщает, чем РЕАЛЬНО отвечал LLM):
        // в UI видно, если она не совпала с запрошенной
        $model_used = trim((string) $model_used);

        if ($model_used !== '')
        {
            $dbu = self::_db();
            $dbu->query(Database::UPDATE, 'UPDATE nj_590_runs SET model_used = '
                .$dbu->quote(mb_substr($model_used, 0, 120)).', updated_at = now() WHERE id = '.(int) $run_id);
        }

        $no = (int) $no;

        if ($no < 1 OR $no > (int) self::_cfg('max_variants', 5))
        {
            return ['error' => 'Недопустимый номер варианта'];
        }

        $max = (int) self::_cfg('max_variant_bytes', 2097152);

        if ($html === '' OR strlen($html) > $max)
        {
            return ['error' => 'Пустой или слишком большой HTML-вариант'];
        }

        // страховка: вариант не должен нести исполняемый JS (в UI он в sandbox-iframe)
        $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html);
        $html = preg_replace('~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html);

        $dir = self::storage_dir().DIRECTORY_SEPARATOR.date('Ym');

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0775, TRUE);
        }

        $name = bin2hex(random_bytes(10)).'.html';

        // @: ошибку записи отдаём словами, а не 500-й (причина доедет до воркера)
        if (@file_put_contents($dir.DIRECTORY_SEPARATOR.$name, $html) === FALSE)
        {
            return ['error' => 'Не удалось записать файл варианта в '.$dir
                .' — проверьте, что каталог существует и доступен пользователю php-fpm'];
        }

        @chmod($dir.DIRECTORY_SEPARATOR.$name, 0664);

        $rel = date('Ym').'/'.$name;
        $db  = self::_db();

        foreach ($db->query(Database::SELECT,
            'SELECT id, path FROM nj_590_variants WHERE run_id = '.(int) $run_id.' AND no = '.$no, FALSE)->as_array() as $old)
        {
            @unlink(self::file_path((string) $old['path']));
            $db->query(Database::DELETE, 'DELETE FROM nj_590_variants WHERE id = '.(int) $old['id']);
        }

        // ВНИМАНИЕ: Database::query() драйвера PostgreSQL выполняет запрос САМ
        // и возвращает массив [id, rows] — ->execute() у массива нет и его вызов
        // ронял PHP в Kohana-500 (бой 2026-09-20, «сайт не принял выгрузку»).
        $id = $db->query(Database::INSERT,
            'INSERT INTO nj_590_variants (run_id, no, title, concept, path, size)
             VALUES ('.(int) $run_id.', '.$no.', '.$db->quote(mb_substr((string) $title, 0, 300)).', '
                    .$db->quote(mb_substr((string) $concept, 0, 4000)).', '.$db->quote($rel).', '.strlen($html).')
             RETURNING id', FALSE);

        return ['id' => (int) (is_array($id) ? reset($id) : $id)];
    }

    /** Промежуточный прогресс (вызов воркером через /design/api/progress). */
    public static function set_progress($run_id, $phase, array $fields = [])
    {
        $db  = self::_db();
        $set = "progress = ".$db->quote(mb_substr((string) $phase, 0, 300));

        if ( ! empty($fields['page_title']))
        {
            $set .= ", title = ".$db->quote(mb_substr((string) $fields['page_title'], 0, 300));
        }

        if ( ! empty($fields['page_summary']))
        {
            $set .= ", summary = ".$db->quote(mb_substr((string) $fields['page_summary'], 0, 6000));
        }

        try
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_590_runs SET '.$set.', status = CASE WHEN status IN (\'new\',\'running\') THEN \'running\' ELSE status END,
                 updated_at = now() WHERE id = '.(int) $run_id);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj590: set_progress: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Прогон не удался (воркер или reconcile). */
    public static function fail($run_id, $error)
    {
        $db = self::_db();

        try
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_590_runs SET status = 'error', error = ".$db->quote(mb_substr((string) $error, 0, 900))
                .", finished_at = now(), updated_at = now() WHERE id = ".(int) $run_id." AND status IN ('new','running')");
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj590: fail: :e', [':e' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Выбор варианта
    // ------------------------------------------------------------------

    public static function pick($run_id, $no)
    {
        $db  = self::_db();
        $no  = (int) $no;

        $ok = (bool) $db->query(Database::SELECT,
            'SELECT 1 AS ok FROM nj_590_variants WHERE run_id = '.(int) $run_id.' AND no = '.$no, FALSE)->current();

        if ( ! $ok)
        {
            return FALSE;
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_590_runs SET chosen = '.$no.', updated_at = now() WHERE id = '.(int) $run_id);

        return TRUE;
    }

    /** Остановить прогон: pending-задачу снимаем, в работе — воркер увидит cancel-флаг. */
    public static function cancel_run($run_id)
    {
        $run = self::by_id((int) $run_id);

        if ($run === NULL OR ! in_array($run['status'], ['new', 'running'], TRUE))
        {
            return FALSE;
        }

        if ( ! empty($run['job_id']))
        {
            try
            {
                // force: без него processing-задачу cancel не снимает, и кнопка
                // «Остановить» в статус-ряде оставалась без последствий
                Service_Nj120_JobQueue::instance()->cancel((int) $run['job_id'], TRUE);
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj590: остановка прогона: :e', [':e' => $e->getMessage()]);
            }
        }

        self::fail((int) $run_id, 'Отменено пользователем');

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Reconcile (лениво при чтении статуса)
    // ------------------------------------------------------------------

    public static function reconcile(array $run)
    {
        if ( ! in_array($run['status'], ['new', 'running'], TRUE) OR empty($run['job_id']))
        {
            return $run;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $run['job_id']);

        if ($job === NULL)
        {
            return $run;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode((string) $res, TRUE);
            $res = is_array($res) ? $res : [];

            $title_set   = ( ! empty($res['page_title']) ? ", title = ".$db->quote(mb_substr((string) $res['page_title'], 0, 300)) : '');
            $summary_set = ( ! empty($res['page_summary']) ? ", summary = ".$db->quote(mb_substr((string) $res['page_summary'], 0, 6000)) : '');
            $usage_set   = ", usage = ".(isset($res['usage']) ? $db->quote(json_encode($res['usage'], JSON_UNESCAPED_UNICODE)).'::jsonb' : 'usage');

            $ready = (int) $db->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_590_variants WHERE run_id = '.(int) $run['id']." AND path <> ''", FALSE)
                ->current()['c'];

            if ($ready > 0)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_590_runs SET status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()"
                    .$title_set.$summary_set.$usage_set
                    .' WHERE id = '.(int) $run['id']);
            }
            else
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_590_runs SET error = 'Воркер закончил, но ни один вариант не сохранился — попробуйте ещё раз', finished_at = now(), updated_at = now()"
                    .$title_set.$summary_set.$usage_set
                    .' WHERE id = '.(int) $run['id']);
            }
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_CANCELLED)
        {
            // Прогон сняли («Остановить», авто-стоп молчащего воркера, свипер) —
            // карточка обязана выйти из «Идёт сборка», а не ждать таймаута.
            self::fail((int) $run['id'], 'Прогон остановлен — запустите заново');
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            self::fail((int) $run['id'], (string) $job['error']);
        }
        elseif (in_array($job['status'], [Service_Nj120_JobQueue::STATUS_PENDING, Service_Nj120_JobQueue::STATUS_PROCESSING], TRUE))
        {
            // Зависшая задача (воркер убит рестартом и т.п.): держать «в работе»
            // вечно нельзя — по истечении timeout_sec + 5 минут помечаем ошибкой.
            $started = $job['claimed_at'] ?: $job['created_at'];
            $limit   = (int) ($job['timeout_sec'] ?: 1800) + 300;

            if ($started AND (time() - strtotime((string) $started)) > $limit)
            {
                self::fail((int) $run['id'], 'Задача зависла (воркер недоступен или перезапущен) — попробуйте создать прогон заново');
            }
        }

        return self::by_id((int) $run['id']);
    }

    // ------------------------------------------------------------------
    // Управление
    // ------------------------------------------------------------------

    public static function remove($run_id)
    {
        $run = self::by_id((int) $run_id);

        if ($run === NULL)
        {
            return FALSE;
        }

        if ( ! empty($run['job_id']))
        {
            try
            {
                Service_Nj120_JobQueue::instance()->cancel((int) $run['job_id']);
            }
            catch (Exception $e)
            {
                // отмена не критична: воркер увидит статус прогона
            }
        }

        $db = self::_db();
        $db->query(Database::UPDATE,
            'UPDATE nj_590_runs SET deleted_at = now(), updated_at = now() WHERE id = '.(int) $run_id);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // MCP
    // ------------------------------------------------------------------

    public static function mcp_run(array $args)
    {
        $code = (string) ($args['code'] ?? '');
        $run  = $code !== '' ? self::by_code($code) : NULL;

        if ($run === NULL)
        {
            return ['error' => 'not_found'];
        }

        $status_ru = ['new' => 'в очереди', 'running' => 'работает', 'done' => 'готово', 'error' => 'ошибка'];
        $variants  = [];

        foreach (self::variants((int) $run['id']) as $v)
        {
            $variants[] = [
                'no'      => (int) $v['no'],
                'title'   => (string) $v['title'],
                'concept' => mb_substr((string) $v['concept'], 0, 500),
                'url'     => ((string) $v['path'] !== '') ? '/design/'.$run['code'].'/file/'.(int) $v['id'] : '',
            ];
        }

        return [
            'code'           => (string) $run['code'],
            'url'            => (string) $run['url'],
            'status'         => (string) $run['status'],
            'status_ru'      => (string) ($status_ru[$run['status']] ?? $run['status']),
            'error'          => (string) $run['error'],
            'progress'       => (string) $run['progress'],
            'wishes'         => (string) $run['wishes'],
            'page_title'     => (string) $run['title'],
            'page_summary'   => mb_substr((string) $run['summary'], 0, 800),
            'variants_count' => (int) $run['variants_count'],
            'variants_ready' => count($variants),
            'chosen'         => (int) $run['chosen'],
            'variants'       => $variants,
            'created_at'     => (string) $run['created_at'],
        ];
    }
}
