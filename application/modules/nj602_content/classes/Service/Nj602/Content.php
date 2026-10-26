<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Контент-конвейер» (nj602_content): воркер собирает в интернете интересный
 * контекст по теме, LLM делает из него «конфетку» — статью на русском
 * (тело в Markdown-лайт, промпт для будущей иллюстрации, mermaid-диаграмма,
 * граф сущностей для сетевой диаграммы, источники). Публикация — через
 * модерацию: status review → управление жмёт «Опубликовать» (авто-режим —
 * NJ602_AUTO_PUBLISH=1, включим позже).
 *
 * Статья: nj_602_articles. Генерация — воркер nj602.article
 * (workers/n2j_workers/handlers/nj602_article.py), расписание — «Пиналка»
 * (nj603, конфиг schedule 'content').
 */
class Service_Nj602_Content
{
    /** Спец-тема «собери сам из интернет-трендов» (воркер раскрывает). */
    const TOPIC_AUTO = '__AUTO_TRENDS__';

    public static function cfg($key, $default = NULL)
    {
        $cfg = Kohana::$config->load('nj602_content');

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
        if (trim((string) getenv('NJ602_SCHEMA_AUTOCREATE')) !== '1')
        {
            return;
        }

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_602_articles" (
    "id"           BIGSERIAL PRIMARY KEY,
    "code"         VARCHAR(12)  NOT NULL UNIQUE,
    "slug"         VARCHAR(140) NOT NULL UNIQUE,
    "topic"        VARCHAR(300) NOT NULL DEFAULT '',
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "summary"      VARCHAR(600) NOT NULL DEFAULT '',
    "body_md"      TEXT         NULL,
    "image_prompt" TEXT         NULL,
    "image_file"   VARCHAR(300) NOT NULL DEFAULT '',
    "mermaid"      TEXT         NULL,
    "graph"        JSONB        NULL,
    "tags"         JSONB        NULL,
    "sources"      JSONB        NULL,
    "status"       VARCHAR(12)  NOT NULL DEFAULT 'collecting',
    "error"        TEXT         NULL,
    "job_id"       BIGINT       NULL,
    "model_used"   VARCHAR(120) NOT NULL DEFAULT '',
    "usage"        JSONB        NULL,
    "published_at" TIMESTAMPTZ  NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"  TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_602_articles_status_idx" ON "nj_602_articles" ("status", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_602_articles_pub_idx" ON "nj_602_articles" ("published_at" DESC);
COMMENT ON TABLE "nj_602_articles" IS 'NJ-602 автостатьи: collecting|review|published|rejected|error, тело Markdown, mermaid + граф для сетевой диаграммы';
COMMENT ON COLUMN "nj_602_articles"."image_prompt" IS 'промпт для генерации иллюстрации (изображения подключим позже)';
COMMENT ON COLUMN "nj_602_articles"."graph" IS '{"nodes":[{"id","label"}],"edges":[{"s","t"}]} — сетевая диаграмма JS';
SQL
        )->execute();

        $done = TRUE;
    }

    // ------------------------------------------------- настройки конвейера

    /** Предопределённый редакционный каталог тем (config/predefined_topics). */
    public static function predefined_topics()
    {
        return array_values((array) self::cfg('predefined_topics', []));
    }

    /**
     * Настройки конвейера (nj_602_settings): enabled, topics (активные из
     * каталога; пусто = весь каталог), custom (свои темы администратора).
     */
    public static function settings()
    {
        self::ensure_tables();
        $db = self::_db();

        $rows = $db->query(Database::SELECT,
            'SELECT key, value FROM nj_602_settings', FALSE)->as_array();

        $raw = [];

        foreach ($rows as $r)
        {
            $raw[(string) $r['key']] = json_decode((string) $r['value'], TRUE);
        }

        $predefined = self::predefined_topics();
        $topics = array_values(array_filter((array) ($raw['topics'] ?? []), 'is_string'));

        return [
            'enabled' => ! isset($raw['enabled']) OR self::_truthy($raw['enabled']),
            // пустой список = весь каталог (галочки управление ещё не трогал)
            'topics'  => empty($topics) ? $predefined : array_values(array_intersect($topics, $predefined)),
            'custom'  => array_slice(array_values(array_filter((array) ($raw['custom'] ?? []), 'is_string')), 0, 50),
        ];
    }

    protected static function _truthy($v)
    {
        return $v === TRUE OR $v === 't' OR $v === 1 OR $v === '1' OR $v === 'true';
    }

    /** Сохранить настройки из админки. Возвращает нормализованный массив. */
    public static function save_settings(array $topics, array $custom, $enabled)
    {
        self::ensure_tables();
        $db = self::_db();
        $predefined = self::predefined_topics();

        $topics = array_values(array_unique(array_intersect(
            array_map('strval', $topics), $predefined)));
        $custom = array_values(array_unique(array_filter(array_map(function ($t) {
            return mb_substr(trim((string) $t), 0, 300);
        }, $custom), function ($t) { return $t !== ''; })));
        $custom = array_slice($custom, 0, 50);
        $enabled = (bool) $enabled;

        foreach (['enabled' => $enabled, 'topics' => $topics, 'custom' => $custom] as $key => $val)
        {
            $db->query(Database::INSERT,
                'INSERT INTO nj_602_settings (key, value, updated_at)
                 VALUES ('.$db->quote($key).', '.$db->quote(json_encode($val, JSON_UNESCAPED_UNICODE)).'::jsonb, now())
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()', FALSE);
        }

        return self::settings();
    }

    // ------------------------------------------------------------ слаг

    /** Транслит заголовка → латинский слаг (пусто → код статьи). */
    public static function slugify($title, $fallback)
    {
        $t = (string) $title;
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $t = mb_strtolower($t);
        $t = strtr($t, $map);
        $t = preg_replace('~[^a-z0-9]+~', '-', $t);
        $t = trim((string) $t, '-');

        if ($t === '')
        {
            $t = (string) $fallback;
        }

        return mb_substr($t, 0, 120);
    }

    /** Уникальный слаг: если занят другой статьёй — суффикс -2, -3… */
    protected static function _unique_slug($base, $exclude_id = 0)
    {
        $db   = self::_db();
        $base = mb_substr($base, 0, 120);
        $slug = $base;
        $n    = 1;

        while (TRUE)
        {
            $row = $db->query(Database::SELECT,
                'SELECT id FROM nj_602_articles WHERE slug = '.$db->quote($slug), FALSE)->current();

            if ( ! $row OR (int) $row['id'] === (int) $exclude_id)
            {
                return $slug;
            }

            $n++;
            $slug = $base.'-'.$n;
        }
    }

    // -------------------------------------------------------- создание

    /**
     * Тема для прогона: явная → ротация активных тем → авто-тренды.
     *
     * Ротация LRU: выбираем активную тему (настройки админа; пусто = весь
     * каталог + свои темы), по которой дольше всего не было статьи — конвейер
     * сам обходит весь каталог вместо повторов.
     */
    public static function pick_topic($explicit = '')
    {
        $explicit = trim((string) $explicit);

        if ($explicit !== '')
        {
            return mb_substr($explicit, 0, 300);
        }

        $s = self::settings();
        $pool = array_merge($s['topics'], $s['custom']);

        if (empty($pool))
        {
            // Каталог пуст и управление ничего не выбрал — автономный режим.
            return self::TOPIC_AUTO;
        }

        $db = self::_db();
        $rows = $db->query(Database::SELECT,
            "SELECT topic, max(created_at) AS last_used
             FROM nj_602_articles
             WHERE topic IN (" . implode(',', array_map([$db, 'quote'], $pool)) . ")
             GROUP BY topic", FALSE)->as_array();

        $used = [];

        foreach ($rows as $r)
        {
            $used[(string) $r['topic']] = (string) $r['last_used'];
        }

        $pick = NULL;
        $pick_at = NULL;

        foreach ($pool as $t)
        {
            // темы без статей — самые свежие кандидаты (last_used = epoch)
            $at = $used[$t] ?? '1970-01-01';

            if ($pick === NULL OR $at < $pick_at)
            {
                $pick = $t;
                $pick_at = $at;
            }
        }

        return (string) $pick;
    }

    /**
     * Брифинг воркера (GET-логика POST /nj602/api/brief, токен воркера):
     * состояние конвейера, подсказка темы, интересы аудитории (обезличенный
     * агрегат nj600), последние опубликованные заголовки (память «что уже
     * писали»). Воркер сам добирает RAG-заметки через /knowledge_search.
     */
    public static function brief_for_worker()
    {
        $s = self::settings();
        $recent = [];

        foreach (self::latest_public(10) as $a)
        {
            $recent[] = ['title' => (string) $a['title'], 'topic' => (string) $a['topic']];
        }

        $audience = ['sections' => [], 'phrases' => []];

        if (class_exists('Service_Nj600_Prefs'))
        {
            try
            {
                $audience = Service_Nj600_Prefs::aggregate_for_content();
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj602: аудитория недоступна: :e', [':e' => $e->getMessage()]);
            }
        }

        return [
            'enabled'   => $s['enabled'],
            'suggested' => $s['enabled'] ? self::pick_topic() : '',
            'topics'    => array_slice(array_merge($s['topics'], $s['custom']), 0, 40),
            'audience'  => $audience,
            'recent'    => $recent,
        ];
    }

    /**
     * Создать черновик и поставить задачу воркеру.
     * Защита: пока предыдущая статья собирается — новую не ставим.
     */
    /** Зависшие «собираются» (>2 ч) — в ошибку. Вызывается из create_draft и admin. */
    public static function sweep_stale(): int
    {
        $res = self::_db()->query(Database::INSERT,
            "UPDATE nj_602_articles SET status = 'error',
                error = COALESCE(NULLIF(error, ''), 'сбор прервался (таймаут конвейера)'),
                finished_at = now(), updated_at = now()
             WHERE status = 'collecting' AND created_at < now() - interval '2 hours'", FALSE);

        return is_int($res) ? $res : 0; // UPDATE у драйвера = число строк
    }

    public static function create_draft($topic, $source = 'auto')
    {
        self::ensure_tables();

        $db = self::_db();

        // рубильник «Конвейер работает»: расписание и кнопка админки уважают его
        if ( ! self::settings()['enabled'])
        {
            return ['error' => 'Конвейер выключен администратором (галочка «Конвейер работает» в /nj602/admin)'];
        }

        // самопомощь: воркер умер до fail (перезапуск, сеть) — сбор старше 2 ч в ошибку,
        // чтобы конвейер не встал навсегда («предыдущая статья ещё собирается»)
        self::sweep_stale();

        $busy = $db->query(Database::SELECT,
            "SELECT code FROM nj_602_articles WHERE status = 'collecting' ORDER BY id DESC LIMIT 1", FALSE)->current();

        if ($busy)
        {
            return ['error' => 'Предыдущая статья ещё собирается ('.$busy['code'].') — подождите завершения'];
        }

        $topic = self::pick_topic($topic);

        if ($topic === '')
        {
            return ['error' => 'Тема не задана: укажите её или заполните topics в config/nj602_content.php'];
        }

        $code = (string) self::cfg('code_prefix', 'cnt').bin2hex(random_bytes((int) self::cfg('code_bytes', 4)));
        $row  = $db->query(Database::SELECT,
            'INSERT INTO nj_602_articles (code, slug, topic, status, progress)
             VALUES ('.$db->quote($code).', '.$db->quote($code).', '.$db->quote($topic).', \'collecting\', \'[]\'::jsonb)
             RETURNING id, code', FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось создать черновик статьи'];
        }

        $job = NULL;

        if (class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $job = Service_Nj120_JobQueue::push('nj602.article', [
                    'article_id' => (int) $row['id'],
                    'code'       => $code,
                    'topic'      => $topic,
                ], [
                    'visitor_id'   => 's:nj602',
                    'module'       => 'nj602',
                    'max_attempts' => 1,
                    'timeout_sec'  => (int) self::cfg('job_timeout_sec', 900),
                ]);
            }
            catch (Throwable $e)
            {
                // Очередь отказала (бюджет токенов, БД) — статья НЕ висит
                // «собирается» с пустым журналом: сразу `error` с причиной,
                // которую видно на странице статьи и в /nj602/admin
                // (25.10: отказ по бюджету был только в логе сервера).
                Kohana::$log->add(Log::WARNING, 'nj602: dispatch: :e', [':e' => $e->getMessage()]);
                $why = 'Задача не поставлена в очередь: '.mb_substr($e->getMessage(), 0, 400);
                $db->query(Database::UPDATE,
                    'UPDATE nj_602_articles SET status = \'error\', error = '.$db->quote($why).',
                        finished_at = now(), updated_at = now() WHERE id = '.(int) $row['id'], FALSE);

                return ['error' => $why, 'code' => $code, 'topic' => $topic, 'job' => NULL];
            }
        }

        $db->query(Database::INSERT,
            'UPDATE nj_602_articles SET job_id = '.($job ? (int) $job : 'NULL').'
             WHERE id = '.(int) $row['id'], FALSE);

        return ['ok' => TRUE, 'code' => $code, 'topic' => $topic, 'job' => $job];
    }

    // ------------------------------------------------- приём от воркера

    /**
     * Результат воркера: статья целиком. Статус — review (модерация) или
     * published сразу (NJ602_AUTO_PUBLISH=1 — включим позже, решение владельца).
     */
    public static function api_save($code, array $d)
    {
        self::ensure_tables();
        $db = self::_db();

        $row = $db->query(Database::SELECT,
            "SELECT id, slug, job_id FROM nj_602_articles WHERE code = ".$db->quote((string) $code)." AND status = 'collecting'", FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Статья '.$code.' не найдена или уже принята'];
        }

        $title = mb_substr(trim((string) ($d['title'] ?? '')), 0, 300);

        if ($title === '')
        {
            return ['error' => 'Воркер прислал статью без заголовка'];
        }

        $slug = self::_unique_slug(self::slugify($title, $code), (int) $row['id']);
        $auto = trim((string) getenv('NJ602_AUTO_PUBLISH')) === '1';
        $st   = $auto ? 'published' : 'review';

        $graph  = (array) ($d['graph'] ?? []);
        $graph_sql = empty($graph) ? 'NULL' : $db->quote(json_encode($graph, JSON_UNESCAPED_UNICODE)).'::jsonb';
        $tags   = (array) ($d['tags'] ?? []);
        $tags_sql  = empty($tags) ? 'NULL' : $db->quote(json_encode(array_map('strval', $tags), JSON_UNESCAPED_UNICODE)).'::jsonb';
        $srcs   = (array) ($d['sources'] ?? []);
        $srcs_sql  = empty($srcs) ? 'NULL' : $db->quote(json_encode($srcs, JSON_UNESCAPED_UNICODE)).'::jsonb';
        $usage  = (array) ($d['usage'] ?? []);
        $usage_sql = empty($usage) ? 'NULL' : $db->quote(json_encode($usage, JSON_UNESCAPED_UNICODE)).'::jsonb';

        // исследовательский конвейер (миграция 004): фактлист и метрика качества
        $facts   = array_slice((array) ($d['facts'] ?? []), 0, 80);
        $facts_sql = empty($facts) ? 'NULL' : $db->quote(json_encode($facts, JSON_UNESCAPED_UNICODE)).'::jsonb';
        $quality = (array) ($d['quality'] ?? []);
        $quality_sql = empty($quality) ? 'NULL' : $db->quote(json_encode($quality, JSON_UNESCAPED_UNICODE)).'::jsonb';

        // финальный журнал прогона от воркера (если есть) или чистая пустота
        $progress  = (array) ($d['progress'] ?? []);
        $progress_sql = empty($progress) ? 'NULL' : $db->quote(json_encode($progress, JSON_UNESCAPED_UNICODE)).'::jsonb';

        $db->query(Database::INSERT,
            'UPDATE nj_602_articles SET
                topic = '.$db->quote(mb_substr(trim((string) ($d['topic'] ?? '')), 0, 300)).',
                title = '.$db->quote($title).',
                slug = '.$db->quote($slug).',
                summary = '.$db->quote(mb_substr(trim((string) ($d['summary'] ?? '')), 0, 600)).',
                body_md = '.$db->quote(mb_substr((string) ($d['body_md'] ?? ''), 0, 60000)).',
                image_prompt = '.$db->quote(mb_substr((string) ($d['image_prompt'] ?? ''), 0, 2000)).',
                mermaid = '.$db->quote(mb_substr((string) ($d['mermaid'] ?? ''), 0, 8000)).',
                graph = '.$graph_sql.',
                tags = '.$tags_sql.',
                sources = '.$srcs_sql.',
                model_used = '.$db->quote(mb_substr((string) ($d['model_used'] ?? ''), 0, 120)).',
                usage = '.$usage_sql.',
                facts = '.$facts_sql.',
                quality = '.$quality_sql.',
                progress = '.$progress_sql.',
                status = '.$db->quote($st).',
                published_at = '.($auto ? 'now()' : 'NULL').',
                error = NULL, finished_at = now(), updated_at = now()
             WHERE id = '.(int) $row['id'], FALSE);

        // Обратная связь в очередь: статья ФАКТИЧЕСКИ принята сайтом — её задача
        // nj120 больше не должна висеть «Выполняется». Если воркер по любой
        // причине не выполнил complete() (пал после отправки, оборвался ответ),
        // закрываем строку изнутри приёмки — это тот же итог, который дал бы
        // complete(), и он идемпотентен. НЕ выполняется только при полном
        // отсутствии привязки задачи к статье.
        try
        {
            if (class_exists('Service_Nj120_JobQueue') AND (int) ($row['job_id'] ?? 0) > 0)
            {
                // mark_delivered, а не complete: статью принимает САЙТ (не воркер),
                // и к этому моменту задача могла быть уже возвращена свипером в
                // pending или отказана как «молчащая» за секунды до доставки
                // (так была потеряна статья #813: принята через 2 с после Ошибки).
                // mark_delivered идемпотентно приводит все три случая к «Готово».
                Service_Nj120_JobQueue::instance()->mark_delivered(
                    (int) $row['job_id'],
                    'Статья '.$code.' принята сайтом — задача закрыта из приёмки nj602',
                    ['ok' => TRUE, 'code' => $code, 'slug' => $slug, 'status' => $st],
                );
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj602: связать задачу с готовой статьёй не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        return ['ok' => TRUE, 'slug' => $slug, 'status' => $st];
    }

    /** Промежуточный статус воркера (пишем в error-поле нет — в title-заглушку не нужно; храним в error как «прогресс» только при ошибке). */
    /**
     * Повторить сбор недоделанной статьи (nj602_admin: кнопка «Повторить»).
     * error → collecting заново с той же темой; зависший collecting — тоже
     * можно перезапустить. Старая задача (если жива) перепишется job_id.
     */
    public static function retry($id)
    {
        self::ensure_tables();
        $db = self::_db();

        $a = $db->query(Database::SELECT,
            'SELECT id, code, topic, status FROM nj_602_articles WHERE id = '.(int) $id, FALSE)->current();

        if ( ! $a)
        {
            return ['error' => 'Статья не найдена'];
        }

        if ( ! in_array($a['status'], ['error', 'collecting'], TRUE))
        {
            return ['error' => 'Повторять нечего: статья в статусе «'.$a['status'].'»'];
        }

        $job = NULL;

        if (class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $job = Service_Nj120_JobQueue::push('nj602.article', [
                    'article_id' => (int) $a['id'],
                    'code'       => (string) $a['code'],
                    'topic'      => (string) $a['topic'],
                ], [
                    'visitor_id'   => 's:nj602',
                    'module'       => 'nj602',
                    'max_attempts' => 1,
                    'timeout_sec'  => (int) self::cfg('job_timeout_sec', 900),
                ]);
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj602: retry dispatch: :e', [':e' => $e->getMessage()]);
                $why = 'Задача не поставлена в очередь: '.mb_substr($e->getMessage(), 0, 400);
                $db->query(Database::UPDATE,
                    'UPDATE nj_602_articles SET status = \'error\', error = '.$db->quote($why).',
                        updated_at = now() WHERE id = '.(int) $a['id'], FALSE);

                return ['error' => $why];
            }
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_602_articles SET status = \'collecting\', error = NULL, '
            .'progress = \'[]\'::jsonb, '
            .'job_id = '.($job ? (int) $job : 'NULL').', updated_at = now()
             WHERE id = '.(int) $a['id'], FALSE);

        return ['ok' => TRUE, 'job' => $job];
    }

    /**
     * Статья целиком для воркера (перегенерация схемы) и для ручной правки
     * в админке: тело, схема, тема, служебные поля.
     */
    public static function article_payload($code)
    {
        self::ensure_tables();

        $r = self::_db()->query(Database::SELECT,
            'SELECT id, code, slug, topic, title, summary, body_md, mermaid, tags, status, error, updated_at
             FROM nj_602_articles WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $r ?: NULL;
    }

    /** То же, что article_payload(), но по id — для страницы ручной правки. */
    public static function article_payload_by_id($id)
    {
        self::ensure_tables();

        $r = self::_db()->query(Database::SELECT,
            'SELECT id, code, slug, topic, title, summary, body_md, mermaid, tags, status, error, updated_at
             FROM nj_602_articles WHERE id = '.(int) $id, FALSE)->current();

        return $r ?: NULL;
    }

    /**
     * Ручное сохранение статьи из админки (заголовок, лид, тело, схема).
     * Меняем только присланные поля: пустое значение поля «тело» не затирает
     * текст, но схему разрешено очистить (пустая строка = без схемы).
     */
    public static function save_manual($id, array $d)
    {
        self::ensure_tables();
        $db = self::_db();

        $a = $db->query(Database::SELECT,
            'SELECT id, code, title, status FROM nj_602_articles WHERE id = '.(int) $id, FALSE)->current();

        if ( ! $a)
        {
            return ['error' => 'Статья не найдена'];
        }

        $title = mb_substr(trim((string) ($d['title'] ?? '')), 0, 300);

        if ($title === '')
        {
            return ['error' => 'Заголовок не может быть пустым'];
        }

        $body = (string) ($d['body_md'] ?? '');

        if (mb_strlen(trim($body)) < 50)
        {
            return ['error' => 'Тело статьи слишком короткое — правьте текст, а не удаляйте его'];
        }

        $slug = self::_unique_slug(self::slugify($title, (string) $a['code']), (int) $a['id']);

        $db->query(Database::UPDATE,
            'UPDATE nj_602_articles SET
                title = '.$db->quote($title).',
                slug = '.$db->quote($slug).',
                summary = '.$db->quote(mb_substr(trim((string) ($d['summary'] ?? '')), 0, 600)).',
                body_md = '.$db->quote(mb_substr($body, 0, 60000)).',
                mermaid = '.$db->quote(mb_substr(trim((string) ($d['mermaid'] ?? '')), 0, 8000)).',
                updated_at = now()
             WHERE id = '.(int) $a['id'], FALSE);

        return ['ok' => TRUE, 'slug' => $slug, 'title' => $title];
    }

    /**
     * Сохранение ТОЛЬКО схемы: перегенерация воркером или правка в админке.
     * Статус, тело и заголовок не трогаются — статью это не «перезапускает».
     */
    public static function save_mermaid($code, $mmd)
    {
        self::ensure_tables();

        if (self::by_code((string) $code) === NULL)
        {
            return ['error' => 'Статья '.$code.' не найдена'];
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_602_articles SET
                mermaid = '.self::_db()->quote(mb_substr(trim((string) $mmd), 0, 8000)).',
                updated_at = now()
             WHERE code = '.self::_db()->quote((string) $code), FALSE);

        return ['ok' => TRUE];
    }

    /**
     * Перегенерация mermaid-схемы: ставим воркеру ту же задачу конвейера, но
     * в режиме «только схема» (payload mode=mermaid). Текст, статус и
     * публикация статьи не меняются.
     */
    public static function regen_mermaid($id)
    {
        self::ensure_tables();

        $a = self::_db()->query(Database::SELECT,
            'SELECT id, code, topic, title, body_md, status FROM nj_602_articles WHERE id = '.(int) $id, FALSE)->current();

        if ( ! $a)
        {
            return ['error' => 'Статья не найдена'];
        }

        if (trim((string) $a['body_md']) === '')
        {
            return ['error' => 'У статьи ещё нет текста — схему строить не по чему'];
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна — воркер не запустится'];
        }

        // не плодим дубли: активная перегенерация по этой статье уже есть?
        try
        {
            $active = self::_db()->query(Database::SELECT,
                "SELECT id FROM nj_120_jobs
                 WHERE channel = 'nj602.article' AND payload->>'mode' = 'mermaid'
                   AND payload->>'code' = ".self::_db()->quote((string) $a['code'])."
                   AND status IN ('pending', 'processing')
                 ORDER BY id DESC LIMIT 1", FALSE)->current();

            if ($active)
            {
                return ['ok' => TRUE, 'job' => (int) $active['id'], 'code' => (string) $a['code'],
                        'already' => TRUE, 'flash' => 'Схема уже перегенерируется — дождитесь финала'];
            }
        }
        catch (Throwable $e)
        {
            // таблицы задач может не быть — уйдём в обычную постановку
        }

        $job = NULL;

        try
        {
            $job = Service_Nj120_JobQueue::push('nj602.article', [
                'article_id' => (int) $a['id'],
                'code'       => (string) $a['code'],
                'topic'      => (string) $a['topic'],
                'mode'       => 'mermaid',
            ], [
                'visitor_id'   => 's:nj602',
                'module'       => 'nj602',
                'max_attempts' => 1,
                'timeout_sec'  => 600,
            ]);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось поставить задачу: '.$e->getMessage()];
        }

        return ['ok' => TRUE, 'job' => $job, 'code' => (string) $a['code'],
                'flash' => 'Схема перегенерируется — обновится сама'];
    }

    /**
     * Годится ли схема к показу: рисует ли её mermaid.
     *
     * Дешёвая проверка (без рендера): у «нерисующихся» схем либо нет объявления
     * `flowchart/graph` первой строкой, либо всё склеено в одну строку, либо
     * есть «&»-склейки, второй `flowchart` в теле, табы, серединные подписи
     * («A -- текст --> B»), незакрытые кавычки или кавычка после закрывающей
     * скобки фигуры («D{"рост}"» — живой случай 26.09). Ровно такие примеры прислал
     * владелец 26.09 («много схем не отрисовываются»).
     */
    public static function mermaid_looks_broken($mmd)
    {
        $t = trim((string) $mmd);

        if ($t === '')
        {
            return FALSE; // схемы нет — это не «сломанная схема»
        }

        // без кавычек внутри подписей не считаем: проверяем «тело» схемы
        $flat = preg_replace('~"[^"]*"~u', '""', $t);

        if ( ! preg_match('~^\s*(?:flowchart|graph)\s+(?:TD|TB|LR|RL|BT)\b~i', $t))
        {
            return TRUE;
        }

        // второе объявление диаграммы (склейка двух схем)
        if (preg_match_all('~\b(?:flowchart|graph)\s+(?:TD|TB|LR|RL|BT)\b~i', $flat) > 1)
        {
            return TRUE;
        }

        if (preg_match('~&~', $flat))
        {
            return TRUE;
        }

        if (strpos($t, "\t") !== FALSE)
        {
            return TRUE;
        }

        // серединные подписи стрелок: «-- текст -->»
        if (preg_match('~--\s+[^-|>\n]+?\s+-->~u', $flat) OR preg_match('~==\s+[^=|>\n]+?\s+==>~u', $flat))
        {
            return TRUE;
        }

        // одна длинная строка вместо построчной схемы
        $lines = preg_split('~\r?\n~', $t);
        $n = 0;

        foreach ((array) $lines AS $ln)
        {
            if (trim($ln) !== '')
            {
                $n++;
            }
        }

        if ($n <= 1 AND mb_strlen($t) > 120)
        {
            return TRUE;
        }

        // непарные кавычки — mermaid падает на разборе
        if (substr_count($t, '"') % 2 !== 0)
        {
            return TRUE;
        }

        // кавычка ПОСЛЕ закрывающей скобки фигуры («D{"рост}"» вместо
        // «D{"рост"}») — mermaid 10.9.3 падает с «Syntax error in text».
        // Живой случай 26.09: «C --> D{"Направления развития}"».
        // Проверяем по исходнику ($t): в $flat содержимое кавычек уже стёрто.
        if (preg_match('~\{"[^"{}]*\}"~u', $t)
            OR preg_match('~\["[^"\[\]]*\]"~u', $t)
            OR preg_match('~\("[^"()]*\)"~u', $t))
        {
            return TRUE;
        }

        return FALSE;
    }

    /**
     * Статьи со «сломанными» схемами: коды для пакетной починки.
     *
     * Смотрим mermaid всех статей с непустой схемой (тело не тянем — это
     * тяжёлый TEXT) и возвращаем коды тех, что mermaid не нарисует.
     */
    public static function mermaid_suspects($limit = 300)
    {
        self::ensure_tables();

        $rows = self::_db()->query(Database::SELECT,
            "SELECT code, mermaid FROM nj_602_articles
              WHERE mermaid IS NOT NULL AND btrim(mermaid) <> ''
              ORDER BY updated_at DESC NULLS LAST, id DESC
              LIMIT ".(int) max(1, min((int) $limit, 2000)), FALSE)->as_array();

        $codes = [];

        foreach ($rows AS $r)
        {
            if (self::mermaid_looks_broken($r['mermaid']))
            {
                $codes[] = (string) $r['code'];
            }
        }

        return $codes;
    }

    /**
     * Сколько схем модерация не увидит — ДЕШЁВАЯ оценка по базе (для бейджа).
     *
     * Тексты схем не тянем: то же условие считается прямо в SQL. Точный список
     * (для самой починки) даёт mermaid_suspects() — он проверяет каждую схему
     * в PHP и запускается только по кнопке.
     */
    public static function mermaid_broken_count()
    {
        self::ensure_tables();

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) AS n FROM nj_602_articles
                  WHERE mermaid IS NOT NULL AND btrim(mermaid) <> ''
                    AND (
                          mermaid !~* '^[[:space:]]*(flowchart|graph)[[:space:]]+(TD|TB|LR|RL|BT)'
                       OR mermaid ~ '&'
                       OR mermaid LIKE '%' || chr(9) || '%'
                       OR position(chr(10) in mermaid) = 0
                       OR (length(mermaid) - length(replace(mermaid, 'flowchart', ''))) >= 18
                       OR (length(mermaid) - length(replace(mermaid, chr(34), ''))) % 2 = 1
                       OR mermaid ~ '[{]\"[^\"{}]*[}]\"'
                       OR mermaid ~ '[(]\"[^\"()]*[)]\"'
                    )", FALSE)->current();

            return (int) ($row['n'] ?? 0);
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    /**
     * Пакетная починка схем: ставим ОДНУ задачу воркеру (mode=mermaid_fix).
     *
     * Без ИИ: воркер нормализует сохранённый текст схемы (однострочники,
     * «&»-склейки, вторые объявления) и возвращает исправленное на сайт.
     * $ids — поставить только для выбранных статей (пусто — все подозрительные).
     */
    public static function repair_mermaids(array $ids = [])
    {
        self::ensure_tables();

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна — воркер не запустится'];
        }

        $codes = [];

        if ($ids)
        {
            $clean = [];

            foreach ($ids AS $id)
            {
                if ((int) $id > 0)
                {
                    $clean[] = (int) $id;
                }
            }

            if ($clean)
            {
                $rows = self::_db()->query(Database::SELECT,
                    'SELECT code, mermaid FROM nj_602_articles WHERE id IN ('.implode(',', $clean).')', FALSE)->as_array();

                foreach ($rows AS $r)
                {
                    $codes[] = (string) $r['code'];
                }
            }
        }
        else
        {
            $codes = self::mermaid_suspects(300);
        }

        if ( ! $codes)
        {
            return ['ok' => TRUE, 'count' => 0, 'already' => FALSE,
                    'flash' => 'Сломанных схем не нашлось — все схемы рисуются'];
        }

        // не плодим дубли: активная починка уже есть?
        try
        {
            $active = self::_db()->query(Database::SELECT,
                "SELECT id FROM nj_120_jobs
                 WHERE channel = 'nj602.article' AND payload->>'mode' = 'mermaid_fix'
                   AND status IN ('pending', 'processing')
                 ORDER BY id DESC LIMIT 1", FALSE)->current();

            if ($active)
            {
                return ['ok' => TRUE, 'count' => count($codes), 'already' => TRUE,
                        'job' => (int) $active['id'], 'flash' => 'Починка схем уже идёт — дождитесь финала'];
            }
        }
        catch (Throwable $e)
        {
            // таблицы задач может не быть — уйдём в обычную постановку
        }

        try
        {
            $job = Service_Nj120_JobQueue::push('nj602.article', [
                'mode'   => 'mermaid_fix',
                'codes'  => $codes,
                'code'   => $codes[0],
            ], [
                'visitor_id'   => 's:nj602',
                'module'       => 'nj602',
                'max_attempts' => 1,
                'timeout_sec'  => 900,
            ]);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось поставить задачу: '.$e->getMessage()];
        }

        return ['ok' => TRUE, 'count' => count($codes), 'job' => (int) $job, 'already' => FALSE,
                'flash' => 'Чиню схемы: '.count($codes).' шт. — ряд прогресса ниже'];
    }

    /**
     * Живое состояние пакетной починки схем для страницы модерации.
     *
     * @return array{status:string,stage:string,elapsed_sec:int,error:string,done:int,fixed:int}
     */
    public static function mermaid_fix_status()
    {
        $empty = ['status' => 'none', 'stage' => '', 'elapsed_sec' => 0, 'error' => '',
                  'total' => 0, 'fixed' => 0, 'skipped' => 0];

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return $empty;
        }

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT id FROM nj_120_jobs
                 WHERE channel = 'nj602.article' AND payload->>'mode' = 'mermaid_fix'
                 ORDER BY id DESC LIMIT 1", FALSE)->current();

            if ( ! $row)
            {
                return $empty;
            }

            $live = Service_Nj120_JobQueue::instance()->live_status('nj602.article', NULL, NULL, (int) $row['id']);

            $job = Service_Nj120_JobQueue::instance()->get((int) $row['id']);
            $res = isset($job['result']) ? $job['result'] : NULL;
            is_string($res) AND $res = json_decode($res, TRUE);
            $res = is_array($res) ? $res : [];

            return [
                'status'      => (string) ($live['status'] ?? 'none'),
                'stage'       => (string) ($live['stage'] ?? ''),
                'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
                'error'       => (string) ($live['error'] ?? ''),
                'total'       => (int) ($res['total'] ?? 0),
                'fixed'       => (int) ($res['fixed'] ?? 0),
                'skipped'     => (int) ($res['skipped'] ?? 0),
            ];
        }
        catch (Throwable $e)
        {
            return $empty;
        }
    }

    /**
     * Состояние перегенерации схемы по коду статьи (для опроса со страницы):
     * {status, stage, elapsed_sec, error} — контракт статус-ряда /trends.
     */
    public static function mermaid_job_status($code)
    {
        if ( ! class_exists('Service_Nj120_JobQueue') OR (string) $code === '')
        {
            return NULL;
        }

        try
        {
            $j = self::_db()->query(Database::SELECT,
                "SELECT id FROM nj_120_jobs
                 WHERE channel = 'nj602.article' AND payload->>'mode' = 'mermaid'
                   AND payload->>'code' = ".self::_db()->quote((string) $code)."
                 ORDER BY id DESC LIMIT 1", FALSE)->current();

            if ( ! $j)
            {
                return NULL;
            }

            return Service_Nj120_JobQueue::instance()->status_of((int) $j['id']);
        }
        catch (Throwable $e)
        {
            return NULL;
        }
    }

    public static function api_progress($code, $phase, $note = '', array $trace = NULL)
    {
        self::ensure_tables();
        $db  = self::_db();
        $code = (string) $code;

        // Воркер шлёт нарастающий журнал целиком (поле trace) — берём его как
        // есть; иначе добавляем одну метку «phase» к существующему журналу.
        if ($trace !== NULL)
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_602_articles SET
                    progress = '.$db->quote(json_encode($trace, JSON_UNESCAPED_UNICODE)).'::jsonb,
                    updated_at = now()
                 WHERE code = '.$db->quote($code)." AND status IN ('collecting', 'review', 'error', 'published', 'rejected')", FALSE);
        }
        else
        {
            $mark = json_encode([['t' => time(), 'phase' => $phase, 'note' => mb_substr((string) $note, 0, 300)]], JSON_UNESCAPED_UNICODE);
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_602_articles SET
                    progress = COALESCE(progress, \'[]\'::jsonb) || '.$db->quote($mark).'::jsonb,
                    updated_at = now()
                 WHERE code = '.$db->quote($code)." AND status = 'collecting'", FALSE);
        }

        return ['ok' => TRUE];
    }

    public static function api_fail($code, $error, array $trace = NULL)
    {
        self::ensure_tables();
        $db = self::_db();

        $row = $db->query(Database::SELECT,
            "SELECT id, job_id FROM nj_602_articles WHERE code = ".$db->quote((string) $code), FALSE)->current();

        $progress_sql = $trace !== NULL
            ? 'progress = '.$db->quote(json_encode($trace, JSON_UNESCAPED_UNICODE)).'::jsonb,'
            : '';

        self::_db()->query(Database::UPDATE,
            "UPDATE nj_602_articles SET status = 'error',
                ".$progress_sql."
                error = ".self::_db()->quote(mb_substr((string) $error, 0, 2000)).",
                finished_at = now(), updated_at = now()
             WHERE code = ".self::_db()->quote((string) $code)." AND status = 'collecting'", FALSE);

        // Ошибка статьи — финальное состояние: её задача nj120 не должна
        // оставаться «Выполняется». Если воркер не домаркировал fail()
        // (упал/оборвался ответ после /fail), закрываем строку изнутри.
        try
        {
            if (class_exists('Service_Nj120_JobQueue') AND $row AND (int) ($row['job_id'] ?? 0) > 0)
            {
                Service_Nj120_JobQueue::instance()->fail(
                    (int) $row['job_id'],
                    'site_nj602',
                    mb_substr((string) $error, 0, 500),
                    FALSE,
                );
            }
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj602: связать задачу с ошибкой статьи не удалось: :e',
                [':e' => $e->getMessage()]);
        }

        return ['ok' => TRUE];
    }

    // --------------------------------------------------------- выдача

    public static function by_code($code)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_602_articles WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function by_slug($slug)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            "SELECT * FROM nj_602_articles WHERE slug = ".self::_db()->quote((string) $slug)."
             AND status IN ('published', 'review')", FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Лента опубликованных (публичная). */
    public static function latest_public($limit = 30)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            "SELECT code, slug, title, summary, tags, image_file, published_at
             FROM nj_602_articles WHERE status = 'published'
             ORDER BY published_at DESC, id DESC LIMIT ".(int) $limit, FALSE)->as_array();
    }

    /** Список для модерации (свежие сверху, все статусы). */
    public static function admin_list($limit = 60, $offset = 0, $q = '', $status = '')
    {
        self::ensure_tables();

        $db = self::_db();
        $rows = $db->query(Database::SELECT,
            'SELECT id, code, slug, topic, title, summary, status, error, model_used, progress, job_id,
                    quality, created_at, published_at
             FROM nj_602_articles '.self::_admin_where($q, $status).'
             ORDER BY id DESC LIMIT '.(int) $limit.' OFFSET '.(int) $offset, FALSE)->as_array();

        // к каждой статье — живой статус задачи очереди (nj_120_jobs), чтобы
        // было видно, «выполнено» ли воркером или очередь до сих пор считает
        // задачу выполняющейся.
        foreach ($rows as $i => $r)
        {
            $rows[$i]['job_status'] = (int) ($r['job_id'] ?? 0) > 0
                ? self::job_status((int) $r['job_id'])
                : NULL;
        }

        return $rows;
    }

    /** Сколько статей под текущим фильтром — для пагинации модерации. */
    public static function admin_count($q = '', $status = '')
    {
        self::ensure_tables();

        $r = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS n FROM nj_602_articles '.self::_admin_where($q, $status), FALSE)->current();

        return $r ? (int) $r['n'] : 0;
    }

    /**
     * Условие выборки статей: поиск по заголовку, теме, коду и слагу плюс
     * фильтр по статусу. Собирается одним местом, чтобы список, счётчик и
     * AJAX-обновление строк видели ОДИН и тот же набор статей.
     */
    protected static function _admin_where($q = '', $status = '')
    {
        $db = self::_db();
        $cond = [];

        $status = trim((string) $status);

        if ($status !== '' AND in_array($status, ['published', 'review', 'collecting', 'rejected', 'error'], TRUE))
        {
            $cond[] = 'status = '.$db->quote($status);
        }

        $q = trim((string) $q);

        if ($q !== '')
        {
            $like = $db->quote('%'.$q.'%');
            $cond[] = '(title ILIKE '.$like.' OR topic ILIKE '.$like
                .' OR code ILIKE '.$like.' OR slug ILIKE '.$like.')';
        }

        return $cond ? 'WHERE '.implode(' AND ', $cond) : '';
    }

    /**
     * Массовое опубликование отмеченных статей (галочки в модерации).
     * Публикуем только то, что ждёт модерации или было отклонено, — уже
     * опубликованные не трогаем, дата публикации у них своя.
     */
    public static function publish_many(array $ids)
    {
        self::ensure_tables();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));

        if ( ! $ids)
        {
            return ['error' => 'Ни одна статья не отмечена'];
        }

        $res = self::_db()->query(Database::INSERT,
            "UPDATE nj_602_articles SET status = 'published', published_at = now(), updated_at = now()
             WHERE id IN (".implode(',', $ids).") AND status IN ('review', 'rejected')", FALSE);

        return ['ok' => TRUE, 'count' => is_int($res) ? $res : 0];
    }

    /** Массовое отклонение отмеченных статей (ждёт модерации или опубликована). */
    public static function reject_many(array $ids)
    {
        self::ensure_tables();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($i) { return $i > 0; })));

        if ( ! $ids)
        {
            return ['error' => 'Ни одна статья не отмечена'];
        }

        $res = self::_db()->query(Database::INSERT,
            "UPDATE nj_602_articles SET status = 'rejected', updated_at = now()
             WHERE id IN (".implode(',', $ids).") AND status IN ('review', 'published')", FALSE);

        return ['ok' => TRUE, 'count' => is_int($res) ? $res : 0];
    }

    /** Статус задачи очереди по id (или NULL, если очереди нет/задача удалена). */
    public static function job_status($job_id)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        try
        {
            $j = Service_Nj120_JobQueue::instance()->get((int) $job_id);

            return $j ? (string) $j['status'] : NULL;
        }
        catch (Throwable $e)
        {
            return NULL;
        }
    }

    /** Журнал прогона статьи — массив меток [{t, phase, note}]. */
    public static function progress_of($code)
    {
        if ($code === '')
        {
            return [];
        }

        $self = self::_db()->query(Database::SELECT,
            'SELECT progress FROM nj_602_articles WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        if ( ! $self OR empty($self['progress']))
        {
            return [];
        }

        $p = json_decode((string) $self['progress'], TRUE);

        return is_array($p) ? $p : [];
    }

    /**
     * Живой статус сбора статьи — для ОБЩЕГО статус-ряда (эталон /trends/<code>,
     * «Собираем обзор»). Возвращает тот же контракт, что
     * Service_Nj120_JobQueue::live_status(): status/stage/stages/elapsed/silent.
     *
     * Зачем: в админке журнал прогона был «списком меток», а процесс выглядел
     * иначе, чем во всех остальных модулях. Владелец: «давай оформим визуально
     * процесс Сбора статьи сейчас так же, как в „Собираем обзор“».
     */
    public static function live_of($code)
    {
        $empty = [
            'found'       => FALSE,
            'code'        => (string) $code,
            'job_id'      => 0,
            'status'      => 'none',
            'stage'       => '',
            'stages'      => [],
            'elapsed_sec' => 0,
            'silent_sec'  => NULL,
            'error'       => '',
            'article'     => NULL,
        ];

        $a = self::by_code((string) $code);

        if ($a === NULL)
        {
            return $empty;
        }

        $empty['article'] = $a;
        $job_id = (int) ($a['job_id'] ?? 0);

        if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            // Задачи нет вовсе: статья либо уже готова, либо черновик застрял
            // «собирается» без задачи (воркер не поставлен) — считаем время от
            // создания, чтобы ряд честно показал, сколько это длится.
            if ((string) $a['status'] === 'collecting')
            {
                $started = strtotime((string) ($a['created_at'] ?? ''));

                // ВАЖНО: array_merge, а не «+» — в объединении массивов левый
                // операнд побеждает, и статус остался бы 'none'.
                return array_merge($empty, [
                    'found'       => TRUE,
                    'status'      => 'queued',
                    'stage'       => 'Задача ещё не поставлена в очередь',
                    'elapsed_sec' => $started ? max(0, time() - $started) : 0,
                ]);
            }

            return $empty;
        }

        try
        {
            $live = Service_Nj120_JobQueue::instance()->live_status('nj602.article', NULL, NULL, $job_id);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj602: live_of: :e', [':e' => $e->getMessage()]);

            return $empty;
        }

        if (empty($live['found']) OR (string) $live['status'] === 'none')
        {
            return $empty;
        }

        // Журнал прогона (progress) — этапы для ряда: тот же список, что видит
        // модератор в «Живом журнале», только последние строки.
        $steps = [];

        foreach (self::progress_of((string) $code) as $m)
        {
            $t     = (int) ($m['t'] ?? 0);
            $phase = trim((string) ($m['phase'] ?? ''));
            $note  = trim((string) ($m['note'] ?? ''));
            $text  = trim($phase.($note !== '' ? ' — '.$note : ''));

            if ($text === '')
            {
                continue;
            }

            $steps[] = [
                'message' => ($t > 0 ? date('H:i:s', $t).' · ' : '').$text,
            ];
        }

        return [
            'found'       => TRUE,
            'code'        => (string) $code,
            'job_id'      => $job_id,
            'status'      => (string) $live['status'],
            'stage'       => (string) ($live['stage'] !== '' ? $live['stage'] : 'Задача в очереди — ждём воркера…'),
            'stages'      => array_slice($steps, -6),
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'error'       => (string) $live['error'],
            'article'     => $a,
        ];
    }

    /**
     * Сверить статью с её задачей в очереди — «сбор не может висеть вечно».
     *
     * Раньше задача могла закрыться (авто-стоп молчащего воркера, свипер,
     * отказ по попыткам), а статья оставалась в статусе «собирается» — и тогда
     * конвейер отказывался собирать новую («Предыдущая статья ещё собирается»),
     * а модератор видел вечное «собирается» без причины. Идемпотентно, дешёво:
     * зовётся при открытии админки и при поллинге статус-ряда.
     */
    public static function reconcile_article($code)
    {
        $a = self::by_code((string) $code);

        if ($a === NULL OR (string) $a['status'] !== 'collecting')
        {
            return;
        }

        $job_id = (int) ($a['job_id'] ?? 0);

        if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return;
        }

        try
        {
            $j = Service_Nj120_JobQueue::instance()->get($job_id);
        }
        catch (Throwable $e)
        {
            return;
        }

        if ( ! $j)
        {
            return;
        }

        $st   = (string) $j['status'];
        $text = '';

        if ($st === 'cancelled')
        {
            $why  = trim((string) ($j['error'] ?? ''));
            $text = $why !== '' ? $why : 'Сбор остановлен — запустите статью заново';
        }
        elseif ($st === 'failed')
        {
            $why  = trim((string) ($j['error'] ?? ''));
            $text = $why !== '' ? $why : 'Воркер не смог собрать статью — запустите заново';
        }
        elseif ($st === 'done')
        {
            // Задача закрыта «сделано», а статья так и не пришла приёмкой —
            // честный финал вместо бесконечного «собирается».
            $text = 'Воркер завершил задачу, но статья не дошла до сайта — запустите сбор заново';
        }

        if ($text === '')
        {
            return;
        }

        self::_db()->query(Database::UPDATE,
            "UPDATE nj_602_articles SET status = 'error',
                error = ".self::_db()->quote(mb_substr($text, 0, 2000)).",
                finished_at = COALESCE(finished_at, now()), updated_at = now()
             WHERE code = ".self::_db()->quote((string) $code)." AND status = 'collecting'", FALSE);
    }

    /** Остановить идущий сбор статьи (кнопка «Прервать сборку» в статус-ряде). */
    public static function stop_job($code)
    {
        $a = self::by_code((string) $code);

        if ($a === NULL)
        {
            return ['error' => 'Статья не найдена'];
        }

        $job_id = (int) ($a['job_id'] ?? 0);

        if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'У этой статьи нет задачи в очереди'];
        }

        try
        {
            $n = Service_Nj120_JobQueue::instance()->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить сбор: '.$e->getMessage()];
        }

        self::reconcile_article((string) $code);

        return ['ok' => TRUE, 'cancelled' => (int) $n];
    }

    public static function counts()
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            "SELECT count(*) FILTER (WHERE status = 'published') AS published,
                    count(*) FILTER (WHERE status = 'review')    AS review,
                    count(*) FILTER (WHERE status = 'collecting') AS collecting,
                    count(*) FILTER (WHERE status = 'error')     AS error,
                    count(*)                                     AS total
             FROM nj_602_articles", FALSE)->current();

        return $row ? (array) $row : [];
    }

    // ------------------------------------------------------ модерация

    public static function publish($id)
    {
        self::ensure_tables();

        self::_db()->query(Database::INSERT,
            "UPDATE nj_602_articles SET status = 'published', published_at = now(), updated_at = now()
             WHERE id = ".(int) $id." AND status IN ('review', 'rejected')", FALSE);

        return ['ok' => TRUE];
    }

    public static function reject($id)
    {
        self::ensure_tables();

        self::_db()->query(Database::INSERT,
            "UPDATE nj_602_articles SET status = 'rejected', updated_at = now()
             WHERE id = ".(int) $id." AND status = 'review'", FALSE);

        return ['ok' => TRUE];
    }

    // ------------------------------------------------------------ MCP

    /** MCP: последние опубликованные статьи (для агентов). */
    public static function mcp_latest($args = [])
    {
        $limit = max(1, min(10, (int) ($args['limit'] ?? 5)));
        $out   = [];

        foreach (self::latest_public($limit) as $a)
        {
            $out[] = [
                'slug'     => (string) $a['slug'],
                'title'    => (string) $a['title'],
                'summary'  => (string) $a['summary'],
                'url'      => 'https://n2j.ru/content/'.(string) $a['slug'],
                'published'=> (string) $a['published_at'],
            ];
        }

        return ['articles' => $out];
    }

    /** MCP: одна статья целиком (текст, диаграмма, граф, источники). */
    public static function mcp_article($args = [])
    {
        $slug = (string) ($args['slug'] ?? '');
        $r    = ($slug !== '') ? self::by_slug($slug) : NULL;

        if ($r === NULL)
        {
            return ['error' => 'Статья не найдена (slug?)'];
        }

        return [
            'title'        => (string) $r['title'],
            'summary'      => (string) $r['summary'],
            'body_md'      => (string) $r['body_md'],
            'mermaid'      => (string) $r['mermaid'],
            'graph'        => json_decode((string) $r['graph'], TRUE),
            'sources'      => json_decode((string) $r['sources'], TRUE),
            'url'          => 'https://n2j.ru/content/'.(string) $r['slug'],
        ];
    }

    public static function remove($id)
    {
        self::ensure_tables();

        self::_db()->query(Database::DELETE,
            'DELETE FROM nj_602_articles WHERE id = '.(int) $id, FALSE);

        return ['ok' => TRUE];
    }
}
