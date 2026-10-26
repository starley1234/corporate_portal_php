<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Аккумулятор предпочтений (nj600_prefs): откуда и куда пришёл, по какой фразе,
 * какие и сколько разделов смотрел. Задел под проактивный ИИ («Детектор контекста
 * и намерений»).
 *
 * ЭКОНОМИЯ БД (требование владельца):
 *  * nj_600_events  — сырое, живёт недолго (NJ600_RAW_TTL_DAYS, по умолчанию 21),
 *                     чистится вероятностно (~1% запросов) и кнопкой в админке;
 *  * nj_600_profile — КОМПАКТНАЯ суммаризация на владельца (u:<id> / g:<ubid>):
 *                     счётчики разделов/фраз/источников в JSONB с капами,
 *                     обновляется инкрементально (upsert) — таблица не растёт.
 *
 * Сбор — серверный, без JS: хук в Erp100_Base::before() (GET-страницы) и
 * вызов track_search() из nj100_search. Каждая точка — try/catch: сбор не может
 * сломать страницу.
 */
class Service_Nj600_Prefs
{
    /** Конфиг модуля (config/nj600_prefs.php). */
    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj600_prefs');

        return $cfg ? $cfg->get($key, $default) : $default;
    }

    protected static function _db()
    {
        return Database::instance();
    }

    public static function ensure_tables()
    {
        static $done = FALSE;

        if ($done)
        {
            return;
        }

        $done = TRUE;

        // Миграции являются единственным штатным способом установки схемы.
        // Нельзя выполнять CREATE/ALTER TABLE на каждом просмотре страницы.
        // Автосоздание оставлено только для аварийного bootstrap по явному env.
        if (trim((string) getenv('NJ600_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_600_events" (
    "id"           BIGSERIAL PRIMARY KEY,
    "user_id"      BIGINT       NULL,
    "visitor_uid"  VARCHAR(40)  NOT NULL DEFAULT '',
    "section"      VARCHAR(60)  NOT NULL DEFAULT '',
    "action"       VARCHAR(12)  NOT NULL DEFAULT 'page',
    "phrase"       VARCHAR(160) NOT NULL DEFAULT '',
    "ref_host"     VARCHAR(120) NOT NULL DEFAULT '',
    "utm_source"   VARCHAR(80)  NOT NULL DEFAULT '',
    "utm_medium"   VARCHAR(80)  NOT NULL DEFAULT '',
    "utm_campaign" VARCHAR(80)  NOT NULL DEFAULT '',
    "uri"          VARCHAR(200) NOT NULL DEFAULT '',
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_600_events_owner_idx" ON "nj_600_events" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_600_events_created_idx" ON "nj_600_events" ("created_at");
COMMENT ON TABLE "nj_600_events" IS 'NJ-600 сырое (TTL, чистится автоматически): страницы, поисковые фразы, referrer/utm';
SQL
        )->execute();
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_600_profile" (
    "owner_key"      VARCHAR(40) PRIMARY KEY,
    "sections"       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    "phrases"        JSONB       NOT NULL DEFAULT '[]'::jsonb,
    "ref_hosts"      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    "utm_last"       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    "pages_total"    BIGINT      NOT NULL DEFAULT 0,
    "searches_total" BIGINT      NOT NULL DEFAULT 0,
    "first_seen"     TIMESTAMPTZ NOT NULL DEFAULT now(),
    "last_seen"      TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_600_profile" IS 'NJ-600 компактная суммаризация предпочтений по владельцу (u:<id>/g:<ubid>)';
SQL
        )->execute();

        // ИИ-резюме профиля (воркер nj600.compact: скрипты → потом LLM, экономим токены)
        DB::query(NULL, <<<SQL
ALTER TABLE "nj_600_profile" ADD COLUMN IF NOT EXISTS "llm_summary" TEXT NULL;
ALTER TABLE "nj_600_profile" ADD COLUMN IF NOT EXISTS "llm_at" TIMESTAMPTZ NULL;
ALTER TABLE "nj_600_profile" ADD COLUMN IF NOT EXISTS "llm_model" VARCHAR(120) NOT NULL DEFAULT '';
SQL
        )->execute();

        $done = TRUE;
    }

    // ------------------------------------------------------------- сбор

    /** Трек страницы (из Erp100_Base::before, GET, главный запрос). */
    public static function track_page($uri, $referrer = NULL)
    {
        try
        {
            $section = self::_section_of((string) $uri);

            if ($section === '' OR in_array($section, (array) self::cfg('sections_ignore', []), TRUE))
            {
                return;
            }

            // Аналитика не должна тормозить навигацию: один браузер пишет
            // событие не чаще заданного интервала. Cookie не является ACL или
            // источником истины, это только дешёвый throttle телеметрии.
            $interval = (int) self::cfg('track_min_interval', 60);
            $last = (int) Cookie::get('nj600_last_track', 0);
            if ($interval > 0 AND $last > 0 AND time() - $last < $interval)
            {
                return;
            }
            Cookie::set('nj600_last_track', (string) time(), $interval + 3600);

            $ref = self::_parse_referrer((string) $referrer);
            $utm = self::_utm_of();

            self::_write($section, 'page', '', $ref, $utm, (string) $uri);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj600: track_page: :e', [':e' => $e->getMessage()]);
        }
    }

    /** Трек поисковой фразы (внутренний поиск nj100). */
    public static function track_search($phrase)
    {
        try
        {
            $phrase = trim((string) $phrase);

            if (mb_strlen($phrase) < 2)
            {
                return;
            }

            self::_write('', 'search', mb_substr($phrase, 0, 160), [], [], '/search/api');
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj600: track_search: :e', [':e' => $e->getMessage()]);
        }
    }

    protected static function _write($section, $action, $phrase, array $ref, array $utm, $uri)
    {
        self::ensure_tables();

        // Учитываем только настоящих посетителей: зарегистрированных либо
        // тех, у кого УЖЕ есть браузерная кука ubid (реальный браузер, которому
        // она была поставлена ранее). Роботов и краулеров, у которых кук нет,
        // не пишем — Visitor::uid()/ubid() сгенерировали бы им «свежую» куку
        // и испортили бы все счётчики. Проверяем ДО любого вызова uid()/ubid().
        $user_id = Visitor::user_id();

        if ($user_id === NULL AND ! Visitor::has_ubid())
        {
            return;
        }

        $uid   = Visitor::uid();
        $db    = self::_db();

        $db->query(Database::INSERT,
            'INSERT INTO nj_600_events (user_id, visitor_uid, section, action, phrase, ref_host, utm_source, utm_medium, utm_campaign, uri)
             VALUES ('.($user_id === NULL ? 'NULL' : $user_id).', '.$db->quote($uid).', '.$db->quote($section).',
                     '.$db->quote($action).', '.$db->quote($phrase).', '.$db->quote((string) ($ref['host'] ?? '')).',
                     '.$db->quote((string) ($utm['utm_source'] ?? '')).', '.$db->quote((string) ($utm['utm_medium'] ?? '')).',
                     '.$db->quote((string) ($utm['utm_campaign'] ?? '')).', '.$db->quote(mb_substr($uri, 0, 200)).')', FALSE);

        self::_summarize($uid, $section, $action, $phrase, $ref, $utm);

        // автоочистка сырья: ~1% запросов (дёшево и достаточно)
        if (mt_rand(1, 100) === 1)
        {
            self::prune();
        }
    }

    /** Инкрементальная суммаризация в компактный профиль (таблица не растёт). */
    protected static function _summarize($owner_key, $section, $action, $phrase, array $ref, array $utm)
    {
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT sections, phrases, ref_hosts, utm_last, pages_total, searches_total
             FROM nj_600_profile WHERE owner_key = '.$db->quote($owner_key), FALSE)->current();

        $sections = $row ? json_decode((string) $row['sections'], TRUE) ?: [] : [];
        $phrases  = $row ? json_decode((string) $row['phrases'], TRUE) ?: [] : [];
        $hosts    = $row ? json_decode((string) $row['ref_hosts'], TRUE) ?: [] : [];
        $pages    = $row ? (int) $row['pages_total'] : 0;
        $searches = $row ? (int) $row['searches_total'] : 0;
        $utm_last = $row ? json_decode((string) $row['utm_last'], TRUE) ?: [] : [];

        if ($action === 'search')
        {
            $searches++;
            $found = FALSE;

            foreach ($phrases as $i => [$p, $c])
            {
                if (mb_strtolower((string) $p) === mb_strtolower((string) $phrase))
                {
                    $phrases[$i][1] = $c + 1;
                    $found = TRUE;
                    break;
                }
            }

            if ( ! $found)
            {
                $phrases[] = [$phrase, 1];
            }

            usort($phrases, function ($a, $b) { return $b[1] <=> $a[1]; });
            $phrases = array_slice($phrases, 0, (int) self::cfg('phrases_cap', 20));
        }
        else
        {
            $pages++;
            $sections[$section] = (int) ($sections[$section] ?? 0) + 1;
            arsort($sections);
            $sections = array_slice($sections, 0, (int) self::cfg('sections_cap', 40), TRUE);
        }

        if ( ! empty($ref['host']))
        {
            $hosts[$ref['host']] = (int) ($hosts[$ref['host']] ?? 0) + 1;
            arsort($hosts);
            $hosts = array_slice($hosts, 0, (int) self::cfg('ref_hosts_cap', 10), TRUE);
        }

        foreach ($utm as $k => $v)
        {
            if ($v !== '')
            {
                $utm_last[$k] = $v;
            }
        }

        $sql = $row
            ? 'UPDATE nj_600_profile SET sections = :s, phrases = :p, ref_hosts = :h, utm_last = :u,
                 pages_total = :pt, searches_total = :st, last_seen = now()
               WHERE owner_key = :o'
            : 'INSERT INTO nj_600_profile (owner_key, sections, phrases, ref_hosts, utm_last, pages_total, searches_total)
               VALUES (:o, :s, :p, :h, :u, :pt, :st)';

        // ВАЖНО: strtr, а не str_replace! Раньше str_replace(':s', ...) задевал
        // плейсхолдер ':st' (и ':p' задевал ':pt') — SQL ломался на первом же
        // событии, nj_600_profile никогда не создавался («Профилей 0» в админке).
        // strtr заменяет по самому длинному совпадению и не пересканирует вставки.
        $db->query(Database::INSERT, strtr($sql, [
            ':s'  => $db->quote(json_encode($sections, JSON_UNESCAPED_UNICODE)),
            ':p'  => $db->quote(json_encode($phrases, JSON_UNESCAPED_UNICODE)),
            ':h'  => $db->quote(json_encode($hosts, JSON_UNESCAPED_UNICODE)),
            ':u'  => $db->quote(json_encode($utm_last, JSON_UNESCAPED_UNICODE)),
            ':pt' => (int) $pages,
            ':st' => (int) $searches,
            ':o'  => $db->quote($owner_key),
        ]), FALSE);
    }

    // --------------------------------------------------------- разбор

    /** Раздел = первые 1–2 сегмента пути («jobs/jb88…» → «jobs»). */
    protected static function _section_of($uri)
    {
        $path = trim((string) parse_url($uri, PHP_URL_PATH), '/');
        $seg  = explode('/', $path);
        $s0   = (string) $seg[0];

        return ($s0 === '') ? 'home' : mb_substr($s0, 0, 60);
    }

    /** referrer → host + поисковая фраза внешнего поисковика (q/p/text/query/wd). */
    protected static function _parse_referrer($referrer)
    {
        $out = ['host' => '', 'phrase' => ''];

        if ($referrer === '' OR stripos($referrer, $_SERVER['HTTP_HOST'] ?? '') !== FALSE)
        {
            return $out; // пусто или внутренний переход — фразы нет
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        $out['host'] = mb_substr((string) $host, 0, 120);

        parse_str((string) parse_url($referrer, PHP_URL_QUERY), $q);

        foreach (['q', 'p', 'text', 'query', 'wd', 'words'] as $k)
        {
            if ( ! empty($q[$k]) AND is_string($q[$k]))
            {
                $out['phrase'] = mb_substr(trim($q[$k]), 0, 160);
                break;
            }
        }

        return $out;
    }

    protected static function _utm_of()
    {
        $out = [];

        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $k)
        {
            $v = isset($_GET[$k]) ? trim((string) $_GET[$k]) : '';

            if ($v !== '')
            {
                $out[$k] = mb_substr($v, 0, 80);
            }
        }

        return $out;
    }

    // --------------------------------------------------- выдача/управление

    /** Компактный профиль владельца — задел под «Детектор намерений» (LLM). */
    public static function profile($owner_key = NULL)
    {
        self::ensure_tables();
        $owner_key = $owner_key ?: Visitor::uid();
        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_600_profile WHERE owner_key = '.self::_db()->quote($owner_key), FALSE)->current();

        if ( ! $row)
        {
            return ['owner' => $owner_key, 'top_sections' => [], 'top_phrases' => [], 'ref_hosts' => [],
                    'pages_total' => 0, 'searches_total' => 0, 'last_seen' => NULL];
        }

        $sections = json_decode((string) $row['sections'], TRUE) ?: [];
        arsort($sections);

        $hosts = json_decode((string) $row['ref_hosts'], TRUE) ?: [];
        arsort($hosts);

        return [
            'owner'          => $owner_key,
            'top_sections'   => array_slice($sections, 0, 5, TRUE),
            'top_phrases'    => array_slice(json_decode((string) $row['phrases'], TRUE) ?: [], 0, 5),
            'ref_hosts'      => array_slice($hosts, 0, 3, TRUE),
            'utm_last'       => json_decode((string) $row['utm_last'], TRUE) ?: [],
            'pages_total'    => (int) $row['pages_total'],
            'searches_total' => (int) $row['searches_total'],
            'last_seen'      => (string) $row['last_seen'],
        ];
    }

    /** Очистка сырья по TTL (дни из конфига). Возвращает число удалённых строк. */
    public static function prune()
    {
        $days = (int) self::cfg('raw_ttl_days', 21);

        if ($days <= 0)
        {
            return 0;
        }

        self::ensure_tables();

        return self::_db()->query(Database::DELETE,
            'DELETE FROM nj_600_events WHERE created_at < now() - interval \''.$days.' days\'', FALSE);
    }

    /** Сводка для админки /nj600/admin. */
    public static function admin_stats()
    {
        self::ensure_tables();
        $db = self::_db();

        $one = function ($sql) use ($db) {
            $r = $db->query(Database::SELECT, $sql, FALSE)->current();

            return $r ? (int) array_values((array) $r)[0] : 0;
        };

        $size = function ($table) use ($db) {
            $r = $db->query(Database::SELECT,
                'SELECT pg_size_pretty(pg_total_relation_size('.$db->quote($table).')) AS s', FALSE)->current();

            return $r ? (string) $r['s'] : '—';
        };

        $sections = $db->query(Database::SELECT,
            'SELECT s.key AS section, sum((s.value)::bigint) AS cnt
             FROM nj_600_profile p, jsonb_each(p.sections) s
             GROUP BY s.key ORDER BY cnt DESC LIMIT 10', FALSE)->as_array();

        $phrases = $db->query(Database::SELECT,
            'SELECT e->>0 AS phrase, sum((e->>1)::bigint) AS cnt
             FROM nj_600_profile p, jsonb_array_elements(p.phrases) e
             GROUP BY e->>0 ORDER BY cnt DESC LIMIT 10', FALSE)->as_array();

        return [
            'events_total'  => $one('SELECT count(*) FROM nj_600_events'),
            'events_24h'    => $one('SELECT count(*) FROM nj_600_events WHERE created_at > now() - interval \'24 hours\''),
            'profiles'      => $one('SELECT count(*) FROM nj_600_profile'),
            'size_events'   => $size('nj_600_events'),
            'size_profile'  => $size('nj_600_profile'),
            'raw_ttl_days'  => (int) self::cfg('raw_ttl_days', 21),
            'top_sections'  => $sections,
            'top_phrases'   => $phrases,
        ];
    }

    // --------------------------------------------- ИИ-сжатие (воркер nj600)

    /**
     * Обезличенные интересы аудитории для контент-конвейера (nj602 brief):
     * топ разделов и поисковых фраз по ВСЕМ профилям, без owner_key и без
     * служебных разделов. Это не персональные данные — только агрегаты.
     */
    public static function aggregate_for_content($limit = 8)
    {
        self::ensure_tables();
        $db = self::_db();

        $ignore = (array) self::cfg('sections_ignore', []);

        $sections = $db->query(Database::SELECT,
            'SELECT s.key AS section, sum((s.value)::bigint) AS cnt
             FROM nj_600_profile p, jsonb_each(p.sections) s
             GROUP BY s.key ORDER BY cnt DESC LIMIT '.((int) $limit + count($ignore)), FALSE)->as_array();

        $out_sections = [];

        foreach ($sections as $r)
        {
            $name = (string) $r['section'];

            if (in_array($name, $ignore, TRUE))
            {
                continue;
            }

            $out_sections[] = ['name' => $name, 'count' => (int) $r['cnt']];

            if (count($out_sections) >= $limit)
            {
                break;
            }
        }

        $phrases = $db->query(Database::SELECT,
            'SELECT e->>0 AS phrase, sum((e->>1)::bigint) AS cnt
             FROM nj_600_profile p, jsonb_array_elements(p.phrases) e
             GROUP BY e->>0 ORDER BY cnt DESC LIMIT '.(int) $limit, FALSE)->as_array();

        $out_phrases = [];

        foreach ($phrases as $r)
        {
            $out_phrases[] = ['phrase' => (string) $r['phrase'], 'count' => (int) $r['cnt']];
        }

        return ['sections' => $out_sections, 'phrases' => $out_phrases];
    }

    /**
     * Профили владельцев для сжатия (воркер nj600.compact): сырые счётчики
     * уже компактны — воркер сначала склеивает их скриптами, LLM пишет только
     * человеческое резюме.
     */
    public static function admin_profiles($limit = 20)
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            'SELECT owner_key, sections, phrases, ref_hosts, pages_total, searches_total,
                    last_seen, llm_summary, llm_at, llm_model
             FROM nj_600_profile
             ORDER BY (pages_total + searches_total) DESC, last_seen DESC
             LIMIT '.(int) $limit, FALSE)->as_array();

        $out = [];

        foreach ($rows as $r)
        {
            $sections = json_decode((string) $r['sections'], TRUE) ?: [];
            arsort($sections);
            $phrases = json_decode((string) $r['phrases'], TRUE) ?: [];

            $out[] = [
                'owner_key'   => (string) $r['owner_key'],
                'pages'       => (int) $r['pages_total'],
                'searches'    => (int) $r['searches_total'],
                'top_section' => (string) (array_key_first($sections) ?: ''),
                'top_phrase'  => (string) ($phrases[0][0] ?? ''),
                'last_seen'   => (string) $r['last_seen'],
                'llm_summary' => (string) ($r['llm_summary'] ?? ''),
                'llm_at'      => (string) ($r['llm_at'] ?? ''),
                'llm_model'   => (string) ($r['llm_model'] ?? ''),
            ];
        }

        return $out;
    }

    /** Данные профилей для воркера (токен воркера в контроллере). */
    public static function dump_for_worker($limit = 15)
    {
        self::ensure_tables();

        $out = [];

        foreach (self::admin_profiles($limit) as $p)
        {
            $row = self::_db()->query(Database::SELECT,
                'SELECT sections, phrases, ref_hosts FROM nj_600_profile WHERE owner_key = '.self::_db()->quote($p['owner_key']), FALSE)->current();

            if ( ! $row)
            {
                continue;
            }

            $out[] = [
                'owner_key' => $p['owner_key'],
                'sections'  => json_decode((string) $row['sections'], TRUE) ?: [],
                'phrases'   => json_decode((string) $row['phrases'], TRUE) ?: [],
                'ref_hosts' => json_decode((string) $row['ref_hosts'], TRUE) ?: [],
                'pages'     => $p['pages'],
                'searches'  => $p['searches'],
            ];
        }

        return $out;
    }

    /** Сохранить ИИ-резюме профиля (воркер nj600.compact). */
    public static function save_summary($owner_key, $summary, $model)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT owner_key FROM nj_600_profile WHERE owner_key = '.self::_db()->quote((string) $owner_key), FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Профиль не найден'];
        }

        self::_db()->query(Database::INSERT,
            'UPDATE nj_600_profile SET llm_summary = '.self::_db()->quote(mb_substr((string) $summary, 0, 2000)).',
                llm_at = now(), llm_model = '.self::_db()->quote(mb_substr((string) $model, 0, 120)).'
             WHERE owner_key = '.self::_db()->quote((string) $owner_key), FALSE);

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------------------
    // Живой статус-ряд сжатия профилей (общий механизм partials/live_status)
    // ------------------------------------------------------------------

    const CHANNEL_COMPACT = 'nj600.compact';

    /** Контракт «задачи нет» — тот же набор полей, что у JobQueue. */
    public static function _empty_live()
    {
        return [
            'found'       => FALSE,
            'job_id'      => 0,
            'status'      => 'none',
            'stage'       => '',
            'stages'      => [],
            'elapsed_sec' => 0,
            'started_at'  => NULL,
            'finished_at' => NULL,
            'silent_sec'  => NULL,
            'error'       => '',
            'note'        => '',
        ];
    }

    /** Текущее (или свежайшее) сжатие профилей: у задачи нет «своего» объекта. */
    public static function live_now()
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return self::_empty_live();
        }

        return Service_Nj120_JobQueue::instance()->live_status(self::CHANNEL_COMPACT, '', '');
    }

    /** Последнее сжатие, включая завершённое — плашка итога на странице. */
    public static function last_run()
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status(self::CHANNEL_COMPACT, '', '');

        return ($st AND ! empty($st['found'])) ? $st : NULL;
    }

    /** Остановить идущее сжатие. @return array [ok] | [error] */
    public static function cancel_run()
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $queue  = Service_Nj120_JobQueue::instance();
        $job_id = method_exists($queue, 'find_active')
            ? $queue->find_active(self::CHANNEL_COMPACT, '', '')
            : 0;

        if ($job_id <= 0)
        {
            return ['error' => 'Активного сжатия нет — останавливать нечего'];
        }

        try
        {
            $queue->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить сжатие'];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    /** Поставить задачу сжатия профилей (не дублируя незавершённую). */
    public static function queue_compact()
    {
        $busy = self::_db()->query(Database::SELECT,
            "SELECT id FROM nj_120_jobs WHERE channel = 'nj600.compact' AND status IN ('pending','processing')
             ORDER BY id DESC LIMIT 1", FALSE)->current();

        if ($busy)
        {
            return ['error' => 'Предыдущее сжатие ещё выполняется (задача #'.(int) $busy['id'].')'];
        }

        $job = Service_Nj120_JobQueue::push('nj600.compact', ['limit' => 15], [
            'visitor_id'   => 's:nj600',
            'module'       => 'nj600',
            'max_attempts' => 1,
            'timeout_sec'  => 1200,
        ]);

        return ['ok' => TRUE, 'job' => (int) $job];
    }

    // ------------------------------------------- популярность модулей

    /** Канал очереди → раздел сайта (для стыковки с визитами). */
    public static function channel_section_map()
    {
        return [
            'nj540.reply'    => 'review',
            'nj550.jobs'     => 'jobs',
            'nj570.generate' => '3d',
            'nj570.clarify'  => '3d',
            'nj570.render'   => '3d',
            'nj580.generate' => 'docs',
            'nj590.design'   => 'design',
            'nj591.company'  => 'company',
            'nj592.email'    => 'coldmail',
            'nj593.trends'   => 'trends',
            'nj598.dossier'  => 'dossier',
            'nj602.article'  => 'content',
        ];
    }

    /**
     * Популярность модулей (7 дней): заходы (nj_600_events) против результата
     * (nj_120_jobs: готово/ошибки). «Пришли, но не добились» = заходы есть,
     * а готовых задач нет — сигнал, что модуль не доводит до результата.
     */
    public static function module_stats()
    {
        self::ensure_tables();
        $db = self::_db();

        $sections = $db->query(Database::SELECT,
            "SELECT section, count(*) AS views
             FROM nj_600_events
             WHERE created_at > now() - interval '7 days'
             GROUP BY section", FALSE)->as_array();

        $jobs = $db->query(Database::SELECT,
            "SELECT channel, count(*) AS total,
                    count(*) FILTER (WHERE status = 'done') AS done,
                    count(*) FILTER (WHERE status = 'failed') AS failed
             FROM nj_120_jobs
             WHERE created_at > now() - interval '7 days'
             GROUP BY channel", FALSE)->as_array();

        $ignore = (array) self::cfg('sections_ignore', []);
        $out = [];

        foreach ($sections as $r)
        {
            $sec = (string) $r['section'];

            if ($sec === '' OR in_array($sec, $ignore, TRUE))
            {
                continue;
            }

            $out[$sec] = [
                'section' => $sec,
                'views'   => (int) $r['views'],
                'jobs'    => 0, 'done' => 0, 'failed' => 0,
                'lost'    => FALSE,
            ];
        }

        foreach ($jobs as $r)
        {
            $sec = (string) (self::channel_section_map()[(string) $r['channel']] ?? '');

            if ($sec === '')
            {
                continue;
            }

            if ( ! isset($out[$sec]))
            {
                $out[$sec] = ['section' => $sec, 'views' => 0, 'jobs' => 0, 'done' => 0, 'failed' => 0, 'lost' => FALSE];
            }

            $out[$sec]['jobs']   = (int) $r['total'];
            $out[$sec]['done']   = (int) $r['done'];
            $out[$sec]['failed'] = (int) $r['failed'];
        }

        foreach ($out as $sec => $row)
        {
            $out[$sec]['lost'] = ($row['views'] >= 3 AND $row['done'] === 0);
        }

        usort($out, function ($a, $b) {
            return [$b['views'], $b['jobs']] <=> [$a['views'], $a['jobs']];
        });

        return array_slice($out, 0, 14);
    }

    /** MCP: популярность модулей (агентам). */
    public static function mcp_module_stats($args = [])
    {
        return ['modules_7d' => self::module_stats()];
    }

    // ------------------------------------------- карточка владельца (админ)

    /**
     * Полный компактный профиль владельца для карточки /nj600/admin/owner:
     * все разделы (не топ-5), фразы, источники, utm, ИИ-резюме. NULL — профиля нет.
     */
    public static function profile_full($owner_key)
    {
        self::ensure_tables();
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_600_profile WHERE owner_key = '.$db->quote((string) $owner_key), FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        $row = (array) $row;
        $sections = json_decode((string) $row['sections'], TRUE) ?: [];
        arsort($sections);
        $hosts = json_decode((string) $row['ref_hosts'], TRUE) ?: [];
        arsort($hosts);

        return [
            'owner'          => (string) $row['owner_key'],
            'sections'       => $sections,
            'phrases'        => json_decode((string) $row['phrases'], TRUE) ?: [],
            'ref_hosts'      => $hosts,
            'utm_last'       => json_decode((string) $row['utm_last'], TRUE) ?: [],
            'pages_total'    => (int) $row['pages_total'],
            'searches_total' => (int) $row['searches_total'],
            'first_seen'     => (string) $row['first_seen'],
            'last_seen'      => (string) $row['last_seen'],
            'llm_summary'    => (string) ($row['llm_summary'] ?? ''),
            'llm_at'         => (string) ($row['llm_at'] ?? ''),
            'llm_model'      => (string) ($row['llm_model'] ?? ''),
        ];
    }

    /** Последние сырые события владельца (пока живут по TTL) — лента «что делал». */
    public static function owner_events($owner_key, $limit = 60)
    {
        self::ensure_tables();
        $db = self::_db();

        return $db->query(Database::SELECT,
            'SELECT id, section, action, phrase, ref_host, utm_source, utm_medium, utm_campaign, uri, created_at
             FROM nj_600_events WHERE visitor_uid = '.$db->quote((string) $owner_key).'
             ORDER BY id DESC LIMIT '.max(1, min(500, (int) $limit)), FALSE)->as_array();
    }

    /** Активность владельца по дням (последние $days дней) — точки, не полосы. */
    public static function owner_days($owner_key, $days = 14)
    {
        self::ensure_tables();
        $db = self::_db();
        $days = max(1, min(60, (int) $days));

        $rows = $db->query(Database::SELECT,
            'SELECT date_trunc(\'day\', created_at)::date AS d, count(*) AS c
             FROM nj_600_events WHERE visitor_uid = '.$db->quote((string) $owner_key).'
               AND created_at > now() - interval \''.$days.' days\'
             GROUP BY d ORDER BY d', FALSE)->as_array();

        $out = [];

        foreach ($rows as $r)
        {
            $out[(string) $r['d']] = (int) $r['c'];
        }

        return $out;
    }

    // ------------------------------------------------------------ MCP

    /** MCP: агрегаты платформы (агентам; без персональных данных). */
    public static function mcp_platform_top($args = [])
    {
        $s = self::admin_stats();

        return [
            'events_24h'   => $s['events_24h'],
            'profiles'     => $s['profiles'],
            'top_sections' => array_map(function ($r) { return [$r['section'], (int) $r['cnt']]; }, $s['top_sections']),
            'top_phrases'  => array_map(function ($r) { return [$r['phrase'], (int) $r['cnt']]; }, $s['top_phrases']),
        ];
    }

    /** MCP: профиль конкретного владельца (owner_key вида u:1 / g:<ubid>). */
    public static function mcp_profile_by_key($args = [])
    {
        $key = (string) ($args['owner_key'] ?? '');

        if ( ! preg_match('/^(u:\d+|g:[a-z0-9]{6,40})$/i', $key))
        {
            return ['error' => 'owner_key ожидается вида u:<id> или g:<ubid>'];
        }

        return self::profile($key);
    }
}
