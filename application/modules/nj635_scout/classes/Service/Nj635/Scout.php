<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Разведка контрагента» (nj635_scout) — единый вход в сквозной сценарий
 * проверки контрагента на платформе.
 *
 * НЕ собирает данные сам: оркестрирует готовые сервисы, каждый из которых
 * владеет своими таблицами, кредитами и воркерами:
 *
 *   nj594 (справочник организаций) → карточка ЕГРЮЛ/ЕГРИП по ИНН/ОГРН/названию;
 *   nj591 (профиль компании)      → обход сайта: сфера, размер, контакты, стек, ЛПР;
 *   nj609 (сторож контрагентов)   → наблюдение за судами/банкротствами/ЕГРЮЛ;
 *   nj592 (холодное письмо)       → первое письмо на основе фактов профиля;
 *   nj598 (AI-досье)              → отзывы, техстатьи, новости, Red Flags.
 *
 * Собственная таблица nj_635_projects хранит только СВЯЗИ артефактов
 * (org_id/profile_code/subject_code/letter_code/dossier_code) и статус маршрута.
 *
 * Безопасность:
 *  * пользовательский ввод идёт только через Database::quote/параметры, без exec;
 *  * домен-вход не может быть локальным/приватным (SSRF-гард для «обойди сайт»);
 *  * лимит проектов на пользователя (окно/за час) через подсчёт таблицы владельца;
 *  * анонимам сервис недоступен — только после входа (артефакты привязаны к user_id).
 */
class Service_Nj635_Scout
{
    protected static $_tables_ensured = FALSE;

    /** Шаги маршрута в порядке их исполнения. */
    public static function steps()
    {
        return [
            'org' => [
                'title' => 'Карточка в справочнике',
                'desc'  => 'ЕГРЮЛ/ЕГРИП: название, ИНН, ОГРН, статус, руководитель.',
                'icon'  => 'fas fa-landmark',
                'svc'   => 'nj594',
            ],
            'watchdog' => [
                'title' => 'Под наблюдение',
                'desc'  => 'Раз в неделю проверяем суды, банкротства, изменения реестра.',
                'icon'  => 'fas fa-shield-halved',
                'svc'   => 'nj609',
            ],
            'profile' => [
                'title' => 'Профиль сайта',
                'desc'  => 'Сфера, размер, контакты, стек, ЛПР — из открытых страниц компании.',
                'icon'  => 'fas fa-building',
                'svc'   => 'nj591',
            ],
            'dossier' => [
                'title' => 'AI-досье',
                'desc'  => 'Отзывы сотрудников, техстатьи, новости — Red Flags & Pros.',
                'icon'  => 'fas fa-user-secret',
                'svc'   => 'nj598',
            ],
            'letter' => [
                'title' => 'Первое письмо',
                'desc'  => 'Холодное письмо на основе фактов профиля (нужен ваш оффер).',
                'icon'  => 'fas fa-envelope',
                'svc'   => 'nj592',
            ],
        ];
    }

    public static function ensure_tables()
    {
        if (self::$_tables_ensured)
        {
            return;
        }

        self::$_tables_ensured = TRUE;

        try
        {
            DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_635_projects" (
    "id"           BIGSERIAL PRIMARY KEY,
    "code"         VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"      BIGINT       NOT NULL,
    "query"        VARCHAR(500) NOT NULL DEFAULT '',
    "company"      VARCHAR(300) NOT NULL DEFAULT '',
    "domain"       VARCHAR(200) NOT NULL DEFAULT '',
    "inn"          VARCHAR(20)  NOT NULL DEFAULT '',
    "ogrn_hint"    VARCHAR(20)  NOT NULL DEFAULT '',
    "org_id"       BIGINT       NULL,
    "profile_code" VARCHAR(12)  NULL,
    "subject_code" VARCHAR(12)  NULL,
    "letter_code"  VARCHAR(12)  NULL,
    "dossier_code" VARCHAR(12)  NULL,
    "status"       VARCHAR(12)  NOT NULL DEFAULT 'running',
    "step"         VARCHAR(30)  NOT NULL DEFAULT 'org',
    "error"        TEXT         NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_635_projects_user_idx" ON "nj_635_projects" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_635_projects" IS 'nj635_scout: маршрут разведки контрагента; связи артефактов nj594/nj591/nj609/nj592/nj598';
COMMENT ON COLUMN "nj_635_projects"."status" IS 'running|done|error (окончательный статус маршрута)';
COMMENT ON COLUMN "nj_635_projects"."step" IS 'текущий шаг маршрута (ключ steps())';
SQL
            )->execute();
        }
        catch (Throwable $e)
        {
            self::$_tables_ensured = FALSE;
            Kohana::$log->add(Log::WARNING, 'nj635: ensure_tables: :e', [':e' => $e->getMessage()]);
        }
    }

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _cfg($key, $default)
    {
        return Kohana::$config->load('nj635_scout.'.$key) ?: $default;
    }

    /** Разбор ввода: домен / название / ИНН / ОГРН. */
    public static function parse($query)
    {
        $query = trim((string) $query);
        $out   = ['company' => '', 'domain' => '', 'inn' => '', 'ogrn' => '', 'kind' => 'name'];

        if ($query === '')
        {
            return ['error' => 'Укажите контрагента: домен, название, ИНН или ОГРН.'];
        }

        if (mb_strlen($query) > (int) self::_cfg('max_query_len', 300))
        {
            return ['error' => 'Слишком длинный ввод.'];
        }

        // ИНН/ОГРН — только цифры (ввод может быть с пробелами)
        $digits = preg_replace('/\D/', '', $query);

        if ($digits !== '' AND preg_match('~^[\d\s]+$~u', $query))
        {
            // ИНН: 10 (ЮЛ) или 12 (ИП/физлицо); ОГРН: 13 или 15
            if (mb_strlen($digits) === 13 OR mb_strlen($digits) === 15)
            {
                $out['ogrn'] = $digits;
                $out['kind'] = 'ogrn';
            }
            elseif (mb_strlen($digits) === 10 OR mb_strlen($digits) === 12)
            {
                $out['inn']  = $digits;
                $out['kind'] = 'inn';
            }
            else
            {
                return ['error' => 'Номер не похож на ИНН (10 или 12 цифр) или ОГРН (13 или 15 цифр).'];
            }
        }

        if ($out['kind'] === 'name')
        {
            // домен? (или IP/localhost — отдельно, они тоже не «название»)
            $maybe = preg_replace('~^https?://~i', '', $query);
            $maybe = explode('/', (string) $maybe)[0];
            $maybe = explode('?', (string) $maybe)[0];
            $maybe = rtrim(strtolower($maybe), '.');

            // IP-адрес (v4/v6) и служебные хостнеймы — сразу через SSRF-гард
            if (filter_var($maybe, FILTER_VALIDATE_IP) OR self::_is_local_host($maybe))
            {
                if (self::_is_local_host($maybe))
                {
                    return ['error' => 'Локальные адреса недоступны для разведки — укажите публичный домен или название.'];
                }

                if (filter_var($maybe, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE)
                {
                    return ['error' => 'Локальные адреса недоступны для разведки — укажите публичный домен или название.'];
                }

                // публичный IP, не домен — просим название
                return ['error' => 'Публичный IP сам по себе не годится — укажите домен или название компании.'];
            }

            if ($maybe !== '' AND strpos($maybe, ' ') === FALSE
                AND preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~i', $maybe))
            {
                $host = strtolower($maybe);

                if (self::_is_local_host($host))
                {
                    return ['error' => 'Локальные адреса недоступны для разведки — укажите публичный домен или название.'];
                }

                $out['domain'] = $host;
                $out['kind']   = 'domain';
            }
            else
            {
                // название компании — оно может быть и ИНН-подобным текстом; чистим
                if (mb_strlen($query) < 3)
                {
                    return ['error' => 'Название слишком короткое — от 3 символов.'];
                }

                $out['company'] = mb_substr($query, 0, 300);
            }
        }

        return $out;
    }

    /** SSRF-гард: localhost, IPv4-приват, metadata, IPv6-loopback, mDNS. */
    protected static function _is_local_host($host)
    {
        $host = rtrim(strtolower((string) $host), '.');

        if (in_array($host, ['localhost', 'localhost.localdomain', '0', '[::1]', '::1'], TRUE))
        {
            return TRUE;
        }

        if (preg_match('~\.(local|internal|lan|home|corp|test)$~', $host))
        {
            return TRUE;
        }

        // IP-адреса
        if (filter_var($host, FILTER_VALIDATE_IP))
        {
            return filter_var($host, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE;
        }

        // 169.254.169.254 и прочий metadata-класс — уже покрыт NO_RES_RANGE, но явно
        if ($host === '169.254.169.254')
        {
            return TRUE;
        }

        return FALSE;
    }

    /** Тихий heroku-образный ключ: user_id → subject для биллинга (не используется, артефакты уже charge у владельцев). */
    protected static function _subject_key()
    {
        return 'scout';
    }

    /** Создать проект и сразу запустить доступные шаги. */
    public static function create($user_id, $query)
    {
        if ((int) $user_id <= 0)
        {
            return ['error' => 'Разведка доступна после входа — артефакты привязываются к вам.'];
        }

        $p = self::parse($query);

        if (isset($p['error']))
        {
            return $p;
        }

        if ( ! self::_allowed_rate((int) $user_id))
        {
            return ['error' => 'Слишком часто — подождите минуту и повторите.'];
        }

        self::ensure_tables();

        $db   = self::_db();
        $code = (string) self::_cfg('code_prefix', 'sct').bin2hex(random_bytes((int) self::_cfg('code_bytes', 4)));

        try
        {
            $row = $db->query(Database::SELECT,
                'INSERT INTO nj_635_projects (code, user_id, query, company, domain, inn, ogrn_hint)
                 VALUES ('.$db->quote($code).', '.(int) $user_id.', '.$db->quote($query).',
                         '.$db->quote($p['company']).', '.$db->quote($p['domain']).', '.$db->quote($p['inn']).',
                         '.$db->quote($p['ogrn']).')
                 RETURNING id', FALSE)->current();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj635: create insert: :e', [':e' => $e->getMessage()]);

            return ['error' => 'Не удалось создать разведку — попробуйте ещё раз.'];
        }

        if ( ! $row)
        {
            return ['error' => 'Не удалось создать разведку — попробуйте ещё раз.'];
        }

        $project_id = (int) $row['id'];

        // Автозапуск шагов (каждый сервис сам валидирует и charge'ит).
        $self = self::_boot((int) $project_id, (int) $user_id);

        return ['code' => $code, 'id' => $project_id, 'steps' => $self];
    }

    /** Инициализация маршрута по проекту: автозапуск безопасных шагов. */
    protected static function _boot($project_id, $user_id)
    {
        $db = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_635_projects WHERE id = '.(int) $project_id, FALSE)->current();

        if (empty($row))
        {
            return [];
        }

        $proj   = (array) $row;
        $lookup = $proj['inn'] !== '' ? $proj['inn'] : ($proj['ogrn_hint'] !== '' ? $proj['ogrn_hint']
            : (($proj['company'] !== '' ? $proj['company'] : $proj['domain'])));

        // Шаг 1: карточка в справочнике nj594 (поиск; линковка на проект)
        if ((int) $proj['org_id'] <= 0 AND $lookup !== ''
            AND class_exists('Service_Nj594_Orgs'))
        {
            try
            {
                $found = Service_Nj594_Orgs::search($lookup, 3);

                if ($found AND count($found) === 1)
                {
                    $org = (array) $found[0];
                    $db->query(Database::UPDATE,
                        'UPDATE nj_635_projects SET org_id = '.(int) $org['id'].',
                         inn = COALESCE(NULLIF(inn, \'\'), '.$db->quote((string) $org['inn']).'),
                         company = COALESCE(NULLIF(company, \'\'), '.$db->quote((string) $org['short_name']).')
                         WHERE id = '.(int) $project_id, FALSE);

                    Service_Nj594_Orgs::link('nj635', (string) $proj['code'], (int) $org['id'],
                        (string) ($proj['company'] !== '' ? $proj['company'] : $org['short_name']),
                        '/scout/'.(string) $proj['code'], 'Разведка');
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj635: nj594 lookup: :e', [':e' => $e->getMessage()]);
            }
        }

        // Шаг 2: профиль сайта nj591 (домен/название)
        if ((string) $proj['profile_code'] === '' AND class_exists('Service_Nj591_Company'))
        {
            try
            {
                $profile_input = $proj['domain'] !== '' ? $proj['domain']
                    : ($proj['company'] !== '' ? $proj['company'] : $lookup);

                $res = Service_Nj591_Company::create($user_id, $profile_input);

                if (isset($res['code']))
                {
                    $db->query(Database::UPDATE,
                        'UPDATE nj_635_projects SET profile_code = '.$db->quote((string) $res['code'])
                        .' WHERE id = '.(int) $project_id, FALSE);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj635: nj591 create: :e', [':e' => $e->getMessage()]);
            }
        }

        // Шаг 3: наблюдение nj609 (название + ИНН)
        if ((string) $proj['subject_code'] === '' AND class_exists('Service_Nj609_Watchdog'))
        {
            try
            {
                $subject_name = $proj['company'] !== '' ? $proj['company'] : ($proj['domain'] !== '' ? $proj['domain'] : $lookup);
                $res = Service_Nj609_Watchdog::add_subject($user_id, $subject_name, $proj['inn'], 'Из разведки '.$proj['code']);

                if (isset($res['code']))
                {
                    $db->query(Database::UPDATE,
                        'UPDATE nj_635_projects SET subject_code = '.$db->quote((string) $res['code'])
                        .' WHERE id = '.(int) $project_id, FALSE);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj635: nj609 add_subject: :e', [':e' => $e->getMessage()]);
            }
        }

        // Шаг 4: AI-досье nj598 (по компании)
        if ((string) $proj['dossier_code'] === '' AND class_exists('Service_Nj598_Dossier'))
        {
            try
            {
                $dossier_input = $proj['company'] !== '' ? $proj['company'] : ($proj['domain'] !== '' ? $proj['domain'] : $lookup);
                $res = Service_Nj598_Dossier::create($user_id, self::_visitor_uid($user_id), $dossier_input);

                if (isset($res['code']))
                {
                    $db->query(Database::UPDATE,
                        'UPDATE nj_635_projects SET dossier_code = '.$db->quote((string) $res['code'])
                        .' WHERE id = '.(int) $project_id, FALSE);
                }
                elseif (isset($res['exists']))
                {
                    // повторное досье: ведём к последнему
                    $db->query(Database::UPDATE,
                        'UPDATE nj_635_projects SET dossier_code = '.$db->quote((string) $res['exists'])
                        .' WHERE id = '.(int) $project_id, FALSE);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj635: nj598 create: :e', [':e' => $e->getMessage()]);
            }
        }

        // Шаг «Первое письмо» не запускаем автоматически: нужны оффер/аудитория.
        return self::status_of((int) $project_id, $user_id);
    }

    protected static function _visitor_uid($user_id)
    {
        if (class_exists('Visitor'))
        {
            return Visitor::uid();
        }

        return 'u:'.(int) $user_id;
    }

    /** Расчёт состояний шагов для проекта. */
    public static function status_of($project_id, $user_id)
    {
        $db = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_635_projects WHERE id = '.(int) $project_id, FALSE)->current();

        if (empty($row))
        {
            return [];
        }

        $proj = (array) $row;
        $out  = [];

        foreach (self::steps() as $key => $s)
        {
            $state = ['key' => $key, 'status' => 'pending', 'code' => NULL, 'url' => NULL];

            try
            {
                switch ($key)
                {
                    case 'org':
                        $state['url'] = '/orgs?q='.rawurlencode((string) ($proj['company'] !== '' ? $proj['company'] : $proj['query']));

                        if ((int) $proj['org_id'] > 0 AND class_exists('Service_Nj594_Orgs'))
                        {
                            $linked = Service_Nj594_Orgs::org_for_item('nj635', (string) $proj['code']);

                            $state['status'] = 'done';
                            $state['url']    = $linked ? '/orgs/'.(string) $linked['ogrn'] : '/orgs';
                        }
                        else
                        {
                            $state['status'] = 'missing';
                        }

                        break;

                    case 'profile':
                        if ((string) $proj['profile_code'] === '')
                        {
                            $state['status'] = 'pending';
                        }
                        else
                        {
                            $p = class_exists('Service_Nj591_Company') ? Service_Nj591_Company::by_code((string) $proj['profile_code']) : NULL;
                            $state['code'] = (string) $proj['profile_code'];

                            if ($p)
                            {
                                $state['status'] = $p['status'] === 'done' ? 'done'
                                    : ($p['status'] === 'error' ? 'error' : 'running');
                                $state['url'] = '/company/'.(string) $proj['profile_code'];
                            }
                            else
                            {
                                $state['status'] = 'error';
                            }
                        }

                        break;

                    case 'watchdog':
                        if ((string) $proj['subject_code'] !== '')
                        {
                            $state['code']   = (string) $proj['subject_code'];
                            $state['status'] = 'running'; // наблюдаем дальше; сигналы в /watchdog
                            $state['url']    = '/watchdog';
                        }

                        break;

                    case 'dossier':
                        if ((string) $proj['dossier_code'] !== '')
                        {
                            $d = class_exists('Service_Nj598_Dossier') ? Service_Nj598_Dossier::by_code((string) $proj['dossier_code']) : NULL;
                            $state['code'] = (string) $proj['dossier_code'];

                            if ($d)
                            {
                                $state['status'] = $d['status'] === 'done' ? 'done'
                                    : ($d['status'] === 'error' ? 'error' : 'running');
                                $state['url'] = '/dossier/'.(string) $proj['dossier_code'];
                            }
                            else
                            {
                                $state['status'] = 'error';
                            }
                        }

                        break;

                    case 'letter':
                        if ((string) $proj['letter_code'] !== '')
                        {
                            $l = class_exists('Service_Nj592_Coldmail') ? Service_Nj592_Coldmail::by_code((string) $proj['letter_code']) : NULL;
                            $state['code'] = (string) $proj['letter_code'];

                            if ($l)
                            {
                                $state['status'] = $l['status'] === 'done' ? 'done'
                                    : ($l['status'] === 'error' ? 'error' : 'running');
                                $state['url'] = '/coldmail/'.(string) $proj['letter_code'];
                            }
                            else
                            {
                                $state['status'] = 'error';
                            }
                        }
                        else
                        {
                            // действие: нужен ввод оффера
                            $state['status'] = ($proj['profile_code'] !== '')
                                ? 'action'
                                : 'pending';
                            $state['url'] = '/coldmail?profile='.rawurlencode((string) $proj['profile_code']);
                        }

                        break;
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj635: status_of :s: :e', [':s' => $key, ':e' => $e->getMessage()]);
                $state['status'] = 'error';
            }

            $out[$key] = $state + $s;
        }

        return $out;
    }

    /** Мягкий лимит: не более N проектов в минуту и M за час на пользователя. */
    protected static function _allowed_rate($user_id)
    {
        self::ensure_tables();

        try
        {
            $db = self::_db();
            $min = (int) self::_cfg('rate_per_minute', 3);
            $hour = (int) self::_cfg('rate_per_hour', 30);

            $c = $db->query(Database::SELECT,
                'SELECT
                    COUNT(*) FILTER (WHERE created_at > now() - interval \'1 minute\') AS m,
                    COUNT(*) FILTER (WHERE created_at > now() - interval \'1 hour\')   AS h
                 FROM nj_635_projects WHERE user_id = '.(int) $user_id, FALSE)->current();

            $m = (int) ($c['m'] ?? 0);
            $h = (int) ($c['h'] ?? 0);

            return $m < $min AND $h < $hour;
        }
        catch (Throwable $e)
        {
            return TRUE; // таблицы нет / сбой — не блокируем
        }
    }

    public static function by_code($code)
    {
        self::ensure_tables();

        $row = self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_635_projects WHERE code = '.self::_db()->quote((string) $code).' LIMIT 1', FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    public static function list_user($user_id, $limit = NULL)
    {
        self::ensure_tables();

        return self::_db()->query(Database::SELECT,
            'SELECT * FROM nj_635_projects WHERE user_id = '.(int) $user_id.'
             ORDER BY id DESC LIMIT '.(int) ($limit ?: self::_cfg('list_limit', 50)), FALSE)->as_array();
    }

    /** Запустить шаг «Первое письмо» (нужен оффер/аудитория/тон). */
    public static function start_letter($user_id, $code, array $in)
    {
        $proj = self::by_code($code);

        if ( ! $proj OR (int) $proj['user_id'] !== (int) $user_id)
        {
            return ['error' => 'Разведка не найдена или вам не принадлежит.'];
        }

        if ( ! class_exists('Service_Nj592_Coldmail'))
        {
            return ['error' => 'Сервис писем недоступен.'];
        }

        $product   = trim((string) ($in['product'] ?? ''));
        $audience  = trim((string) ($in['audience'] ?? ''));
        $tone      = trim((string) ($in['tone'] ?? 'business'));
        $company_q = trim((string) ($in['company_query'] ?? ''));

        $res = Service_Nj592_Coldmail::create($user_id,
            $company_q !== '' ? $company_q : ($proj['domain'] !== '' ? $proj['domain']
                : ($proj['company'] !== '' ? $proj['company'] : $proj['query'])),
            (string) $proj['profile_code'],
            $product, $audience, $tone, 3);

        if (isset($res['error']))
        {
            return $res;
        }

        self::_db()->query(Database::UPDATE,
            'UPDATE nj_635_projects SET letter_code = '.self::_db()->quote((string) $res['code'])
            .' WHERE code = '.self::_db()->quote((string) $code), FALSE);

        return ['code' => $res['code']];
    }
}
