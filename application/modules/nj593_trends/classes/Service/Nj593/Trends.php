<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сервис «Тренды GitHub» (nj593): обзоры свежих и интересных репозиториев.
 * Факты (звёзды, язык, даты) — из GitHub API на воркере; тексты — LLM.
 * Страница обзора публичная (дайджест), создание через сайт — только для администратора.
 */
class Service_Nj593_Trends {

    const CHANNEL_TRENDS = 'nj593.trends';

    protected static $_db = NULL;
    protected static $_tables_ensured = FALSE;
    // Есть ли в таблице обзоров колонка published (миграция 003 могла ещё не
    // накатиться): NULL — «ещё не спрашивали».
    protected static $_has_published = NULL;

    protected static function _db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('nj593_trends');
        }

        return Arr::path($cfg, $key, $default);
    }

    // ------------------------------------------------------------------
    // Страховка таблиц: КАЖДЫЙ запрос реально исполняется (execute()!)
    // ------------------------------------------------------------------

    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        // Схема ставится миграцией; DDL на каждом API-вызове блокирует
        // параллельные пинки и создаёт ложное ощущение «зависшего» воркера.
        if (trim((string) getenv('NJ593_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        $stmts = [];
        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_593_reviews" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "rdate"       DATE         NOT NULL DEFAULT CURRENT_DATE,
    "period_days" SMALLINT     NOT NULL DEFAULT 1,
    "min_stars"   INTEGER      NOT NULL DEFAULT 50,
    "language"    VARCHAR(40)  NOT NULL DEFAULT '',
    "item_count"  SMALLINT     NOT NULL DEFAULT 8,
    "focus"       VARCHAR(500) NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "title"       VARCHAR(300) NOT NULL DEFAULT '',
    "summary"     TEXT         NULL,
    "model"       VARCHAR(120) NULL,
    "model_used"  VARCHAR(120) NULL,
    "job_id"      BIGINT       NULL,
    "usage"       JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
)
SQL;
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_593_reviews_user_idx" ON "nj_593_reviews" ("user_id", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_593_reviews_status_idx" ON "nj_593_reviews" ("status", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_593_reviews_date_idx" ON "nj_593_reviews" ("rdate" DESC)';
        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_593_items" (
    "id"            BIGSERIAL PRIMARY KEY,
    "review_id"     BIGINT       NOT NULL REFERENCES "nj_593_reviews"("id") ON DELETE CASCADE,
    "rank"          SMALLINT     NOT NULL,
    "full_name"     VARCHAR(200) NOT NULL,
    "url"           VARCHAR(300) NOT NULL DEFAULT '',
    "description"   TEXT         NULL,
    "language"      VARCHAR(40)  NOT NULL DEFAULT '',
    "stars"         INTEGER      NOT NULL DEFAULT 0,
    "forks"         INTEGER      NOT NULL DEFAULT 0,
    "gh_created_at" TIMESTAMPTZ  NULL,
    "topics"        VARCHAR(500) NOT NULL DEFAULT '',
    "title"         VARCHAR(300) NOT NULL DEFAULT '',
    "what"          TEXT         NULL,
    "why"           TEXT         NULL,
    "use_for"       TEXT         NULL,
    "score"         SMALLINT     NOT NULL DEFAULT 0,
    "created_at"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_593_items_review_rank_key" UNIQUE ("review_id", "rank")
)
SQL;
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_593_items_review_idx" ON "nj_593_items" ("review_id", "rank")';
        // 002: колонки «польза обзора» (apply на позицию, takeaways на обзор).
        // Миграция ставит их штатно; здесь — на случай автосоздания без миграций.
        $stmts[] = 'ALTER TABLE "nj_593_items" ADD COLUMN IF NOT EXISTS "apply" TEXT NOT NULL DEFAULT \'\'';
        $stmts[] = 'ALTER TABLE "nj_593_reviews" ADD COLUMN IF NOT EXISTS "takeaways" TEXT NULL';
        // 003: обзор публикуется по умолчанию; снять с публикации может владелец.
        $stmts[] = 'ALTER TABLE "nj_593_reviews" ADD COLUMN IF NOT EXISTS "published" BOOLEAN NOT NULL DEFAULT TRUE';

        foreach ($stmts as $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Exception $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj593: ensure_tables: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Создание обзора
    // ------------------------------------------------------------------

    public static function create($user_id, $rdate, $period_days, $item_count, $language, $min_stars, $focus, $model = '')
    {
        self::ensure_tables();

        $max_n  = (int) self::_cfg('max_items', 12);
        $count  = (int) $item_count ?: (int) self::_cfg('default_items', 8);
        $count  = max(3, min($max_n, $count));

        $period = (int) $period_days ?: (int) self::_cfg('default_period', 1);
        $period = max(1, min((int) self::_cfg('max_period', 7), $period));

        $min_stars = (int) $min_stars ?: 50;
        $min_stars = max((int) self::_cfg('min_stars_min', 10), min(100000, $min_stars));

        $language = trim((string) $language);
        $langs    = (array) self::_cfg('languages', ['']);

        if ( ! in_array($language, $langs, TRUE))
        {
            $language = '';
        }

        $focus = trim((string) $focus);

        if (mb_strlen($focus) > (int) self::_cfg('max_focus_len', 500))
        {
            $focus = mb_substr($focus, 0, (int) self::_cfg('max_focus_len', 500));
        }

        $rdate = trim((string) $rdate);

        if ($rdate === '')
        {
            $rdate = date('Y-m-d');
        }
        elseif ( ! preg_match('~^\d{4}-\d{2}-\d{2}$~', $rdate))
        {
            return ['error' => 'Дата должна быть в формате ГГГГ-ММ-ДД.'];
        }

        $code = self::_gen_code();

        try
        {
            $res = DB::query(Database::INSERT,
                'INSERT INTO nj_593_reviews (code, user_id, rdate, period_days, min_stars, language, item_count, focus, model, status)
                 VALUES (:code, :user_id, :rdate::date, :period, :stars, :language, :count, :focus, :model, :status)
                 RETURNING id')
                ->parameters([
                    ':code'     => $code,
                    ':user_id'  => (int) $user_id,
                    ':rdate'    => $rdate,
                    ':period'   => $period,
                    ':stars'    => $min_stars,
                    ':language' => $language,
                    ':count'    => $count,
                    ':focus'    => $focus !== '' ? $focus : NULL,
                    ':model'    => trim((string) $model) !== '' ? mb_substr(trim((string) $model), 0, 120) : NULL,
                    ':status'   => 'new',
                ])
                ->execute();
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: create: :e', [':e' => $e->getMessage()]);

            // причина — сразу в тексте ошибки, чтобы не гадать по логам
            return ['error' => 'Не удалось создать обзор: '.mb_substr((string) $e->getMessage(), 0, 300)];
        }

        $review_id = (int) (is_array($res) ? reset($res) : $res);

        $payload = [
            'review_id' => $review_id,
            'code'      => $code,
            'date'      => $rdate,
            'period'    => $period,
            'count'     => $count,
            'language'  => $language,
            'min_stars' => $min_stars,
            'focus'     => $focus,
        ];

        if (trim((string) $model) !== '')
        {
            $payload['model'] = mb_substr(trim((string) $model), 0, 120);
        }

        $job_id = self::_dispatch(self::CHANNEL_TRENDS, $review_id, $payload);

        if ($job_id <= 0)
        {
            return ['error' => 'Не удалось поставить задачу воркеру — попробуйте ещё раз.'];
        }

        return ['code' => $code, 'id' => $review_id];
    }

    protected static function _gen_code()
    {
        $prefix = (string) self::_cfg('code_prefix', 'trd');
        $bytes  = (int) self::_cfg('code_bytes', 4);

        return $prefix.bin2hex(random_bytes(max(2, $bytes)));
    }

    /** Поставить задачу в очередь (created_by = владелец → придёт подписка erp217). */
    protected static function _dispatch($channel, $review_id, array $payload)
    {
        $user = self::_db()->query(Database::SELECT,
            'SELECT user_id FROM nj_593_reviews WHERE id = '.(int) $review_id, FALSE)->current();

        try
        {
            $job = Service_Nj120_JobQueue::push($channel, $payload, [
                'max_attempts' => 2,
                'module'       => 'nj593',
                'created_by'   => $user ? (int) $user['user_id'] : NULL,
                // поиск + README + LLM: обычно 2-4 минуты
                'timeout_sec'  => 1800,
            ]);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: dispatch: :e', [':e' => $e->getMessage()]);

            return 0;
        }

        self::_db()->query(Database::UPDATE,
            "UPDATE nj_593_reviews SET job_id = ".(int) $job.", status = 'running', updated_at = now()
             WHERE id = ".(int) $review_id);

        return (int) $job;
    }

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    public static function by_id($review_id)
    {
        self::ensure_tables();

        // current() у пустого результата возвращает false — нормализуем в NULL
        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_593_reviews WHERE id = '.(int) $review_id, FALSE)->current() ?: NULL;
    }

    public static function by_code($code)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            "SELECT * FROM nj_593_reviews WHERE code = ".self::_db()->quote((string) $code),
            FALSE)->current() ?: NULL;
    }

    public static function list_user($user_id, $limit = NULL)
    {
        self::ensure_tables();
        $limit = (int) ($limit ?: self::_cfg('list_limit', 30));

        return self::_db()->query(Database::SELECT,
            'SELECT r.*, (SELECT count(*) FROM nj_593_items i WHERE i.review_id = r.id) AS items_ready
             FROM nj_593_reviews r WHERE r.user_id = '.(int) $user_id.'
             ORDER BY r.id DESC LIMIT '.$limit, FALSE)->as_array();
    }

    public static function list_admin($limit = NULL)
    {
        self::ensure_tables();
        $limit = (int) ($limit ?: self::_cfg('admin_limit', 100));

        return self::_db()->query(Database::SELECT,
            'SELECT r.*, (SELECT count(*) FROM nj_593_items i WHERE i.review_id = r.id) AS items_ready
             FROM nj_593_reviews r ORDER BY r.id DESC LIMIT '.$limit, FALSE)->as_array();
    }

    // ------------------------------------------------------------------
    // Поиск и постраничный вывод (страница «Мои обзоры» и админка)
    // ------------------------------------------------------------------

    /**
     * Условие поиска: код, название, фокус и — важно — названия позиций.
     * Владелец ищет «где был этот репозиторий», а не только по коду обзора.
     */
    protected static function _search_where($q)
    {
        $q = trim((string) $q);

        if ($q === '')
        {
            return '1 = 1';
        }

        // LIKE-метасимволы обезвреживаем (пользователь ищет словами, не шаблонами)
        $needle = '%'.str_replace(['%', '_', '\\'], ' ', $q).'%';
        $like = self::_db()->quote($needle);

        return '(r.code ILIKE '.$like.' OR r.title ILIKE '.$like.' OR r.focus ILIKE '.$like
            .' OR EXISTS (SELECT 1 FROM nj_593_items i WHERE i.review_id = r.id'
            .' AND (i.full_name ILIKE '.$like.' OR i.title ILIKE '.$like.')))';
    }

    /** Сколько обзоров пользователя попадает под поиск. */
    public static function count_user($user_id, $q = '')
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_593_reviews r
             WHERE r.user_id = '.(int) $user_id.' AND '.self::_search_where($q), FALSE)->current();

        return (int) ($row ? $row['c'] : 0);
    }

    /** Сколько обзоров всего попадает под поиск (админка). */
    public static function count_admin($q = '')
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_593_reviews r WHERE '.self::_search_where($q), FALSE)->current();

        return (int) ($row ? $row['c'] : 0);
    }

    /** Сводка для шапки админки: всего / готово / идут / с ошибкой. */
    public static function stats_admin()
    {
        self::ensure_tables();

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) AS total,
                        count(*) FILTER (WHERE status = 'done') AS done,
                        count(*) FILTER (WHERE status IN ('new', 'running')) AS live,
                        count(*) FILTER (WHERE status = 'error') AS failed
                 FROM nj_593_reviews", FALSE)->current();
        }
        catch (Throwable $e)
        {
            // FILTER требует PostgreSQL 9.4+ — на всякий случай простой подсчёт
            $row = self::_db()->query(Database::SELECT,
                'SELECT count(*) AS total, 0 AS done, 0 AS live, 0 AS failed
                 FROM nj_593_reviews', FALSE)->current();
        }

        return [
            'total'  => (int) ($row['total'] ?? 0),
            'done'   => (int) ($row['done'] ?? 0),
            'live'   => (int) ($row['live'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /** Страница «Мои обзоры» с поиском. */
    public static function search_user($user_id, $q = '', $page = 1, $per = 20)
    {
        self::ensure_tables();
        $per = max(5, min(100, (int) $per));
        $offset = max(0, ((int) $page - 1) * $per);

        return self::_db()->query(Database::SELECT,
            'SELECT r.*, (SELECT count(*) FROM nj_593_items i WHERE i.review_id = r.id) AS items_ready
             FROM nj_593_reviews r
             WHERE r.user_id = '.(int) $user_id.' AND '.self::_search_where($q).'
             ORDER BY r.id DESC LIMIT '.$per.' OFFSET '.$offset, FALSE)->as_array();
    }

    /** Страница «Все обзоры» в админке с поиском. */
    public static function search_admin($q = '', $page = 1, $per = 50)
    {
        self::ensure_tables();
        $per = max(5, min(200, (int) $per));
        $offset = max(0, ((int) $page - 1) * $per);

        return self::_db()->query(Database::SELECT,
            'SELECT r.*, (SELECT count(*) FROM nj_593_items i WHERE i.review_id = r.id) AS items_ready
             FROM nj_593_reviews r
             WHERE '.self::_search_where($q).'
             ORDER BY r.id DESC LIMIT '.$per.' OFFSET '.$offset, FALSE)->as_array();
    }

    // ------------------------------------------------------------------
    // Публикация и открытый каталог обзоров (владелец, 08.10.2026)
    //
    // «Отдельная страница каталога опубликованных обзоров — для людей и СЕО:
    // читаемый список, свежие сверху, пагинация, поиск. Обзор публикуется по
    // умолчанию, но я могу снять его с публикации».
    // ------------------------------------------------------------------

    /**
     * Есть ли колонка published.
     *
     * Код и схема едут на прод разными шагами: страница каталога не должна
     * падать 500-й, пока миграция 003 не накатана. Отсутствие колонки читается
     * как «всё опубликовано» — это же и умолчание самой колонки.
     */
    protected static function _has_published()
    {
        if (self::$_has_published !== NULL)
        {
            return self::$_has_published;
        }

        try
        {
            self::ensure_tables();

            $row = self::_db()->query(Database::SELECT,
                "SELECT 1 AS has FROM information_schema.columns
                  WHERE table_name = 'nj_593_reviews' AND column_name = 'published'", FALSE)->current();

            self::$_has_published = ($row !== NULL AND $row !== FALSE);
        }
        catch (Throwable $e)
        {
            self::$_has_published = FALSE;
        }

        return self::$_has_published;
    }

    /**
     * PostgreSQL отдаёт BOOLEAN строками 't'/'f', а (bool)'f' в PHP — это TRUE
     * (непустая строка). Все булевы значения из БД прогоняем здесь.
     */
    public static function as_bool($value)
    {
        return $value === TRUE OR $value === 't' OR $value === 'true' OR $value === 1 OR $value === '1';
    }

    /** Опубликован ли обзор. Нет колонки или нет ключа — считаем опубликованным. */
    public static function is_published($review)
    {
        $review = (array) $review;

        if ( ! array_key_exists('published', $review) OR $review['published'] === NULL)
        {
            return TRUE;
        }

        return self::as_bool($review['published']);
    }

    /** Единая видимость страницы обзора и публичного API статуса. */
    public static function can_read(array $review, $is_admin = FALSE)
    {
        return $is_admin OR (Arr::get($review, 'status') === 'done' AND self::is_published($review));
    }

    /**
     * Снять обзор с публикации или вернуть его в каталог.
     *
     * Колонку добавляем лениво и только здесь — на явном действии владельца, а
     * не на каждом запросе (см. комментарий в ensure_tables про DDL).
     */
    public static function set_published($review_id, $published)
    {
        self::ensure_tables();

        if ( ! self::_has_published())
        {
            try
            {
                DB::query(NULL, 'ALTER TABLE "nj_593_reviews" ADD COLUMN IF NOT EXISTS "published" BOOLEAN NOT NULL DEFAULT TRUE')->execute();

                self::$_has_published = TRUE;
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::ERROR, 'nj593: колонка published не добавилась: :e', [':e' => $e->getMessage()]);

                return FALSE;
            }
        }

        try
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_593_reviews SET published = '.($published ? 'TRUE' : 'FALSE')
                .', updated_at = now() WHERE id = '.(int) $review_id, FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: публикация не переключилась: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        return TRUE;
    }

    /** Условие «обзор можно показать людям»: готовый, опубликованный и под поиск. */
    protected static function _catalog_where($q = '')
    {
        $published = self::_has_published() ? 'r.published IS TRUE' : 'TRUE';

        return "r.status = 'done' AND ".$published.' AND '.self::_search_where($q);
    }

    /** Сколько обзоров в открытом каталоге (он же счётчик для «Показано X–Y из Z»). */
    public static function count_public($q = '')
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_593_reviews r WHERE '.self::_catalog_where($q), FALSE)->current();

        return (int) ($row ? $row['c'] : 0);
    }

    /**
     * Страница открытого каталога: свежие сверху.
     *
     * Кроме обзора отдаём имена первых позиций: по одному названию обзора не
     * видно, что внутри, а «где был этот репозиторий» — самый частый вопрос к
     * каталогу (тем же вопросом живёт поиск по «Моим обзорам»).
     */
    public static function search_public($q = '', $page = 1, $per = 10)
    {
        self::ensure_tables();
        $per    = max(5, min(50, (int) $per));
        $offset = max(0, ((int) $page - 1) * $per);

        return self::_db()->query(Database::SELECT,
            "SELECT r.id, r.code, r.rdate, r.period_days, r.min_stars, r.language, r.item_count,
                    r.title, r.summary, r.takeaways, r.created_at,
                    (SELECT count(*) FROM nj_593_items i WHERE i.review_id = r.id) AS items_ready,
                    (SELECT string_agg(t.full_name, ' · ' ORDER BY t.rank)
                       FROM (SELECT i.full_name, i.rank FROM nj_593_items i
                              WHERE i.review_id = r.id ORDER BY i.rank LIMIT 3) t) AS top_names
               FROM nj_593_reviews r
              WHERE ".self::_catalog_where($q).'
              ORDER BY r.rdate DESC, r.id DESC LIMIT '.$per.' OFFSET '.$offset, FALSE)->as_array();
    }

    /** Последний опубликованный обзор — дайджест на главной странице модуля. */
    public static function latest_public()
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT, "
            SELECT r.id, r.code, r.rdate, r.period_days, r.item_count, r.title, r.summary,
                   (SELECT count(*) FROM nj_593_items i WHERE i.review_id = r.id) AS items_ready
              FROM nj_593_reviews r
             WHERE ".self::_catalog_where('').'
             ORDER BY r.rdate DESC, r.id DESC LIMIT 1', FALSE)->current() ?: NULL;
    }

    /** Есть ли обзор, созданный в последние $seconds (для расписания пиналки). */
    public static function has_recent($seconds)
    {
        try
        {
            self::ensure_tables();
            $r = self::_db()->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_593_reviews
                 WHERE created_at > now() - (( '.max(1, (int) $seconds).' ) * interval \'1 second\')',
                FALSE)->current();

            return $r AND (int) $r['c'] > 0;
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    /** id первого администратора (владелец автообзора по расписанию). */
    public static function first_admin_id()
    {
        try
        {
            $row = DB::select('u.id')
                ->from(['users', 'u'])
                ->join(['roles_users', 'ru'], 'INNER')->on('ru.user_id', '=', 'u.id')
                ->join(['roles', 'r'], 'INNER')->on('r.id', '=', 'ru.role_id')
                ->where('r.name', '=', 'admin')
                ->order_by('u.id')
                ->limit(1)
                ->execute()->current();

            return $row ? (int) $row['id'] : 0;
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    public static function items($review_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_593_items WHERE review_id = '.(int) $review_id.' ORDER BY rank', FALSE)->as_array();
    }

    // ------------------------------------------------------------------
    // Воркер: прогресс, позиции, завершение
    // ------------------------------------------------------------------

    public static function set_progress($review_id, $phase)
    {
        $db = self::_db();

        try
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_593_reviews SET progress = ".$db->quote(mb_substr((string) $phase, 0, 300))
                .", status = CASE WHEN status IN ('new','running') THEN 'running' ELSE status END
                , updated_at = now() WHERE id = ".(int) $review_id);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: progress: :e', [':e' => $e->getMessage()]);
        }

        return TRUE;
    }

    /** Одна позиция обзора (замена по rank); $item — факты + тексты ИИ. */
    /**
     * Собран ли обзор БЕЗ модели (эвристика по данным GitHub).
     *
     * Признак — в результате задачи (ai_offline, его ставит воркер, когда модель
     * молчала или ответила мусором). Нужен странице обзора: владелец должен
     * видеть, что разбор не модельный (владелец: «где включается эвристика?»).
     */
    public static function is_ai_offline(array $review)
    {
        if (empty($review['job_id']) OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return FALSE;
        }

        try
        {
            $job = Service_Nj120_JobQueue::instance()->get((int) $review['job_id']);

            if ( ! $job)
            {
                return FALSE;
            }

            $res = $job['result'];
            is_string($res) AND $res = json_decode($res, TRUE);

            return is_array($res) AND ! empty($res['ai_offline']);
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }

    public static function add_item($review_id, $rank, array $item, $model_used = NULL)
    {
        self::ensure_tables();

        $rank = (int) $rank;

        if ($rank < 1 OR $rank > (int) self::_cfg('max_items', 12) + 5)
        {
            return ['error' => 'Недопустимый номер позиции'];
        }

        $full = trim((string) Arr::get($item, 'full_name', ''));

        if ($full === '' OR mb_strlen($full) > 200)
        {
            return ['error' => 'Нужно full_name репозитория (до 200 знаков)'];
        }

        // Отменённый обзор больше НЕ принимает позиции: владелец нажал
        // «Прервать», и воркер мог узнать об этом с задержкой до минуты.
        // Уже сохранённые позиции остаются на странице, новые не добавляем.
        $cur = self::by_id((int) $review_id);

        if ($cur AND (string) $cur['status'] === 'cancelled')
        {
            return ['error' => 'Сборка остановлена вручную — новые позиции не принимаются'];
        }

        $model_used = trim((string) $model_used);

        if ($model_used !== '')
        {
            $dbu = self::_db();
            $dbu->query(Database::UPDATE, 'UPDATE nj_593_reviews SET model_used = '
                .$dbu->quote(mb_substr($model_used, 0, 120)).', updated_at = now() WHERE id = '.(int) $review_id);
        }

        $db = self::_db();
        $gh_created = trim((string) Arr::get($item, 'gh_created_at', ''));

        // позиция с тем же rank заменяется целиком
        $db->query(Database::DELETE,
            'DELETE FROM nj_593_items WHERE review_id = '.(int) $review_id.' AND rank = '.$rank);

        try
        {
            $db->query(Database::INSERT,
                'INSERT INTO nj_593_items
                 (review_id, rank, full_name, url, description, language, stars, forks, gh_created_at, topics, title, what, why, use_for, apply, score)
                 VALUES ('
                    .(int) $review_id.', '.$rank.', '
                    .$db->quote($full).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'url', 'https://github.com/'.$full)), 0, 300)).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'description', '')), 0, 1000)).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'language', '')), 0, 40)).', '
                    .(int) Arr::get($item, 'stars', 0).', '
                    .(int) Arr::get($item, 'forks', 0).', '
                    .($gh_created !== '' ? $db->quote($gh_created).'::timestamptz' : 'NULL').', '
                    .$db->quote(mb_substr(implode(',', array_slice((array) Arr::get($item, 'topics', []), 0, 8)), 0, 500)).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'title', '')), 0, 300)).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'what', '')), 0, 2000)).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'why', '')), 0, 2000)).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'use_for', '')), 0, 1000)).', '
                    .$db->quote(mb_substr(trim((string) Arr::get($item, 'apply', '')), 0, 1500)).', '
                    .max(0, min(100, (int) Arr::get($item, 'score', 0)))
                    .') RETURNING id', FALSE);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: add_item: :e', [':e' => $e->getMessage()]);

            return ['error' => 'Не удалось сохранить позицию: '.mb_substr((string) $e->getMessage(), 0, 300)];
        }

        return ['ok' => TRUE];
    }

    public static function complete($review_id, $title, $summary, $model_used = NULL, $takeaways = NULL)
    {
        // Идемпотентность отмены: если владелец прервал сборку, завершение из
        // воркера не должно возвращать обзор в 'done' (иначе кнопка «Прервать»
        // молча отменялась бы сама собой на финише).
        $cur = self::by_id((int) $review_id);

        if ($cur AND (string) $cur['status'] === 'cancelled')
        {
            return ['error' => 'Обзор остановлен вручную — завершение не принято'];
        }

        $db = self::_db();

        $model_used = trim((string) $model_used);
        $takeaways  = trim((string) $takeaways);

        try
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_593_reviews SET status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now(), "
                ."title = ".$db->quote(mb_substr(trim((string) $title), 0, 300)).", "
                ."summary = ".$db->quote(mb_substr(trim((string) $summary), 0, 4000))
                .($model_used !== '' ? ", model_used = ".$db->quote(mb_substr($model_used, 0, 120)) : '')
                .", takeaways = ".($takeaways !== '' ? $db->quote(mb_substr($takeaways, 0, 4000)) : 'NULL')
                .' WHERE id = '.(int) $review_id);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: complete: :e', [':e' => $e->getMessage()]);

            return ['error' => 'Не удалось завершить обзор: '.mb_substr((string) $e->getMessage(), 0, 300)];
        }

        // Извещение автору. Только на первый переход в done: повторный вызов
        // complete() (воркер переслал результат дважды) второй раз не пишет.
        // Если автор — тот же, кто запускал (а он всегда и есть), сообщение
        // всё равно нужно: сборка идёт фоном, человек ждёт её конца.
        $already_done = ($cur AND (string) $cur['status'] === 'done');

        if ( ! $already_done)
        {
            $owner = $cur ? (int) Arr::get($cur, 'user_id', 0) : 0;
            $code  = $cur ? (string) Arr::get($cur, 'code', '') : '';
            $count = 0;

            try
            {
                $count = count(self::items((int) $review_id));
            }
            catch (Throwable $e)
            {
                $count = 0;
            }

            $where = $code !== '' ? '/trends/'.$code : '/trends';

            self::_notify_owner($owner, 'nj593.review.done', [
                'title'     => 'Обзор готов: '.mb_substr(trim((string) $title), 0, 120),
                'body'      => 'Собрано позиций: '.$count.'. Обзор опубликован — можно читать и делиться.',
                'href'      => $where,
                'dedup_key' => 'nj593.review.done:'.(int) $review_id,
                // Своё событие: человек сам запустил обзор. Если он и сейчас
                // на сайте (страница обзора сама досчитывает прогресс),
                // колокольчик молчит — он уже смотрит результат.
                'self'      => TRUE,
                'data'      => [
                    'title' => mb_substr(trim((string) $title), 0, 200),
                    'items' => (string) $count,
                    'href'  => $where,
                ],
            ]);
        }

        // Обратная связь в очередь: обзор ФАКТИЧЕСКИ принят сайтом — его задача
        // nj120 не должна висеть «Выполняется». Если воркер не выполнил
        // complete() (пал после /complete, оборвался ответ), закрываем строку
        // изнутри приёмки — это тот же итог, что дал бы complete() (образец
        // nj602.api_save). Идемпотентно, не выполняется без привязки задачи.
        try
        {
            $r = self::by_id((int) $review_id);

            if (class_exists('Service_Nj120_JobQueue') AND $r AND (int) ($r['job_id'] ?? 0) > 0)
            {
                // mark_delivered: обзор принят САЙТОМ (не воркером), и задача могла
                // быть возвращена в pending или отказана свипером за секунды до
                // доставки — все случаи приводим к «Готово» идемпотентно.
                Service_Nj120_JobQueue::instance()->mark_delivered(
                    (int) $r['job_id'],
                    'Обзор трендов #'.(int) $review_id.' принят сайтом — задача закрыта из приёмки nj593',
                    ['ok' => TRUE, 'review_id' => (int) $review_id, 'status' => 'done'],
                );
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj593: связать задачу с готовым обзором не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        return ['ok' => TRUE];
    }

    public static function fail($review_id, $message)
    {
        $db = self::_db();

        // Читаем строку ДО обновления: нужен владелец и прежний статус, иначе
        // повторный fail() (воркер повторил запрос) слал бы вторую ошибку.
        $cur   = self::by_id((int) $review_id);
        $first = ( ! $cur OR (string) $cur['status'] !== 'error');

        try
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_593_reviews SET status = 'error', error = ".$db->quote(mb_substr((string) $message, 0, 1000))
                .", updated_at = now() WHERE id = ".(int) $review_id);
        }
        catch (Exception $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj593: fail: :e', [':e' => $e->getMessage()]);
        }

        if ($first)
        {
            $code  = $cur ? (string) Arr::get($cur, 'code', '') : '';
            $title = $cur ? trim((string) Arr::get($cur, 'title', '')) : '';
            $where = $code !== '' ? '/trends/'.$code : '/trends';

            self::_notify_owner($cur ? (int) Arr::get($cur, 'user_id', 0) : 0, 'nj593.review.error', [
                'title'     => 'Обзор не собрался'.($title !== '' ? ': '.mb_substr($title, 0, 100) : ''),
                'body'      => 'Сборка прервалась: '.mb_substr(trim((string) $message), 0, 300).'. Можно запустить заново.',
                'href'      => $where,
                'dedup_key' => 'nj593.review.error:'.(int) $review_id,
                'data'      => [
                    'title' => mb_substr($title !== '' ? $title : 'Обзор трендов', 0, 200),
                    'error' => mb_substr(trim((string) $message), 0, 300),
                    'href'  => $where,
                ],
            ]);
        }

        return TRUE;
    }

    /**
     * Сообщить владельцу обзора о его судьбе через центр уведомлений (nj597).
     *
     * Владелец (15.10.2026): «подумай, что добавить в уведомления из модулей:
     * /trends и /graph3d добавь, настрой шаблоны». События объявлены в
     * notify.events манифеста модуля и имеют шаблоны по каналам.
     *
     * Тихий отказ: если центр уведомлений не установлен/недоступен, приёмка
     * результата всё равно должна ответить воркеру успехом.
     *
     * @param int    $user_id  владелец обзора
     * @param string $alias    код события (nj593.review.done | nj593.review.error)
     * @param array  $payload  user_id уже внутри; остальное — title/body/href/data
     */
    protected static function _notify_owner($user_id, $alias, array $payload = [])
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0 OR ! class_exists('Service_Nj597_Notify'))
        {
            return;
        }

        try
        {
            Service_Nj597_Notify::emit($alias, $payload + ['user_id' => $user_id]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj593: уведомление :a не ушло: :e',
                [':a' => $alias, ':e' => $e->getMessage()]);
        }
    }

    /**
     * ПРЕРВАТЬ сборку: задача снимается принудительно (в том числе уже
     * выполняющаяся — воркер узнаёт об этом по отказу heartbeat и прекращает
     * работу), обзор переходит в 'cancelled'.
     *
     * Уже собранные позиции НЕ удаляем: владелец видит частичный результат,
     * а не пустую страницу. Отмена идемпотентна.
     *
     * @return array ['ok'=>TRUE,'status'=>'cancelled','items'=>N] | ['error'=>...]
     */
    public static function cancel($review_id, $reason = '')
    {
        $review = self::by_id((int) $review_id);

        if ($review === NULL)
        {
            return ['error' => 'Обзор не найден'];
        }

        if ( ! in_array((string) $review['status'], ['new', 'running'], TRUE))
        {
            // уже готов/ошибка/остановлен — ничего не делаем (идемпотентно)
            return ['ok' => TRUE, 'status' => (string) $review['status'],
                    'note' => 'сборка уже завершена', 'items' => 0];
        }

        $job_id = (int) ($review['job_id'] ?? 0);

        if ($job_id > 0)
        {
            try
            {
                // force=TRUE: снимаем и выполняющуюся задачу. Воркер увидит
                // отказ heartbeat и прервёт обработчик (см. base._heartbeat_loop).
                Service_Nj120_JobQueue::instance()->cancel($job_id, TRUE);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj593: cancel job :e', [':e' => $e->getMessage()]);
            }
        }

        $items = count(self::items((int) $review['id']));
        $text  = $reason !== '' ? $reason : 'Сборка прервана вручную.';

        if ($items > 0)
        {
            $text .= ' Собранные позиции ('.$items.') сохранены.';
        }

        $text .= ' Можно запустить новый обзор.';

        $db = self::_db();
        $db->query(Database::UPDATE,
            "UPDATE nj_593_reviews SET
                status = 'cancelled',
                error = ".$db->quote(mb_substr($text, 0, 1000)).",
                progress = '',
                finished_at = now(),
                updated_at = now()
             WHERE id = ".(int) $review['id']);

        return ['ok' => TRUE, 'status' => 'cancelled', 'items' => $items];
    }

    /** Удалить обзор ПОЛНОСТЬЮ (позиции уйдут по CASCADE). */
    public static function hard_delete($review_id)
    {
        $review = self::by_id((int) $review_id);

        if ($review === NULL)
        {
            return FALSE;
        }

        if ( ! empty($review['job_id']))
        {
            try
            {
                // force=TRUE: иначе удаление обзора оставляло выполняющуюся
                // задачу жить (воркер доработает и начнёт публиковать позиции
                // в уже удалённый обзор — ошибки в журнале и мусор в очереди).
                Service_Nj120_JobQueue::instance()->cancel((int) $review['job_id'], TRUE);
            }
            catch (Exception $e)
            {
                // отмена не критична
            }
        }

        self::_db()->query(Database::DELETE,
            'DELETE FROM nj_593_reviews WHERE id = '.(int) $review_id);

        return TRUE;
    }

    // ------------------------------------------------------------------
    // Reconcile (лениво при чтении статуса/страницы)
    // ------------------------------------------------------------------

    /**
     * Порог «обзор живёт слишком долго» (сек). Раньше отсутствовал: страница
     * обзора НЕ звала ни свипер, ни авто-стоп (в отличие от /radar и /leads),
     * поэтому задача, чей воркер замолчал, держала статус «в работе» вечно
     * (владелец, 26.09: trda72ff421 — «ИИ отбирает…», 0 из 8, часы спустя).
     */
    /**
     * Сколько секунд идёт текущая задача обзора (для живого статус-ряда).
     *
     * Раньше таймер в разметке был жёстко «0:00»: страница показывала ноль,
     * пока за неё не возьмётся JS — а если скрипт не отработал (или страница
     * открыта без JS), владелец видел «0:00» и решал, что сборка зависла.
     * Теперь сервер отдаёт фактическое время задачи, а JS продолжает счёт.
     */
    public static function elapsed_sec(array $review)
    {
        if (empty($review['job_id']))
            return 0;

        try
        {
            $job = Service_Nj120_JobQueue::instance()->get((int) $review['job_id']);
        }
        catch (Throwable $e)
        {
            return 0;
        }

        if ( ! $job OR ! in_array($job['status'], ['pending', 'processing'], TRUE))
            return 0;

        $started = strtotime((string) ($job['claimed_at'] ?: $job['created_at']));

        return $started ? max(0, time() - $started) : 0;
    }

    protected static function _stale_sec()
    {
        $v = (int) getenv('NJ593_TRENDS_STALE_SEC');

        return $v > 0 ? $v : 1500;   // 25 минут
    }

    /** Порог «задачу никто не взял» (сек): воркер не подхватил работу. */
    protected static function _claim_sec()
    {
        $v = (int) getenv('NJ593_TRENDS_CLAIM_SEC');

        return $v > 0 ? $v : 900;    // 15 минут ожидания в очереди
    }

    /**
     * Порог «воркер перестал отчитываться» (сек). Пульс задачи (heartbeat_at)
     * приходит от ОТДЕЛЬНОГО потока и остаётся свежим, даже когда event loop
     * воркера заморожен: снаружи задача выглядит живой, а обзор бесконечно
     * висит «в работе» (владелец, 26.09: trda72ff421). Отчёты о ходе пишет
     * только сам рабочий цикл — по ним и видно реальную тишину.
     */
    protected static function _silence_sec()
    {
        $v = (int) getenv('NJ593_TRENDS_SILENCE_SEC');

        return $v > 0 ? $v : 600;    // 10 минут без отчётов о ходе
    }

    /** Возраст строки в секундах (по created_at, с запасным updated_at). */
    protected static function _age_sec(array $row)
    {
        $ts = strtotime((string) ( ! empty($row['created_at']) ? $row['created_at'] : $row['updated_at']));

        return $ts ? max(0, time() - $ts) : 0;
    }

    /**
     * Сколько секунд обзор молчит: с последнего отчёта воркера о ходе.
     * Считаем НА СТОРОНЕ БД (now() минус updated_at) — часы и часовой пояс
     * одни и те же, время PHP тут ни при чём.
     */
    protected static function _silent_sec(array $review)
    {
        if (empty($review['id']))
        {
            return 0;
        }

        try
        {
            $row = self::_db()->query(Database::SELECT,
                'SELECT GREATEST(0, EXTRACT(EPOCH FROM (now() - COALESCE(updated_at, created_at))))::int AS s'
                .' FROM nj_593_reviews WHERE id = '.(int) $review['id'], FALSE)->current();
        }
        catch (Throwable $e)
        {
            return 0;
        }

        return $row ? (int) $row['s'] : 0;
    }

    /**
     * Сколько задача РАБОТАЕТ (с момента старта у воркера), сек.
     * Считаем именно от старта: время ожидания в очереди — не «зависание»,
     * и жёсткий потолок не должен убивать обзор, который стоял в очереди.
     */
    protected static function _job_age_sec(array $job, array $review)
    {
        $stamp = ! empty($job['started_at']) ? $job['started_at']
            : ( ! empty($job['claimed_at']) ? $job['claimed_at']
                : ( ! empty($review['created_at']) ? $review['created_at'] : $review['updated_at']));

        $ts = strtotime((string) $stamp);

        return $ts ? max(0, time() - $ts) : 0;
    }

    /**
     * Сверка списка: незавершённые строки получают честный финал, даже если
     * пользователь смотрит «Мои обзоры», а не страницу обзора. Ограничение по
     * количеству — чтобы вызов оставался дешёвым (страница открывается часто).
     */
    public static function reconcile_list(array $reviews, $limit = 8)
    {
        $left = max(1, (int) $limit);

        foreach ($reviews as $i => $row)
        {
            if ($left <= 0)
            {
                break;
            }

            if (in_array((string) Arr::get($row, 'status'), ['new', 'running'], TRUE))
            {
                $reviews[$i] = self::reconcile($row);
                $left--;
            }
        }

        return $reviews;
    }

    public static function reconcile(array $review)
    {
        if ( ! in_array($review['status'], ['new', 'running'], TRUE))
        {
            return $review;
        }

        if (empty($review['job_id']))
        {
            // Задача не встала в очередь (сбой прокидки): раньше это был вечный
            // «в работе». Теперь честная ошибка с причиной — обзор можно
            // запустить снова.
            if (self::_age_sec($review) > self::_claim_sec())
            {
                $text = 'Сборка не началась: задача не встала в очередь воркеров. Запустите обзор заново.';

                self::_db()->query(Database::UPDATE,
                    "UPDATE nj_593_reviews SET status = 'error', error = ".self::_db()->quote($text)
                    .", progress = '', updated_at = now() WHERE id = ".(int) $review['id']);

                $review['status'] = 'error';
                $review['error']  = $text;
            }

            return $review;
        }

        // Молчащий воркер гасим ТОЙ ЖЕ авто-остановкой, что и /radar с /leads
        // (порог 240 с без пульса внутри метода; вызов дешёвый, идемпотентный).
        // Живая задача не тронет: сверка идёт по heartbeat_at.
        try
        {
            Service_Nj120_JobQueue::instance()->auto_stop_silent((int) $review['job_id']);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj593: авто-остановка молчащего сбора: :e', [':e' => $e->getMessage()]);
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $review['job_id']);

        if ($job === NULL)
        {
            // Строку очереди убрали (чистка), а обзор остался «в работе».
            if (self::_age_sec($review) > self::_stale_sec())
            {
                $text = 'Задача сбора исчезла из очереди воркеров — обзор остановлен. Запустите заново.';

                self::_db()->query(Database::UPDATE,
                    "UPDATE nj_593_reviews SET status = 'error', error = ".self::_db()->quote($text)
                    .", progress = '', updated_at = now() WHERE id = ".(int) $review['id']);

                $review['status'] = 'error';
                $review['error']  = $text;
            }

            return $review;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode((string) $res, TRUE);
            $res = is_array($res) ? $res : [];

            $ready = (int) $db->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_593_items WHERE review_id = '.(int) $review['id'], FALSE)
                ->current()['c'];

            $usage_set = ', usage = '.(isset($res['usage']) ? $db->quote(json_encode($res['usage'], JSON_UNESCAPED_UNICODE)).'::jsonb' : 'usage');

            if ($ready > 0)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_593_reviews SET status = 'done', error = NULL, progress = '', finished_at = now(), updated_at = now()"
                    .$usage_set
                    .' WHERE id = '.(int) $review['id']);
                $review['status'] = 'done';
            }
            else
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_593_reviews SET status = 'error', error = "
                    .$db->quote('Воркер закончил работу, но не прислал ни одной позиции обзора')
                    .", updated_at = now() WHERE id = ".(int) $review['id']);
                $review['status'] = 'error';
            }
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_CANCELLED)
        {
            // Задачу сняли: вручную («Прервать»), авто-стопом молчащего воркера
            // или уборкой сирот после рестарта. Раньше этот случай НЕ
            // обрабатывался — страница показывала «Собираем обзор» бесконечно,
            // хотя сбор уже никто не вёл (владелец: «обычно работает, а сейчас
            // завис»). Теперь честное «остановлено» с сохранением позиций.
            $ready = (int) $db->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_593_items WHERE review_id = '.(int) $review['id'], FALSE)
                ->current()['c'];

            // Причина от сайта (авто-стоп молчащего воркера, свипер, владелец)
            // важнее общей формулировки: владелец должен видеть, ПОЧЕМУ встало.
            $text = trim((string) $job['error']);

            if ($text === '')
            {
                $text = 'Сборка остановлена до завершения.';
            }

            if ($ready > 0 AND strpos($text, (string) $ready) === FALSE)
            {
                $text .= ' Собранные позиции ('.$ready.') сохранены.';
            }

            if (strpos($text, 'заново') === FALSE)
            {
                $text .= ' Можно запустить новый обзор.';
            }

            $db->query(Database::UPDATE,
                "UPDATE nj_593_reviews SET
                    status = 'cancelled',
                    error = ".$db->quote($text).",
                    progress = '',
                    finished_at = COALESCE(finished_at, now()),
                    updated_at = now()
                 WHERE id = ".(int) $review['id']);

            $review['status'] = 'cancelled';
            $review['error']  = $text;
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED)
        {
            $err = (string) $job['error'];

            if ($err === '' AND ! empty($job['result']))
            {
                $err = (string) (is_array($job['result']) ? reset($job['result']) : $job['result']);
            }

            $db->query(Database::UPDATE,
                "UPDATE nj_593_reviews SET status = 'error', error = "
                .$db->quote(mb_substr($err !== '' ? $err : 'Ошибка воркера', 0, 1000))
                .", updated_at = now() WHERE id = ".(int) $review['id']);
            $review['status'] = 'error';
            $review['error']  = $err;
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_PENDING)
        {
            // Задачу никто не взял (воркер не поднят / другой канал заняты).
            // Раньше «новый» обзор висел в очереди бесконечно и выглядел как
            // «в работе» — теперь честная ошибка с понятным текстом.
            if (self::_age_sec($review) > self::_claim_sec())
            {
                $text = 'Воркер не взял задачу за '.(int) round(self::_claim_sec() / 60)
                    .' мин. Проверьте, запущен ли обработчик, и повторите сбор.';

                try
                {
                    Service_Nj120_JobQueue::instance()->cancel((int) $review['job_id'], TRUE);
                }
                catch (Throwable $e)
                {
                    // отмена не критична — важен понятный финал для пользователя
                }

                $db->query(Database::UPDATE,
                    "UPDATE nj_593_reviews SET status = 'error', error = ".$db->quote($text)
                    .", progress = '', updated_at = now() WHERE id = ".(int) $review['id']);

                $review['status'] = 'error';
                $review['error']  = $text;
            }
        }
        elseif (self::_job_age_sec($job, $review) > self::_stale_sec()
             OR self::_silent_sec($review) > self::_silence_sec())
        {
            // Обзор идёт дольше разумного без доставленной позиции:
            // жёсткий потолок (последний случай — «живой» пульс у застрявшего
            // обработчика). Гасим задачу на сайте, воркер узнает об отмене по
            // пульсу; найденное (если что-то успело дойти) остаётся на странице.
            //
            // Второе условие — тишина в отчётах о ходе. Пульс задачи приходит
            // от отдельного потока воркера и остаётся свежим, даже когда сам
            // цикл заморожен: по одному пульсу «зависание» не отличить от
            // работы. Если же воркер N минут не присылает ни одной строки
            // прогресса, сбор ведёт никто — закрываем с понятным финалом.
            $ready = (int) $db->query(Database::SELECT,
                'SELECT count(*) AS c FROM nj_593_items WHERE review_id = '.(int) $review['id'], FALSE)
                ->current()['c'];

            $silent = self::_silent_sec($review);

            if ($silent > self::_silence_sec())
            {
                $text = 'Воркер не выходил на связь '.(int) round($silent / 60)
                    .' мин — сборка остановлена автоматически.'
                    .($ready > 0 ? ' Собранные позиции ('.$ready.') сохранены.' : '')
                    .' Запустите обзор заново.';
            }
            else
            {
                $text = 'Сборка шла дольше '.(int) round(self::_stale_sec() / 60)
                    .' мин и не дала результата — остановлена автоматически.'
                    .($ready > 0 ? ' Собранные позиции ('.$ready.') сохранены.' : '')
                    .' Запустите обзор заново.';
            }

            try
            {
                Service_Nj120_JobQueue::instance()->cancel((int) $review['job_id'], TRUE);
            }
            catch (Throwable $e)
            {
                // отмена не критична — статус обзора важнее
            }

            $db->query(Database::UPDATE,
                "UPDATE nj_593_reviews SET
                    status = 'cancelled',
                    error = ".$db->quote($text).",
                    progress = '',
                    finished_at = COALESCE(finished_at, now()),
                    updated_at = now()
                 WHERE id = ".(int) $review['id']);

            $review['status'] = 'cancelled';
            $review['error']  = $text;
        }

        return $review;
    }
}
