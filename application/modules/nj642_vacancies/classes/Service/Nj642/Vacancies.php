<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj642_vacancies: «Вакансии компаний» — сервисный слой.
 *
 * Владелец (26.09.2026): «та же ходи по страницам „Карьера“ „Вакансии“ „О
 * компании“ — эта важная информация, её можно обновлять и хранить историю
 * появления и исчезновения вакансий; по вакансиям от работодателя можно сделать
 * отдельный функционал (отдельный модуль): указать город, направление и
 * мониторить изменение вакансий на сайтах компаний, с уведомлением письмом,
 * связать онтологией с компанией и другими модулями».
 *
 * Как это работает:
 *   1. МОНИТОР (nj_642_watches) = «город + направление + ключевые слова +
 *      компании» у конкретного пользователя, с паузой и переключателем писем;
 *   2. сбор ставит задачу в очередь nj120 (канал nj642.vacancies): python-воркер
 *      читает страницы «Карьера / Вакансии / О компании» и моделью извлекает
 *      вакансии СТРОГО из текста страниц;
 *   3. сайт принимает их (/vacancies/api/ingest): вакансия живёт ОДНОЙ записью
 *      (отпечаток = компания + должность + город + ссылка), поэтому сборы не
 *      плодят дубли, а история появления/исчезновения копится в nj_642_events;
 *   4. подходящие вакансии связываются с мониторами (nj_642_matches), а о новых
 *      уходит письмо через nj597 (по правилам самого пользователя);
 *   5. компания попадает в общую онтологию (nj607) — связь с профилем компании
 *      и другими модулями.
 *
 * Вакансии собирает и профиль компании (nj591): те же страницы работодателя
 * уходят сюда, поэтому история не зависит от того, каким сервисом её смотрели.
 */
class Service_Nj642_Vacancies {

    const CHANNEL = 'nj642.vacancies';

    /** Статусы вакансии. */
    const V_ACTIVE = 'active';
    const V_GONE   = 'gone';

    /** Статусы связи «вакансия ↔ монитор». */
    const M_NEW    = 'new';
    const M_SAVED  = 'saved';
    const M_HIDDEN = 'hidden';

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
            $cfg = (array) Kohana::$config->load('nj642_vacancies');
        }

        return Arr::path($cfg, $key, $default);
    }

    /**
     * Страховка на деплой без миграций: модуль сам создаёт свои таблицы тем же
     * DDL, что миграция 001 (идемпотентно, каждое утверждение отдельно — ошибка
     * одной таблицы не мешает остальным).
     */
    protected static $_tables_ensured = FALSE;

    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        $stmts = [];

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_642_watches" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(16)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "title"       VARCHAR(200) NOT NULL DEFAULT '',
    "city"        VARCHAR(120) NOT NULL DEFAULT '',
    "direction"   VARCHAR(200) NOT NULL DEFAULT '',
    "keywords"    VARCHAR(300) NOT NULL DEFAULT '',
    "companies"   JSONB        NULL,
    "enabled"     BOOLEAN      NOT NULL DEFAULT TRUE,
    "notify"      BOOLEAN      NOT NULL DEFAULT TRUE,
    "min_score"   INTEGER      NOT NULL DEFAULT 55,
    "last_run_at" TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL;

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_642_vacancies" (
    "id"           BIGSERIAL PRIMARY KEY,
    "company_key"  VARCHAR(180) NOT NULL DEFAULT '',
    "company"      VARCHAR(200) NOT NULL DEFAULT '',
    "profile_code" VARCHAR(16)  NOT NULL DEFAULT '',
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "city"         VARCHAR(120) NOT NULL DEFAULT '',
    "direction"    VARCHAR(120) NOT NULL DEFAULT '',
    "salary"       VARCHAR(120) NOT NULL DEFAULT '',
    "url"          VARCHAR(600) NOT NULL DEFAULT '',
    "source_url"   VARCHAR(600) NOT NULL DEFAULT '',
    "snippet"      VARCHAR(1000) NOT NULL DEFAULT '',
    "fingerprint"  VARCHAR(64)  NOT NULL UNIQUE,
    "status"       VARCHAR(12)  NOT NULL DEFAULT 'active',
    "seen_count"   INTEGER      NOT NULL DEFAULT 1,
    "first_seen_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    "last_seen_at"  TIMESTAMPTZ NOT NULL DEFAULT now(),
    "gone_at"       TIMESTAMPTZ NULL,
    "updated_at"    TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL;

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_642_events" (
    "id"         BIGSERIAL PRIMARY KEY,
    "vacancy_id" BIGINT       NOT NULL,
    "watch_id"   BIGINT       NOT NULL DEFAULT 0,
    "kind"       VARCHAR(16)  NOT NULL DEFAULT '',
    "note"       VARCHAR(300) NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL;

        $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS "nj_642_matches" (
    "id"          BIGSERIAL PRIMARY KEY,
    "watch_id"    BIGINT       NOT NULL,
    "vacancy_id"  BIGINT       NOT NULL,
    "score"       INTEGER      NOT NULL DEFAULT 0,
    "why"         VARCHAR(300) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "notified_at" TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
)
SQL;

        $stmts[] = 'CREATE UNIQUE INDEX IF NOT EXISTS "nj_642_matches_uniq" ON "nj_642_matches" ("watch_id", "vacancy_id")';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_642_watches_user_idx" ON "nj_642_watches" ("user_id", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_642_vacancies_company_idx" ON "nj_642_vacancies" ("company_key", "status", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_642_events_vacancy_idx" ON "nj_642_events" ("vacancy_id", "id" DESC)';
        $stmts[] = 'CREATE INDEX IF NOT EXISTS "nj_642_matches_watch_idx" ON "nj_642_matches" ("watch_id", "status", "id" DESC)';

        foreach ($stmts as $sql)
        {
            try
            {
                DB::query(NULL, $sql)->execute();
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj642: таблицы вакансий: :e', [':e' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Мониторы
    // ------------------------------------------------------------------

    /**
     * Создать монитор вакансий.
     *
     * @param  int   $user_id
     * @param  array $in title, city, direction, keywords, companies[], notify, min_score
     * @return array [ok, code] | [error]
     */
    public static function create_watch($user_id, array $in)
    {
        self::ensure_tables();

        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return ['error' => 'Сервис для зарегистрированных — войдите.'];
        }

        $city      = mb_substr(trim((string) ($in['city'] ?? '')), 0, 120);
        $direction = mb_substr(trim((string) ($in['direction'] ?? '')), 0, 200);
        $keywords  = mb_substr(trim((string) ($in['keywords'] ?? '')), 0, 300);
        $companies = self::_clean_companies($in['companies'] ?? []);

        // Компании указывать НЕ обязательно: владелец (27.09.2026) — «режим: только
        // город + направление, компании с сайтами сервис находит сам». Поэтому
        // обязательны город или направление, а сайты работодателей воркер
        // подберёт сам (карта отрасли + поиск) и покажет их в мониторе.
        if ($city === '' AND $direction === '' AND $keywords === '' AND $companies === [])
        {
            return ['error' => 'Укажите город и направление — компании с сайтами сервис найдёт сам.'];
        }

        $max = (int) self::_cfg('max_companies', 12);

        if (count($companies) > $max)
        {
            $companies = array_slice($companies, 0, $max);
        }

        if (self::count_of($user_id) >= (int) self::_cfg('max_watches', 10))
        {
            return ['error' => 'Мониторов уже '.(int) self::_cfg('max_watches', 10).' — удалите неактуальные, чтобы добавить новый.'];
        }

        $default_title = trim(($direction !== '' ? $direction : 'Вакансии').($city !== '' ? ' · '.$city : ''));
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120) ?: $default_title;
        $code  = 'vac'.bin2hex(random_bytes(4));
        $db    = self::_db();

        try
        {
            $db->query(Database::INSERT,
                'INSERT INTO nj_642_watches (code, user_id, title, city, direction, keywords, companies, notify, min_score)
                 VALUES ('.$db->quote($code).', '.$user_id.', '
                .$db->quote($title).', '.$db->quote($city).', '.$db->quote($direction).', '.$db->quote($keywords).', '
                .$db->quote(json_encode($companies, JSON_UNESCAPED_UNICODE)).'::jsonb, '
                .(( ! empty($in['notify']) AND $in['notify'] !== '0') ? 'TRUE' : 'FALSE').', '
                .max(0, min(100, (int) ($in['min_score'] ?? (int) self::_cfg('min_score', 55)))).')', FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj642: создать монитор: :e', [':e' => $e->getMessage()]);

            return ['error' => 'Не удалось создать монитор — попробуйте ещё раз.'];
        }

        // первый сбор — сразу: пользователь должен увидеть результат, а не ждать
        // расписания пиналки
        self::_enqueue($code);

        return ['ok' => TRUE, 'code' => $code];
    }

    /**
     * Привести компании монитора к строгому виду [{name, domain, profile_code}].
     *
     * @param bool $keep TRUE — сохранять служебные пометки автоподбора
     *                   (auto/source/why/found_at), чтобы владелец видел, откуда
     *                   компания взялась.
     */
    protected static function _clean_companies($in, $keep = FALSE)
    {
        $out = [];

        foreach ((array) $in as $c)
        {
            if (is_string($c))
            {
                $c = ['name' => $c, 'domain' => $c];
            }

            if ( ! is_array($c))
            {
                continue;
            }

            $domain = strtolower(trim((string) ($c['domain'] ?? '')));
            $domain = preg_replace('~^https?://~i', '', $domain);
            $domain = trim((string) preg_replace('~^www\.~i', '', (string) $domain), '/');

            if (strpos($domain, '/') !== FALSE)
            {
                $domain = (string) explode('/', $domain)[0];
            }

            $name = mb_substr(trim((string) ($c['name'] ?? '')), 0, 200);

            if ($domain === '' AND $name === '')
            {
                continue;
            }

            $key = $domain !== '' ? $domain : mb_strtolower($name);

            // дубли по ключу компании не плодим
            if (isset($out[$key]))
            {
                continue;
            }

            $row = [
                'name'         => $name !== '' ? $name : $domain,
                'domain'       => $domain,
                'profile_code' => mb_substr(trim((string) ($c['profile_code'] ?? '')), 0, 16),
            ];

            if ($keep)
            {
                foreach (['auto', 'source', 'why', 'found_at'] as $k)
                {
                    if ( ! isset($c[$k]) OR $c[$k] === '' OR $c[$k] === NULL)
                    {
                        continue;
                    }

                    $row[$k] = is_bool($c[$k]) ? $c[$k] : mb_substr(trim((string) $c[$k]), 0, 160);
                }
            }

            $out[$key] = $row;
        }

        return array_values($out);
    }

    /** Монитор по коду (или NULL). */
    public static function by_code($code)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_642_watches WHERE code = '.self::_db()->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function by_id($id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_642_watches WHERE id = '.(int) $id, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Компании монитора (нормализованный список). */
    public static function companies_of(array $watch)
    {
        $raw = $watch['companies'] ?? NULL;

        if (is_string($raw))
        {
            $raw = json_decode($raw, TRUE);
        }

        return self::_clean_companies(is_array($raw) ? $raw : [], TRUE);
    }

    /** Сколько мониторов у пользователя. */
    public static function count_of($user_id)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_642_watches WHERE user_id = '.(int) $user_id, FALSE)->current();

        return (int) ($row['c'] ?? 0);
    }

    /** Мониторы пользователя с числами для карточек. */
    public static function watches_of($user_id)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT w.*,
                    (SELECT count(*) FROM nj_642_matches m WHERE m.watch_id = w.id) AS total,
                    (SELECT count(*) FROM nj_642_matches m WHERE m.watch_id = w.id AND m.status = \'new\') AS fresh,
                    (SELECT count(*) FROM nj_642_matches m JOIN nj_642_vacancies v ON v.id = m.vacancy_id
                      WHERE m.watch_id = w.id AND v.status = \'active\') AS active_n
               FROM nj_642_watches w
              WHERE w.user_id = '.(int) $user_id.'
              ORDER BY w.id DESC', FALSE)->as_array();
    }

    /** Пауза/включение монитора. */
    public static function set_enabled($code, $user_id, $enabled, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return ['error' => 'Монитор не найден'];
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_642_watches SET enabled = '.($enabled ? 'TRUE' : 'FALSE').', updated_at = now()
             WHERE id = '.(int) $w['id'], FALSE);

        return ['ok' => TRUE];
    }

    /** Удалить монитор (связи уходят вместе с ним, вакансии остаются в истории). */
    public static function remove_watch($code, $user_id, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return ['error' => 'Монитор не найден'];
        }

        $db = self::_db();
        $db->query(Database::DELETE, 'DELETE FROM nj_642_matches WHERE watch_id = '.(int) $w['id'], FALSE);
        $db->query(Database::DELETE, 'DELETE FROM nj_642_watches WHERE id = '.(int) $w['id'], FALSE);

        return ['ok' => TRUE];
    }

    /**
     * Монитор в авто-режиме: сайты работодателей подбирает сервис.
     *
     * Владелец (27.09.2026): «режим: только город + направление, компании с
     * сайтами сервис находит сам — максимально автоматизируем, ориентируемся на
     * онтологию».
     *
     * Признак — не направление (оно есть почти у всех мониторов), а состав
     * компаний: пусто или все компании найдены сервисом (пометка `auto`). Так
     * монитор, собранный владельцем вручную по списку сайтов, не получает
     * «в подарок» чужие компании, а авто-режим продолжает дополнять список,
     * пока не наберёт цель.
     */
    public static function is_auto($watch)
    {
        $comps = self::companies_of($watch);

        if ($comps === [])
        {
            return trim((string) ($watch['city'] ?? '')) !== ''
                OR trim((string) ($watch['direction'] ?? '')) !== ''
                OR trim((string) ($watch['keywords'] ?? '')) !== '';
        }

        foreach ($comps as $c)
        {
            if (empty($c['auto']))
            {
                return FALSE;
            }
        }

        return TRUE;
    }

    /**
     * Сколько ещё компаний нужно найти авто-режиму: цель — autofind_target
     * (по умолчанию 8) в пределах потолка max_companies.
     */
    public static function autofind_slots($watch)
    {
        $have = count(self::companies_of($watch));
        $cap  = max(1, (int) self::_cfg('max_companies', 12));
        $want = min($cap, max(1, (int) self::_cfg('autofind_target', 8)));

        return max(0, $want - $have);
    }

    /** Задача сбора монитора в очередь nj120 (payload самодостаточен для воркера). */
    protected static function _enqueue($code, $force = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL)
        {
            return FALSE;
        }

        $comps = self::companies_of($w);
        $auto  = self::is_auto($w);

        if ( ! $force AND ( ! self::_enabled($w['enabled']) OR ($comps === [] AND ! $auto)))
        {
            // Монитор на паузе или без компаний и без направления: место для
            // вакансий неизвестно — задача была бы пустой работой воркера.
            // Авто-режиму (город+направление) пустой список компаний не помеха:
            // воркер подберёт сайты работодателей сам (иначе монитор молчал бы
            // вечно — именно это владелец и увидел как «ничего не происходит»).
            return FALSE;
        }

        if (method_exists('Service_Nj120_JobQueue', 'find_active')
            AND Service_Nj120_JobQueue::instance()->find_active(self::CHANNEL, 'watch_id', (int) $w['id']) > 0)
        {
            return FALSE;
        }

        try
        {
            Service_Nj120_JobQueue::push(self::CHANNEL, [
                'watch_id' => (int) $w['id'],
                'code'     => (string) $w['code'],
                'city'     => (string) $w['city'],
                'direction' => (string) $w['direction'],
                'keywords' => (string) $w['keywords'],
                'companies' => $comps,
                // mode=auto — «компаний может не быть, найди их сам»;
                // find — сколько сайтов работодателей ещё нужно подобрать.
                'mode'     => $auto ? 'auto' : 'sites',
                'find'     => $auto ? self::autofind_slots($w) : 0,
            ], [
                'module'       => 'nj642',
                'max_attempts' => 2,
                'timeout_sec'  => 1200,
                'created_by'   => (int) $w['user_id'],
            ]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj642: задача сбора: :e', [':e' => $e->getMessage()]);

            return FALSE;
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_642_watches SET last_run_at = now(), updated_at = now() WHERE id = '.(int) $w['id'], FALSE);

        return TRUE;
    }

    protected static function _enabled($v)
    {
        return $v === TRUE OR $v === 1 OR $v === '1' OR $v === 't' OR $v === 'true';
    }

    /** «Собрать сейчас» (владелец или управление). */
    public static function run_now($code, $user_id, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return ['error' => 'Монитор не найден'];
        }

        if (self::companies_of($w) === [] AND ! self::is_auto($w))
        {
            return ['error' => 'В мониторе нет компаний — добавьте сайт работодателя или направление.'];
        }

        return self::_enqueue($code, TRUE)
            ? ['ok' => TRUE]
            : ['error' => 'Сбор этого монитора уже идёт — дождитесь результата или нажмите «Остановить»'];
    }

    /** Остановить идущий сбор (владелец или управление). */
    public static function cancel_run($code, $user_id, $is_admin = FALSE)
    {
        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $w = self::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return ['error' => 'Монитор не найден'];
        }

        $queue  = Service_Nj120_JobQueue::instance();
        $job_id = method_exists($queue, 'find_active')
            ? $queue->find_active(self::CHANNEL, 'watch_id', (int) $w['id'])
            : 0;

        if ($job_id <= 0)
        {
            return ['error' => 'Активного сбора нет — останавливать нечего'];
        }

        try
        {
            $queue->cancel($job_id, TRUE);
        }
        catch (Throwable $e)
        {
            return ['error' => 'Не удалось остановить сбор'];
        }

        return ['ok' => TRUE, 'job_id' => $job_id];
    }

    /** Живой статус-ряд монитора (эталон /trends: статус, этап, секундомер, пульс). */
    public static function live_for($code, $user_id, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['found' => FALSE, 'status' => 'none', 'stage' => '', 'stages' => [],
                    'elapsed_sec' => 0, 'silent_sec' => NULL, 'error' => '', 'job_id' => 0];
        }

        return Service_Nj120_JobQueue::instance()->live_status(self::CHANNEL, 'watch_id', (int) $w['id']);
    }

    /** Последняя (в том числе завершённая) задача монитора — плашка «Готово»/«Ошибка». */
    public static function last_for($code, $user_id, $is_admin = FALSE)
    {
        $w = self::by_code($code);

        if ($w === NULL OR ( ! $is_admin AND (int) $w['user_id'] !== (int) $user_id))
        {
            return NULL;
        }

        if ( ! class_exists('Service_Nj120_JobQueue'))
        {
            return NULL;
        }

        $st = Service_Nj120_JobQueue::instance()->last_status(self::CHANNEL, 'watch_id', (int) $w['id']);

        return ($st AND ! empty($st['found'])) ? $st : NULL;
    }

    /** Сколько вакансий монитор получил с момента старта сбора («найдено: N»). */
    public static function found_since($watch_id, $since = NULL)
    {
        self::ensure_tables();

        $since = trim((string) $since);
        $where = $since !== '' ? ' AND m.created_at >= '.self::_db()->quote($since) : '';

        $row = self::_db()->query(Database::SELECT,
            'SELECT count(*) AS c FROM nj_642_matches m WHERE m.watch_id = '.(int) $watch_id.$where, FALSE)->current();

        return $row ? (int) $row['c'] : 0;
    }

    // ------------------------------------------------------------------
    // Пиналка (nj603) и воркер
    // ------------------------------------------------------------------

    /**
     * POST /vacancies/api/collect — поставить задачи «созревшим» мониторам.
     * Молодые (недавно собранные) пропускаем: сбор чужого сайта — не бесплатный.
     */
    public static function api_collect()
    {
        self::ensure_tables();

        $hours = max(1, (int) self::_cfg('every_hours', 12));

        $rows = self::_db()->query(Database::SELECT,
            "SELECT code FROM nj_642_watches
              WHERE enabled = TRUE
                AND (last_run_at IS NULL OR last_run_at < now() - interval '".$hours." hour')
              ORDER BY last_run_at NULLS FIRST, id
              LIMIT 20", FALSE)->as_array();

        $queued = 0;

        foreach ($rows as $r)
        {
            if (self::_enqueue((string) $r['code']))
            {
                $queued++;
            }
        }

        return ['ok' => TRUE, 'checked' => count($rows), 'queued' => $queued];
    }

    /**
     * POST /vacancies/api/ingest — приём вакансий от воркера.
     *
     * @param  array $in company, domain, profile_code, source_url, watch_id, full, pages_read, vacancies[]
     * @return array ['ok', 'added', 'restored', 'gone', 'matched', 'notified']
     */
    public static function api_ingest(array $in)
    {
        self::ensure_tables();

        $company      = mb_substr(trim((string) ($in['company'] ?? '')), 0, 200);
        $domain       = strtolower(trim((string) ($in['domain'] ?? '')));
        $profile_code = mb_substr(trim((string) ($in['profile_code'] ?? '')), 0, 16);
        $source_url   = mb_substr(trim((string) ($in['source_url'] ?? '')), 0, 600);
        $watch_id     = (int) ($in['watch_id'] ?? 0);
        $pages_read   = (int) ($in['pages_read'] ?? 0);
        $full         = ! empty($in['full']);
        $items        = is_array($in['vacancies'] ?? NULL) ? $in['vacancies'] : [];

        $company_key = self::company_key($company, $domain);

        if ($company_key === '')
        {
            return ['error' => 'Не указана компания (или её сайт) — вакансии некуда привязать'];
        }

        $db = self::_db();

        // Граница «этот сбор» для отметки исчезнувших берётся у САМОЙ базы:
        // часовой пояс PHP и PostgreSQL может не совпадать, и граница из PHP
        // отметила бы «исчезнувшими» только что прочитанные вакансии.
        $pass = date('Y-m-d H:i:s');

        try
        {
            $now = $db->query(Database::SELECT, 'SELECT now()::text AS t', FALSE)->current();

            if ($now AND ! empty($now['t']))
            {
                $pass = (string) $now['t'];
            }
        }
        catch (Throwable $e)
        {
            // время базы недоступно — сравниваем по времени PHP
        }

        $added   = 0;
        $restored = 0;
        $touched = [];
        $fresh    = [];   // вакансии, которые появились или вернулись — их показываем уведомлением

        foreach ($items as $raw)
        {
            if ( ! is_array($raw))
            {
                continue;
            }

            $title = mb_substr(trim((string) ($raw['title'] ?? '')), 0, 300);

            if (mb_strlen($title) < 3)
            {
                continue;
            }

            $city    = mb_substr(trim((string) ($raw['city'] ?? '')), 0, 120);
            $direct  = mb_substr(trim((string) ($raw['direction'] ?? '')), 0, 120);
            $salary  = mb_substr(trim((string) ($raw['salary'] ?? '')), 0, 120);
            $url     = mb_substr(trim((string) ($raw['url'] ?? '')), 0, 600);
            $snippet = mb_substr(trim((string) ($raw['snippet'] ?? '')), 0, 1000);

            $fp = self::fingerprint($company_key, $title, $city, $url);

            $prev = $db->query(Database::SELECT,
                'SELECT id, status FROM nj_642_vacancies WHERE fingerprint = '.$db->quote($fp), FALSE)->current();

            if ($prev)
            {
                $vacancy_id = (int) $prev['id'];
                $was_gone   = ((string) $prev['status'] === self::V_GONE);

                $db->query(Database::UPDATE,
                    "UPDATE nj_642_vacancies SET
                        last_seen_at = now(), seen_count = seen_count + 1,
                        status = '".self::V_ACTIVE."', gone_at = NULL,
                        city = ".$db->quote($city).", direction = ".$db->quote($direct).",
                        salary = CASE WHEN ".$db->quote($salary)." <> '' THEN ".$db->quote($salary).' ELSE salary END, '
                    .'snippet = CASE WHEN '.$db->quote($snippet)." <> '' THEN ".$db->quote($snippet).' ELSE snippet END, '
                    .'profile_code = CASE WHEN '.$db->quote($profile_code)." <> '' THEN ".$db->quote($profile_code).' ELSE profile_code END, '
                    .'source_url = CASE WHEN '.$db->quote($source_url)." <> '' THEN ".$db->quote($source_url).' ELSE source_url END, '
                    .'company = CASE WHEN '.$db->quote($company)." <> '' THEN ".$db->quote($company).' ELSE company END, '
                    .'updated_at = now()
                     WHERE id = '.$vacancy_id, FALSE);

                if ($was_gone)
                {
                    self::_event($vacancy_id, 0, 'restored', 'Вакансия снова открыта на сайте компании');
                    $restored++;
                    $fresh[] = $vacancy_id;
                }
            }
            else
            {
                $row = $db->query(Database::SELECT,
                    'INSERT INTO nj_642_vacancies
                        (company_key, company, profile_code, title, city, direction, salary, url, source_url, snippet, fingerprint)
                     VALUES ('.$db->quote($company_key).', '.$db->quote($company).', '.$db->quote($profile_code).', '
                    .$db->quote($title).', '.$db->quote($city).', '.$db->quote($direct).', '.$db->quote($salary).', '
                    .$db->quote($url).', '.$db->quote($source_url).', '.$db->quote($snippet).', '.$db->quote($fp).')
                     RETURNING id', FALSE)->current();

                if ( ! $row)
                {
                    continue;
                }

                $vacancy_id = (int) $row['id'];
                self::_event($vacancy_id, 0, 'appeared', 'Вакансия появилась на сайте компании');

                if ($watch_id > 0)
                {
                    self::_event($vacancy_id, $watch_id, 'found', 'Найдена сбором монитора');
                }

                $added++;
                $fresh[] = $vacancy_id;
            }

            $touched[$vacancy_id] = TRUE;
        }

        // Исчезнувшие: вакансии этой компании, которых не было в ЭТОМ сборе.
        // Отмечаем только когда сбор действительно прочитал страницы (full) —
        // иначе сбой сети выглядел бы как «все вакансии закрылись».
        $gone = 0;

        if ($full AND $pages_read > 0)
        {
            $where_keep = $touched ? ' AND id NOT IN ('.implode(',', array_map('intval', array_keys($touched))).')' : '';

            $rows = $db->query(Database::SELECT,
                "SELECT id FROM nj_642_vacancies
                  WHERE company_key = ".$db->quote($company_key)."
                    AND status = '".self::V_ACTIVE."'
                    AND last_seen_at < ".$db->quote($pass).'::timestamptz'.$where_keep, FALSE)->as_array();

            foreach ($rows as $r)
            {
                $db->query(Database::UPDATE,
                    "UPDATE nj_642_vacancies SET status = '".self::V_GONE."', gone_at = now(), updated_at = now()
                     WHERE id = ".(int) $r['id'], FALSE);

                self::_event((int) $r['id'], 0, 'gone', 'Вакансия больше не опубликована на сайте компании');
                $gone++;
            }
        }

        // Авто-режим: компания, у которой мы действительно прочитали вакансии,
        // становится компанией монитора — владелец видит, у кого именно нашлось.
        if ($watch_id > 0 AND $company_key !== '')
        {
            self::_remember_company($watch_id, $company, $domain, $profile_code);
        }

        // Подходящие вакансии → мониторы + письма о новых
        $matched  = self::_match($company_key, $company, $profile_code, $fresh, $watch_id);
        $notified = self::_notify($matched);

        // Онтология: компания с вакансиями — сущность общей карты знаний
        self::_ontology($company, $domain, $source_url, $company_key);

        // Завершение сбора воркер подтверждает отдельно (/vacancies/api/finish):
        // вакансии приходят по одной компании за запрос, а закрывать задачу
        // нужно один раз, когда обход закончен.

        return [
            'ok'        => TRUE,
            'company'   => $company_key,
            'added'     => $added,
            'restored'  => $restored,
            'gone'      => $gone,
            'matched'   => $matched,
            'notified'  => $notified,
            'found'     => count($fresh),
        ];
    }

    /**
     * POST /vacancies/api/companies — воркер принёс сайты работодателей, которые
     * нашёл сам (авто-режим: «только город + направление»).
     *
     * @param  array $in watch_id, source (поиск|карта отрасли|сбор), companies[{name,domain,why}]
     * @return array ['ok', 'added', 'total', 'companies']  (companies — итоговый список монитора)
     */
    public static function api_companies(array $in)
    {
        self::ensure_tables();

        $watch_id = (int) ($in['watch_id'] ?? 0);
        $source   = mb_substr(trim((string) ($in['source'] ?? 'поиск')), 0, 60);
        $w        = self::by_id($watch_id);

        if ($w === NULL)
        {
            return ['error' => 'Монитор не найден'];
        }

        if ( ! self::is_auto($w))
        {
            return ['error' => 'Монитор собран по списку компаний — автоподбор ему не нужен'];
        }

        $found = self::_clean_companies($in['companies'] ?? [], TRUE);
        $cap   = max(1, (int) self::_cfg('max_companies', 12));
        $have  = self::companies_of($w);
        $by    = [];

        foreach ($have as $c)
        {
            $by[self::company_key($c['name'] ?? '', $c['domain'] ?? '')] = $c;
        }

        $added = 0;

        foreach ($found as $c)
        {
            $key = self::company_key($c['name'] ?? '', $c['domain'] ?? '');

            if ($key === '')
            {
                continue;
            }

            if (isset($by[$key]))
            {
                // уже знаем эту компанию: дополняем недостающее (домен, пометку)
                foreach (['domain', 'source', 'why'] as $k)
                {
                    if (empty($by[$key][$k]) AND ! empty($c[$k]))
                    {
                        $by[$key][$k] = $c[$k];
                    }
                }

                continue;
            }

            if (count($by) >= $cap)
            {
                break;
            }

            $c['auto']     = TRUE;
            $c['source']   = trim((string) ($c['source'] ?? '')) !== '' ? (string) $c['source'] : $source;
            $c['found_at'] = date('d.m.y H:i');
            $by[$key]      = $c;
            $added++;
        }

        if ($added > 0)
        {
            $db = self::_db();

            $db->query(Database::UPDATE,
                'UPDATE nj_642_watches SET companies = '.$db->quote(json_encode(array_values($by), JSON_UNESCAPED_UNICODE))
                .'::jsonb, updated_at = now() WHERE id = '.$watch_id, FALSE);
        }

        return ['ok' => TRUE, 'added' => $added, 'total' => count($by), 'companies' => array_values($by)];
    }

    /** Компания монитора, найденная сбором (та же дверь, что и автоподбор). */
    protected static function _remember_company($watch_id, $name, $domain, $profile_code = '')
    {
        try
        {
            return self::api_companies([
                'watch_id'  => (int) $watch_id,
                'source'    => 'сбор',
                'companies' => [[
                    'name'         => (string) $name,
                    'domain'       => (string) $domain,
                    'profile_code' => (string) $profile_code,
                ]],
            ]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj642: компания монитора: :e', [':e' => $e->getMessage()]);

            return ['error' => 'Не удалось запомнить компанию'];
        }
    }

    /**
     * GET /vacancies/api/hints/<code> — кандидаты в компании монитора из НАШЕЙ
     * базы: сущности карты отрасли (nj607), профили компаний (nj591), находки
     * «Потока заказчиков» (nj606) и уже собранные вакансии. Воркер спрашивает их
     * ПЕРЕД поиском: своё знание дешевле и точнее любого поисковика, а онтология —
     * общий ориентир платформы (правило модуля: вакансии только с сайта
     * работодателя, агрегаторы запрещены).
     *
     * @return array [{name, domain, source, why}]
     */
    public static function discover_seed($code, $limit = 20)
    {
        self::ensure_tables();

        $w = self::by_code($code);

        if ($w === NULL OR ! self::is_auto($w))
        {
            return [];
        }

        $db    = self::_db();
        $uid   = (int) $w['user_id'];
        $limit = max(1, min((int) $limit, 40));
        $words = self::_search_words($w);
        $out   = [];

        // 1. Карта отрасли (nj607): компании, известные платформе. Фильтр по
        // словам направления и города — «повар», «Москва».
        if ($words)
        {
            $like = [];

            foreach ($words as $x)
            {
                $like[] = 'e.title ILIKE '.$db->quote('%'.$x.'%');
                $like[] = 'e.details ILIKE '.$db->quote('%'.$x.'%');
            }

            try
            {
                foreach ($db->query(Database::SELECT,
                    'SELECT e.title, e.domain, e.url FROM nj_607_entities e
                      WHERE e.kind = \'company\' AND ('.implode(' OR ', $like).')
                      ORDER BY e.updated_at DESC LIMIT '.$limit, FALSE)->as_array() as $r)
                {
                    $out[] = [
                        'name'   => (string) $r['title'],
                        'domain' => (string) $r['domain'],
                        'source' => 'карта отрасли',
                        'why'    => 'компания из карты отрасли (nj607)',
                    ];
                }
            }
            catch (Throwable $e)
            {
                // карты отрасли может не быть — не беда
            }
        }

        // 2. Свои данные владельца: профили компаний и прошлые находки потоков.
        try
        {
            foreach ($db->query(Database::SELECT,
                "SELECT company AS name, domain FROM nj_591_profiles
                  WHERE user_id = ".$uid." AND deleted_at IS NULL AND company <> '' AND domain <> ''
                  ORDER BY id DESC LIMIT ".$limit, FALSE)->as_array() as $r)
            {
                $out[] = ['name' => (string) $r['name'], 'domain' => (string) $r['domain'],
                          'source' => 'профиль компании', 'why' => 'из ваших профилей компаний'];
            }
        }
        catch (Throwable $e)
        {
            // профилей может не быть
        }

        try
        {
            foreach ($db->query(Database::SELECT,
                "SELECT company AS name, site AS domain FROM nj_606_leads
                  WHERE user_id = ".$uid." AND company <> '' AND site <> ''
                  ORDER BY id DESC LIMIT ".$limit, FALSE)->as_array() as $r)
            {
                $out[] = ['name' => (string) $r['name'], 'domain' => (string) $r['domain'],
                          'source' => 'поток заказчиков', 'why' => 'компания из ваших потоков (nj606)'];
            }
        }
        catch (Throwable $e)
        {
            // потоков может не быть
        }

        // 3. Компании, у которых вакансии уже собирались (любой пользователь):
        // проверенный источник — сайт работодателя уже читался.
        try
        {
            $empty = $db->quote('');

            foreach ($db->query(Database::SELECT,
                'SELECT company AS name, company_key AS domain, count(*) AS n FROM nj_642_vacancies
                  WHERE company <> '.$empty.' AND company_key <> '.$empty.'
                  GROUP BY company, company_key ORDER BY n DESC LIMIT '.$limit, FALSE)->as_array() as $r)
            {
                $out[] = ['name' => (string) $r['name'], 'domain' => (string) $r['domain'],
                          'source' => 'собранные вакансии', 'why' => 'у компании уже собирались вакансии'];
            }
        }
        catch (Throwable $e)
        {
            // пусто — значит пусто
        }

        // 4. Компании других мониторов с тем же направлением и городом.
        try
        {
            $same = $db->query(Database::SELECT,
                'SELECT companies FROM nj_642_watches
                  WHERE id <> '.(int) $w['id'].' AND coalesce(jsonb_array_length(companies), 0) > 0
                    AND lower(direction) = '.$db->quote(mb_strtolower((string) $w['direction'])).'
                    AND lower(city) = '.$db->quote(mb_strtolower((string) $w['city'])).'
                  ORDER BY id DESC LIMIT 10', FALSE)->as_array();

            foreach ($same as $row)
            {
                foreach (self::companies_of((array) $row) as $c)
                {
                    $out[] = ['name' => (string) $c['name'], 'domain' => (string) $c['domain'],
                              'source' => 'соседний монитор', 'why' => 'то же направление и город'];
                }
            }
        }
        catch (Throwable $e)
        {
            // нет соседей — не беда
        }

        // Дедуп: домен важнее названия; домены-агрегаторы отсекаем здесь же —
        // вакансии с job-бордов не считаются вакансиями работодателя.
        $seen = [];
        $res  = [];

        foreach ($out as $c)
        {
            $domain = strtolower(trim((string) $c['domain']));
            $domain = trim((string) preg_replace('~^https?://~i', '', $domain), '/');

            if ($domain !== '' AND strpos($domain, '/') !== FALSE)
            {
                $domain = (string) explode('/', $domain)[0];
            }

            if ($domain !== '' AND self::_blocked_host($domain))
            {
                continue;
            }

            $key = $domain !== '' ? $domain : mb_strtolower(trim((string) $c['name']));

            if ($key === '' OR isset($seen[$key]))
            {
                continue;
            }

            $seen[$key] = TRUE;
            $c['domain'] = $domain;
            $res[] = $c;
        }

        return array_slice($res, 0, $limit);
    }

    /** Слова направления/города/ключей для отбора кандидатов (без стоп-слов). */
    protected static function _search_words($watch)
    {
        $stop = ['работа', 'вакансии', 'вакансия', 'компания', 'компании', 'город', 'монитор',
                 'направление', 'сайт', 'сайты', 'ищу', 'поиск', 'нужны', 'требуются'];

        $raw = mb_strtolower(trim((string) $watch['direction'].' '.(string) $watch['keywords'].' '.(string) $watch['city']));
        $raw = (string) preg_replace('~[^\p{L}\p{N}]+~u', ' ', $raw);

        $words = [];

        foreach (explode(' ', $raw) as $x)
        {
            $x = trim($x);

            if (mb_strlen($x) < 4 OR in_array($x, $stop, TRUE) OR isset($words[$x]))
            {
                continue;
            }

            $words[$x] = TRUE;

            if (count($words) >= 6)
            {
                break;
            }
        }

        return array_keys($words);
    }

    /** Домен-агрегатор вакансий? (тот же список, что у воркера). */
    protected static function _blocked_host($host)
    {
        static $list = ['hh.ru', 'superjob', 'zarplata', 'avito.ru', 'trudvsem', 'rabota.ru',
                        'gorodrabot', 'job.ru', 'linkedin.com', 'indeed.com', 'glassdoor',
                        'wikipedia.org', 'yandex.', 'google.', 'duckduckgo.com', 'vk.com', 'ok.ru',
                        '2gis.', 'zoon.ru', 'irr.ru', 'olx.', 'youla', 'profi.ru', 'farpost',
                        'career.ru', 'jcat.ru', 'jobsora', 'jooble', 'rabota.', 'trud.com',
                        'facebook.', 'instagram.', 'youtube.', 't.me'];

        $host = mb_strtolower(trim((string) $host));

        if ($host === '')
        {
            return FALSE;
        }

        foreach ($list as $bad)
        {
            if (strpos($host, $bad) !== FALSE)
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * POST /vacancies/api/finish — воркер закончил обход: задача очереди должна
     * стать «Готово», а не висеть processing до таймаута (образец nj602/nj605).
     * Упавший на середине обхода воркер сюда не доходит — такую задачу закроет
     * авто-остановка по пульсу, и это честно видно на странице.
     */
    public static function finish_run($watch_id)
    {
        $watch_id = (int) $watch_id;

        if ($watch_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
        {
            return ['ok' => FALSE, 'error' => 'Нечего закрывать'];
        }

        try
        {
            $queue  = Service_Nj120_JobQueue::instance();
            $job_id = method_exists($queue, 'find_for_delivery')
                ? $queue->find_for_delivery(self::CHANNEL, 'watch_id', $watch_id)
                : 0;

            if ($job_id <= 0)
            {
                return ['ok' => FALSE, 'error' => 'Задача сбора не найдена'];
            }

            $queue->mark_delivered($job_id, 'Вакансии приняты сайтом');

            return ['ok' => TRUE, 'job_id' => $job_id];
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj642: дожим задачи сбора: :e', [':e' => $e->getMessage()]);

            return ['ok' => FALSE, 'error' => 'Не удалось закрыть задачу'];
        }
    }

    /**
     * Ключ компании по ПРОФИЛЮ компании (nj591).
     *
     * У профилей, собранных по названию, колонка domain пустая — настоящий сайт
     * виден только в источниках (список прочитанных страниц). Без этого чип
     * «Вакансии» на странице профиля не нашёл бы вакансии, собранные по домену.
     */
    public static function key_for_profile(array $p)
    {
        $key = self::company_key((string) ($p['company'] ?? ''), (string) ($p['domain'] ?? ''));

        if (trim((string) ($p['domain'] ?? '')) !== '')
        {
            return $key;
        }

        $sources = $p['sources'] ?? [];

        if (is_string($sources))
        {
            $sources = json_decode($sources, TRUE);
        }

        foreach ((array) $sources as $src)
        {
            $url = is_array($src) ? (string) ($src['url'] ?? '') : (string) $src;

            if ($url === '')
            {
                continue;
            }

            $host = (string) parse_url($url, PHP_URL_HOST);

            if ($host !== '')
            {
                return self::company_key('', ltrim($host, '.'));
            }
        }

        return $key;
    }

    /** Ключ компании: домен, иначе название. */
    public static function company_key($company, $domain = '')
    {
        $domain = strtolower(trim((string) $domain));
        $domain = (string) preg_replace('~^https?://~i', '', $domain);

        if (strpos($domain, '/') !== FALSE)
        {
            $domain = (string) explode('/', $domain)[0];
        }

        $domain = trim((string) preg_replace('~^www\.~i', '', $domain));

        if ($domain !== '')
        {
            return mb_substr($domain, 0, 180);
        }

        return mb_substr(mb_strtolower(trim((string) $company)), 0, 180);
    }

    /**
     * Отпечаток вакансии: компания + должность + город + путь ссылки. Запросы и
     * якоря ссылки отбрасываем — иначе счётчики в URL («?utm=…») плодили бы
     * «новые» вакансии на каждом сборе.
     */
    public static function fingerprint($company_key, $title, $city, $url)
    {
        $path = (string) $url;

        if ($path !== '')
        {
            $parts = parse_url($path);
            $path  = (string) (($parts['host'] ?? '').($parts['path'] ?? ''));
        }

        $norm = function ($s) {
            $s = mb_strtolower(trim((string) $s));
            $s = str_replace('ё', 'е', $s);

            return trim((string) preg_replace('~\s+~u', ' ', $s));
        };

        return sha1($norm($company_key).'|'.$norm($title).'|'.$norm($city).'|'.$norm($path));
    }

    /** Запись в историю вакансии. */
    protected static function _event($vacancy_id, $watch_id, $kind, $note = '')
    {
        try
        {
            $db = self::_db();
            $db->query(Database::INSERT,
                'INSERT INTO nj_642_events (vacancy_id, watch_id, kind, note) VALUES ('
                .(int) $vacancy_id.', '.(int) $watch_id.', '.$db->quote(mb_substr((string) $kind, 0, 16)).', '
                .$db->quote(mb_substr((string) $note, 0, 300)).')', FALSE);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj642: история вакансии: :e', [':e' => $e->getMessage()]);
        }
    }

    /**
     * Оценка «насколько вакансия подходит монитору».
     *
     * Без магии: совпал город — плюс, слова направления и ключевые слова нашли
     * своё место в должности/описании — плюс. Всё объяснимо: причина попадает в
     * `why` и показывается пользователю.
     *
     * @return array [score, why]
     */
    public static function score_of(array $watch, array $v)
    {
        $norm = function ($s) {
            $s = mb_strtolower(trim((string) $s));
            $s = str_replace('ё', 'е', $s);

            return trim((string) preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $s));
        };

        $score = 20;
        $why   = [];

        $city_w = $norm($watch['city'] ?? '');
        $city_v = $norm($v['city'] ?? '');

        if ($city_w === '')
        {
            $score += 15;
        }
        elseif ($city_v === '')
        {
            // город вакансии неизвестен — не отбрасываем, но и не награждаем
            $why[] = 'город не указан';
        }
        elseif (mb_strpos($city_v, $city_w) !== FALSE OR mb_strpos($city_w, $city_v) !== FALSE)
        {
            $score += 35;
            $why[] = 'город совпал: '.mb_substr((string) $v['city'], 0, 40);
        }
        else
        {
            return [0, 'другой город'];
        }

        $hay = $norm(($v['title'] ?? '').' '.($v['direction'] ?? '').' '.($v['snippet'] ?? ''));

        foreach (self::_tokens($watch['direction'] ?? '') as $t)
        {
            if (mb_strpos($hay, $t) !== FALSE)
            {
                $score += 14;
                $why[] = 'направление: '.$t;
            }
        }

        foreach (self::_tokens($watch['keywords'] ?? '') as $t)
        {
            if (mb_strpos($hay, $t) !== FALSE)
            {
                $score += 12;
                $why[] = 'слово: '.$t;
            }
        }

        if (trim((string) ($v['salary'] ?? '')) !== '')
        {
            $score += 5;
        }

        return [max(0, min(100, $score)), implode('; ', array_slice(array_unique($why), 0, 4))];
    }

    /** Слова для сопоставления: длиннее 3 знаков, без служебных. */
    protected static function _tokens($s)
    {
        $s = mb_strtolower((string) $s);
        $s = str_replace('ё', 'е', $s);
        $parts = preg_split('~[^\p{L}\p{N}]+~u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop  = ['для', 'или', 'the', 'and', 'или', 'это', 'все', 'работа', 'вакансии', 'сфера'];

        $out = [];

        foreach ($parts as $p)
        {
            if (mb_strlen($p) > 3 AND ! in_array($p, $stop, TRUE))
            {
                $out[$p] = $p;
            }
        }

        return array_slice(array_values($out), 0, 8);
    }

    /**
     * Связать вакансии с мониторами.
     *
     * @param  int   $watch_id  конкретный монитор (сбор по его заданию) или 0 —
     *                          тогда подходящие мониторы ищем по компаниям
     * @param  array $fresh_ids вакансии, которые появились/вернулись
     * @return array список совпадений для уведомления: [['watch'=>…, 'vacancy'=>…], …]
     */
    protected static function _match($company_key, $company, $profile_code, array $fresh_ids, $watch_id = 0)
    {
        $db = self::_db();

        if ($watch_id > 0)
        {
            $w = self::by_id($watch_id);
            $watches = $w ? [$w] : [];
        }
        else
        {
            $watches = [];

            foreach ($db->query(Database::SELECT,
                'SELECT * FROM nj_642_watches WHERE enabled = TRUE ORDER BY id DESC LIMIT 300', FALSE)->as_array() as $w)
            {
                foreach (self::companies_of($w) as $c)
                {
                    $key = self::company_key($c['name'] ?? '', $c['domain'] ?? '');

                    if ($key !== '' AND $key === $company_key)
                    {
                        $watches[] = $w;
                        break;
                    }
                }
            }
        }

        if ($watches === [] OR $fresh_ids === [])
        {
            return [];
        }

        $out = [];

        $rows = $db->query(Database::SELECT,
            'SELECT * FROM nj_642_vacancies WHERE id IN ('.implode(',', array_map('intval', $fresh_ids)).')', FALSE)->as_array();

        foreach ($rows as $v)
        {
            foreach ($watches as $w)
            {
                list($score, $why) = self::score_of($w, (array) $v);

                if ($score < (int) $w['min_score'])
                {
                    continue;
                }

                try
                {
                    $db->query(Database::INSERT,
                        'INSERT INTO nj_642_matches (watch_id, vacancy_id, score, why)
                         VALUES ('.(int) $w['id'].', '.(int) $v['id'].', '.(int) $score.', '
                        .$db->quote(mb_substr((string) $why, 0, 300)).')
                         ON CONFLICT (watch_id, vacancy_id) DO UPDATE SET score = EXCLUDED.score, why = EXCLUDED.why', FALSE);
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj642: связь вакансии с монитором: :e', [':e' => $e->getMessage()]);
                    continue;
                }

                $out[] = ['watch' => (array) $w, 'vacancy' => (array) $v, 'score' => (int) $score];
            }
        }

        return $out;
    }

    /**
     * Письмо о новых подходящих вакансиях — через nj597 (outbox + правила
     * пользователя). Один сбор — одно письмо на монитор, не чаще раза в час.
     */
    protected static function _notify(array $matched)
    {
        if ($matched === [] OR ! class_exists('Service_Nj597_Notify'))
        {
            return 0;
        }

        $by_watch = [];

        foreach ($matched as $m)
        {
            $by_watch[(int) $m['watch']['id']]['watch'] = $m['watch'];
            $by_watch[(int) $m['watch']['id']]['items'][] = $m;
        }

        $db   = self::_db();
        $sent = 0;
        $done = [];   // мониторы, по которым письмо реально ушло

        foreach ($by_watch as $group)
        {
            $w = $group['watch'];

            if ( ! self::_enabled($w['notify']))
            {
                continue;
            }

            $recent = $db->query(Database::SELECT,
                "SELECT id FROM nj_597_notifications
                  WHERE user_id = ".(int) $w['user_id']." AND kind = 'nj642_vacancies'
                    AND created_at > now() - interval '1 hour' LIMIT 1", FALSE)->current();

            // Вакансии не «звенят» каждый сбор: если письмо по монитору уже было
            // в этот час, новые совпадения просто ждут следующего.
            if ($recent)
            {
                continue;
            }

            $titles = [];

            foreach (array_slice($group['items'], 0, 3) as $m)
            {
                $titles[] = '«'.mb_substr((string) $m['vacancy']['title'], 0, 80).'»'
                    .(((string) $m['vacancy']['company'] !== '') ? ' — '.mb_substr((string) $m['vacancy']['company'], 0, 60) : '');
            }

            $n = count($group['items']);

            $word = class_exists('Service_Nj502_Words')
                ? Service_Nj502_Words::plural($n, 'новая вакансия', 'новые вакансии', 'новых вакансий')
                : 'новых вакансий';

            $body = implode('; ', $titles).($n > 3 ? ' и ещё '.(int) ($n - 3) : '');

            try
            {
                Service_Nj597_Notify::notify((int) $w['user_id'], 'nj642_vacancies',
                    'Вакансии: '.$n.' '.$word.' по монитору «'.mb_substr((string) $w['title'], 0, 80).'»',
                    $body, '/vacancies/'.(string) $w['code']);

                $done[] = (int) $w['id'];
                $sent++;
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj642: уведомление о вакансиях: :e', [':e' => $e->getMessage()]);
            }
        }

        // Отметку «о ней уже написали» ставим ТОЛЬКО тем мониторам, по которым
        // письмо ушло: пропущенные (часовой лимит, выключенные письма) обязаны
        // попасть в следующее письмо, а не потеряться молча.
        if ($done !== [])
        {
            $db->query(Database::UPDATE,
                'UPDATE nj_642_matches SET notified_at = now()
                  WHERE watch_id IN ('.implode(',', array_map('intval', $done)).')
                    AND notified_at IS NULL', FALSE);
        }

        return $sent;
    }

    /**
     * Онтология (nj607): компания с вакансиями становится сущностью общей карты
     * знаний и связывается с предметом модуля — списком вакансий этой компании.
     * Ошибка онтологии ничего не ломает: вакансии уже сохранены.
     */
    protected static function _ontology($company, $domain, $source_url, $company_key)
    {
        if ( ! class_exists('Service_Nj607_Ontology') OR trim((string) $company) === '')
        {
            return;
        }

        try
        {
            $url = trim((string) $source_url);

            if ($url === '' AND $domain !== '')
            {
                $url = 'https://'.$domain;
            }

            Service_Nj607_Ontology::remember($company, [
                'kind'    => 'company',
                'url'     => $url,
                'domain'  => (string) $domain,
                'module'  => 'nj642',
                'code'    => (string) $company_key,
                'path'    => '/vacancies?company='.urlencode((string) $company_key),
                'label'   => 'Вакансии компании',
                'score'   => 70,
                'source'  => 'nj642_vacancies',
            ]);
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj642: онтология: :e', [':e' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Чтение для страниц
    // ------------------------------------------------------------------

    /** Условие выборки вакансий пользователя (мониторы + фильтры). */
    protected static function _feed_where($user_id, array $f)
    {
        $db    = self::_db();
        $where = ['w.user_id = '.(int) $user_id];

        if (trim((string) ($f['watch'] ?? '')) !== '')
        {
            $where[] = 'w.code = '.$db->quote((string) $f['watch']);
        }

        if (trim((string) ($f['company'] ?? '')) !== '')
        {
            $where[] = 'v.company_key = '.$db->quote(mb_strtolower(trim((string) $f['company'])));
        }

        if (trim((string) ($f['city'] ?? '')) !== '')
        {
            $where[] = 'v.city ILIKE '.$db->quote('%'.trim((string) $f['city']).'%');
        }

        if (trim((string) ($f['direction'] ?? '')) !== '')
        {
            $like = $db->quote('%'.trim((string) $f['direction']).'%');
            $where[] = '(v.direction ILIKE '.$like.' OR v.title ILIKE '.$like.')';
        }

        $q = trim((string) ($f['q'] ?? ''));

        if ($q !== '')
        {
            $like = $db->quote('%'.$q.'%');
            $where[] = '(v.title ILIKE '.$like.' OR v.company ILIKE '.$like.' OR v.city ILIKE '.$like
                .' OR v.snippet ILIKE '.$like.')';
        }

        switch ((string) ($f['status'] ?? ''))
        {
            case 'new':
                $where[] = "v.status = 'active' AND m.status = 'new'";
                break;
            case 'saved':
                $where[] = "m.status = 'saved'";
                break;
            case 'hidden':
                $where[] = "m.status = 'hidden'";
                break;
            case 'gone':
                $where[] = "v.status = 'gone'";
                break;
            case 'active':
            default:
                $where[] = "v.status = 'active'";
                break;
        }

        return implode(' AND ', $where);
    }

    /** Вакансии ленты пользователя с фильтрами и пагинацией. */
    public static function feed($user_id, array $f, $limit = 20, $offset = 0)
    {
        self::ensure_tables();

        $limit  = max(1, min((int) $limit, 100));
        $offset = max(0, (int) $offset);
        $db     = self::_db();

        try
        {
            return $db->query(Database::SELECT,
                'SELECT v.*, m.id AS match_id, m.score, m.why, m.status AS match_status, m.watch_id,
                        w.code AS watch_code, w.title AS watch_title
                   FROM nj_642_matches m
                   JOIN nj_642_vacancies v ON v.id = m.vacancy_id
                   JOIN nj_642_watches w ON w.id = m.watch_id
                  WHERE '.self::_feed_where($user_id, $f).'
                  ORDER BY m.id DESC
                  LIMIT '.$limit.' OFFSET '.$offset, FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj642: лента вакансий: :e', [':e' => $e->getMessage()]);

            return [];
        }
    }

    /** Сколько вакансий под фильтром (пагинация и «Строк:»). */
    public static function feed_count($user_id, array $f)
    {
        self::ensure_tables();

        try
        {
            $row = self::_db()->query(Database::SELECT,
                'SELECT count(*) AS n
                   FROM nj_642_matches m
                   JOIN nj_642_vacancies v ON v.id = m.vacancy_id
                   JOIN nj_642_watches w ON w.id = m.watch_id
                  WHERE '.self::_feed_where($user_id, $f), FALSE)->current();

            return (int) ($row['n'] ?? 0);
        }
        catch (Throwable $e)
        {
            return 0;
        }
    }

    /** Счётчики статусов ленты — чипы страницы. */
    public static function feed_counts($user_id)
    {
        self::ensure_tables();

        $out = ['active' => 0, 'new' => 0, 'saved' => 0, 'gone' => 0];

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) FILTER (WHERE v.status = 'active') AS active_n,
                        count(*) FILTER (WHERE v.status = 'active' AND m.status = 'new') AS new_n,
                        count(*) FILTER (WHERE m.status = 'saved') AS saved_n,
                        count(*) FILTER (WHERE v.status = 'gone') AS gone_n
                   FROM nj_642_matches m
                   JOIN nj_642_vacancies v ON v.id = m.vacancy_id
                   JOIN nj_642_watches w ON w.id = m.watch_id
                  WHERE w.user_id = ".(int) $user_id, FALSE)->current();
        }
        catch (Throwable $e)
        {
            return $out;
        }

        if ($row)
        {
            $out = [
                'active' => (int) $row['active_n'],
                'new'    => (int) $row['new_n'],
                'saved'  => (int) $row['saved_n'],
                'gone'   => (int) $row['gone_n'],
            ];
        }

        return $out;
    }

    /** Статистика монитора для его страницы. */
    public static function stats_of($watch_id)
    {
        self::ensure_tables();

        $out = ['total' => 0, 'fresh' => 0, 'active' => 0, 'gone' => 0, 'saved' => 0];

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) AS total,
                        count(*) FILTER (WHERE m.status = 'new') AS fresh,
                        count(*) FILTER (WHERE v.status = 'active') AS active_n,
                        count(*) FILTER (WHERE v.status = 'gone') AS gone_n,
                        count(*) FILTER (WHERE m.status = 'saved') AS saved_n
                   FROM nj_642_matches m
                   JOIN nj_642_vacancies v ON v.id = m.vacancy_id
                  WHERE m.watch_id = ".(int) $watch_id, FALSE)->current();
        }
        catch (Throwable $e)
        {
            return $out;
        }

        if ($row)
        {
            $out = [
                'total'  => (int) $row['total'],
                'fresh'  => (int) $row['fresh'],
                'active' => (int) $row['active_n'],
                'gone'   => (int) $row['gone_n'],
                'saved'  => (int) $row['saved_n'],
            ];
        }

        return $out;
    }

    /**
     * Сколько вакансий этой компании мы знаем (для чипа на странице профиля
     * компании: модули связаны между собой, а не живут каждый сам по себе).
     *
     * @return array [total, active]
     */
    public static function count_for_company($company_key)
    {
        self::ensure_tables();

        $out = ['total' => 0, 'active' => 0];
        $key = mb_strtolower(trim((string) $company_key));

        if ($key === '')
        {
            return $out;
        }

        try
        {
            $row = self::_db()->query(Database::SELECT,
                "SELECT count(*) AS total, count(*) FILTER (WHERE status = 'active') AS active_n
                   FROM nj_642_vacancies WHERE company_key = ".self::_db()->quote($key), FALSE)->current();

            if ($row)
            {
                $out = ['total' => (int) $row['total'], 'active' => (int) $row['active_n']];
            }
        }
        catch (Throwable $e)
        {
            // нет таблиц — чип просто не покажем
        }

        return $out;
    }

    /** История вакансии (появление/исчезновение) — для карточки. */
    public static function events_of($vacancy_id, $limit = 12)
    {
        self::ensure_tables();

        try
        {
            return self::_db()->query(Database::SELECT,
                'SELECT kind, note, created_at FROM nj_642_events
                  WHERE vacancy_id = '.(int) $vacancy_id.'
                  ORDER BY id DESC LIMIT '.max(1, min((int) $limit, 50)), FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /** Пометка вакансии в ленте: прочитано / в избранном / скрыть. */
    public static function set_match_status($user_id, $match_id, $status)
    {
        self::ensure_tables();

        if ( ! in_array((string) $status, [self::M_NEW, self::M_SAVED, self::M_HIDDEN], TRUE))
        {
            return ['error' => 'Неизвестная пометка'];
        }

        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT m.id FROM nj_642_matches m JOIN nj_642_watches w ON w.id = m.watch_id
              WHERE m.id = '.(int) $match_id.' AND w.user_id = '.(int) $user_id, FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Вакансия не найдена'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_642_matches SET status = '.$db->quote((string) $status).' WHERE id = '.(int) $match_id, FALSE);

        return ['ok' => TRUE, 'status' => (string) $status];
    }

    /**
     * Компании для формы монитора: профили компаний пользователя (nj591) плюс
     * уже встречавшиеся компании из вакансий. Автозаполнение браузера не
     * используем (владелец: «не используй автозаполнение браузером») — подсказки
     * берутся из нашей же базы.
     */
    public static function company_hints($user_id, $limit = 40)
    {
        self::ensure_tables();

        $out = [];
        $db  = self::_db();

        try
        {
            foreach ($db->query(Database::SELECT,
                "SELECT code, company, domain FROM nj_591_profiles
                  WHERE user_id = ".(int) $user_id." AND deleted_at IS NULL AND company <> ''
                  ORDER BY id DESC LIMIT ".max(1, (int) $limit), FALSE)->as_array() as $r)
            {
                $out[] = ['name' => (string) $r['company'], 'domain' => (string) $r['domain'],
                          'profile_code' => (string) $r['code'], 'from' => 'профиль компании'];
            }
        }
        catch (Throwable $e)
        {
            // модуля профилей может не быть — не беда
        }

        try
        {
            foreach ($db->query(Database::SELECT,
                "SELECT company, company_key, count(*) AS n FROM nj_642_vacancies
                  WHERE company <> '' GROUP BY company, company_key ORDER BY n DESC LIMIT ".max(1, (int) $limit), FALSE)->as_array() as $r)
            {
                $out[] = ['name' => (string) $r['company'], 'domain' => (string) $r['company_key'],
                          'profile_code' => '', 'from' => 'вакансии'];
            }
        }
        catch (Throwable $e)
        {
            // пусто — значит пусто
        }

        $uniq = [];

        foreach ($out as $c)
        {
            $key = mb_strtolower($c['domain'] !== '' ? $c['domain'] : $c['name']);

            if ($key === '' OR isset($uniq[$key]))
            {
                continue;
            }

            $uniq[$key] = $c;
        }

        return array_slice(array_values($uniq), 0, (int) $limit);
    }

    /**
     * Найти вакансию по ссылке — для входа «вставьте ссылку на вакансию»
     * (сводная карточка nj635). Сначала точные формы адреса, затем — поиск по
     * хвосту пути («/vacancy/12345»): вакансия могла быть сохранена с
     * параметрами запроса или без схемы. Возвращает строку вакансии или NULL.
     */
    public static function by_url($url)
    {
        self::ensure_tables();

        $url = trim((string) $url);

        if ($url === '')
        {
            return NULL;
        }

        $db    = self::_db();
        $forms = self::_url_forms($url);

        if ($forms)
        {
            $in = implode(',', array_map([$db, 'quote'], $forms));

            $rows = $db->query(Database::SELECT,
                'SELECT id, company, company_key, title, city, url, status
                   FROM nj_642_vacancies
                  WHERE url IN ('.$in.') OR source_url IN ('.$in.')
                  ORDER BY id DESC
                  LIMIT 5', FALSE)->as_array();

            foreach ($rows as $r)
            {
                // активная вакансия важнее закрытой, но и закрытую покажем
                if ((string) $r['status'] === 'active')
                {
                    return (array) $r;
                }
            }

            if ($rows)
            {
                return (array) $rows[0];
            }
        }

        // Хвост пути: «hh.ru/vacancy/123456» — ищем вхождение адреса в ссылку.
        // Специальных символов в пути вакансии нет, проценты не экранируем
        // (шаблон может быть шире — это не ошибка, а подсказка по ссылке).
        $parts = parse_url($url);
        $path  = (string) ($parts['path'] ?? '');

        if (mb_strlen($path) >= 6)
        {
            try
            {
                $row = $db->query(Database::SELECT,
                    'SELECT id, company, company_key, title, city, url, status
                       FROM nj_642_vacancies
                      WHERE url LIKE '.$db->quote('%'.$path.'%').'
                      ORDER BY id DESC
                      LIMIT 1', FALSE)->current();

                if ($row)
                {
                    return (array) $row;
                }
            }
            catch (Throwable $e)
            {
                // поиск по хвосту — вспомогательный: ошибка не должна ломать карточку
            }
        }

        return NULL;
    }

    /** Нормализованные формы адреса: как ввели, без query/якоря, без схемы/www. */
    protected static function _url_forms($url)
    {
        $url = trim((string) $url);
        $p   = parse_url($url);
        $out = [$url];

        if ( ! empty($p['host']))
        {
            $host = mb_strtolower((string) $p['host']);
            $host = (string) preg_replace('~^www\.~', '', $host);
            $path = (string) ($p['path'] ?? '');

            $out[] = 'https://'.$host.$path;
            $out[] = 'http://'.$host.$path;
            $out[] = $host.$path;
            $out[] = 'www.'.$host.$path;
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * Активные вакансии компании по набору ключей (company_key) и счётчики —
     * для сводной карточки (nj635). Ключи готовит вызывающая сторона (домен или
     * нормализованное название), здесь только чтение своих таблиц.
     *
     * @param  array $keys  ключи company_key
     * @param  int   $limit сколько вакансий вернуть (1..20)
     * @return array ['total' => int, 'active' => int, 'items' => array]
     */
    public static function for_company_keys(array $keys, $limit = 6)
    {
        self::ensure_tables();

        $out  = ['total' => 0, 'active' => 0, 'items' => []];
        $uniq = [];

        foreach ((array) $keys as $k)
        {
            $k = mb_strtolower(trim((string) $k));

            if ($k !== '')
            {
                $uniq[$k] = TRUE;
            }
        }

        $keys = array_keys($uniq);

        if (empty($keys))
        {
            return $out;
        }

        $db    = self::_db();
        $in    = implode(',', array_map([$db, 'quote'], $keys));
        $limit = min(20, max(1, (int) $limit));

        try
        {
            $c = $db->query(Database::SELECT,
                "SELECT count(*) AS total, count(*) FILTER (WHERE status = 'active') AS active_n
                   FROM nj_642_vacancies WHERE company_key IN (".$in.')', FALSE)->current();

            if ($c)
            {
                $out['total']  = (int) $c['total'];
                $out['active'] = (int) $c['active_n'];
            }

            $out['items'] = $db->query(Database::SELECT,
                "SELECT id, company, company_key, title, city, direction, salary, url,
                        first_seen_at, last_seen_at
                   FROM nj_642_vacancies
                  WHERE company_key IN (".$in.") AND status = 'active'
                  ORDER BY last_seen_at DESC, id DESC
                  LIMIT ".$limit, FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            // таблиц нет — карточка будет без вакансий, это не ошибка
        }

        return $out;
    }
}
