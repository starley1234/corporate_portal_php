<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Работа 2 Оффер» (NJ-550, бренд «Job 2 Offer»).
 *
 * Публичный сервис: пользователь задаёт город и направление — python-воркер
 * (канал nj550.jobs) бережно ищет вакансии через поисковик (ddgs), ранжирует
 * их LangChain-цепью и сохраняет результат. Долгая работа — НЕ в HTTP-запросе.
 *
 * Идентификация: Visitor::uid() (залогиненный — 'u:<id>', аноним — кука ubid).
 * Предпочтения (город, направление) запоминаются в браузерные куки — форма
 * предзаполняется при следующем визите; недавние поиски — из БД по visitor_id.
 *
 * Надёжность — как в nj540: воркер в pg-режиме пишет результат в БД сам,
 * в http-режиме возвращает его в job.result — тогда API статуса лениво
 * «дозаписывает» (self::reconcile). Оба пути идемпотентны.
 */
class Service_Nj550_Jobs {

    const CODE_PREFIX = 'jb';

    /** @var Database */
    protected static $_db;

    protected static function _db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    /** Значение из config/nj550_jobs.php (точки — вложенность), иначе дефолт. */
    protected static function _cfg($key, $default = NULL)
    {
        static $cfg = NULL;

        if ($cfg === NULL)
        {
            $cfg = (array) Kohana::$config->load('nj550_jobs');
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

    /** IP клиента (за nginx берём X-Forwarded-For — см. nj520/nj540). */
    public static function client_ip()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($xff !== '' AND ($remote === '' OR filter_var($remote, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE))
        {
            return trim((string) strtok($xff, ','));
        }

        return $remote;
    }

    /**
     * Нормализовать ввод: ['city', 'direction'] или ['error' => ...].
     */
    public static function normalize($city, $direction)
    {
        $city      = trim((string) $city);
        $direction = trim((string) $direction);
        $max_city  = (int) self::_cfg('max_city', 80);
        $min_city  = (int) self::_cfg('min_city', 2);
        $min_dir   = (int) self::_cfg('min_direction', 2);
        $max_dir   = (int) self::_cfg('max_direction', 200);

        if (mb_strlen($city) < $min_city OR mb_strlen($city) > $max_city)
        {
            return ['error' => 'Укажите город ('.$min_city.'–'.$max_city.' символов).'];
        }

        if (mb_strlen($direction) < $min_dir OR mb_strlen($direction) > $max_dir)
        {
            return ['error' => 'Укажите направление — должность или сферу ('.$min_dir.'–'.$max_dir.' символов).'];
        }

        return ['city' => $city, 'direction' => $direction];
    }

    /**
     * Создать поиск: INSERT + задача в очередь + куки предпочтений.
     * Возвращает ['code' => ...] или ['error' => ...].
     */
    public static function create($city, $direction, $ip = NULL)
    {
        $norm = self::normalize($city, $direction);

        if (isset($norm['error']))
        {
            return $norm;
        }

        $uid = Visitor::uid();

        if ( ! self::_rate_ok($uid))
        {
            return ['error' => 'Слишком много поисков с этого устройства. Лимит: '
                .(int) self::_cfg('rate.per_hour', 8).' в час — попробуйте позже.'];
        }

        $db = self::_db();

        // не заставляем воркер (и LLM) работать повторно: свежий идентичный
        // поиск уже есть → показываем его результат (решение владельца, 09.2026)
        $fresh = $db->query(Database::SELECT,
            'SELECT code FROM nj_550_searches
             WHERE lower(trim(direction)) = '.self::_db()->quote(mb_strtolower(trim($norm['direction']))).'
               AND lower(trim(city)) = '.self::_db()->quote(mb_strtolower(trim($norm['city'])))."
               AND status = 'done' AND finished_at > now() - interval '".(int) self::_cfg('dedup_days', 3)." days'
             ORDER BY id DESC LIMIT 1", FALSE)->current();

        if ($fresh)
        {
            self::remember_prefs($norm['city'], $norm['direction']);

            return ['code' => (string) $fresh['code'], 'dedup' => TRUE];
        }

        $code = (string) self::_cfg('code_prefix', self::CODE_PREFIX)
            .bin2hex(random_bytes((int) self::_cfg('code_bytes', 5)));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_550_searches (code, visitor_id, user_id, city, direction, client_ip)
             VALUES ('.$db->quote($code).', '.$db->quote($uid).', '
                .(Visitor::user_id() !== NULL ? (int) Visitor::user_id() : 'NULL').', '
                .$db->quote($norm['city']).', '.$db->quote($norm['direction']).',
                '.$db->quote((string) ($ip ?? self::client_ip())).')
             RETURNING id, code', FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось сохранить запрос — попробуйте ещё раз.'];
        }

        $job_id = self::_push_job((int) $row['id'], $code, $norm);

        if ($job_id === 0)
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_550_searches SET status = 'error', error = 'Очередь задач недоступна'
                 WHERE id = ".(int) $row['id']);
            return ['error' => 'Очередь задач недоступна — попробуйте позже.'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_550_searches SET job_id = '.(int) $job_id.' WHERE id = '.(int) $row['id']);

        // предпочтения — в браузерные куки на год (форма предзаполнится)
        self::remember_prefs($norm['city'], $norm['direction']);

        return ['code' => $code];
    }

    /** Запомнить предпочтения пользователя (город + направление) в куки. */
    public static function remember_prefs($city, $direction)
    {
        try
        {
            Cookie::set('nj550_city', (string) $city, Visitor::LIFETIME);
            Cookie::set('nj550_direction', (string) $direction, Visitor::LIFETIME);
        }
        catch (Exception $e)
        {
            // без кук сервис работает, просто не запомнит предпочтения
            Kohana::$log->add(Log::WARNING, 'nj550: не удалось записать куки предпочтений: :e',
                [':e' => $e->getMessage()]);
        }
    }

    /** Предпочтения из кук: ['city' => .., 'direction' => ..] (или пустые). */
    public static function prefs()
    {
        return [
            'city'      => (string) Cookie::get('nj550_city', ''),
            'direction' => (string) Cookie::get('nj550_direction', ''),
        ];
    }

    /** Недавние поиски посетителя (для блока «вы искали»). */
    public static function recent($limit = NULL)
    {
        $limit = (int) ($limit ?? self::_cfg('recent_limit', 5));
        $db    = self::_db();

        return $db->query(Database::SELECT,
            'SELECT code, city, direction, status, created_at
             FROM nj_550_searches WHERE visitor_id = '.$db->quote((string) Visitor::uid()).'
             ORDER BY id DESC LIMIT '.max(1, $limit), FALSE)->as_array();
    }

    /** Поиск по публичному коду или NULL. */
    public static function by_code($code)
    {
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT * FROM nj_550_searches WHERE code = '.$db->quote((string) $code), FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Результаты поиска: карточки вакансий по убыванию релевантности. */
    public static function results($search_id)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT title, company, url, source, snippet, relevance, created_at
             FROM nj_550_results WHERE search_id = '.(int) $search_id.'
             ORDER BY relevance DESC, id ASC LIMIT 60', FALSE)->as_array();
    }

    /**
     * Дозаписать результат из очереди (воркер http-режима не имеет доступа к БД).
     * Вызывается лениво из API статуса — идемпотентно.
     */
    public static function reconcile(array $row)
    {
        if ($row['status'] !== 'new' OR empty($row['job_id']))
        {
            return $row;
        }

        $job = Service_Nj120_JobQueue::instance()->get((int) $row['job_id']);

        if ($job === NULL)
        {
            return $row;
        }

        $db = self::_db();

        if ($job['status'] === Service_Nj120_JobQueue::STATUS_DONE)
        {
            $res = $job['result'];
            is_string($res) AND $res = json_decode($res, TRUE);

            if (is_array($res) AND ( ! empty($res['jobs']) OR isset($res['summary'])))
            {
                self::_save_results((int) $row['id'], (array) ($res['jobs'] ?? []));

                $meta = [];

                foreach (['model', 'engine', 'queries'] as $k)
                {
                    if ( ! empty($res[$k]))
                    {
                        $meta[$k] = $res[$k];
                    }
                }

                $db->query(Database::UPDATE,
                    "UPDATE nj_550_searches
                     SET status = 'done', summary = ".self::_quote_nullable($db, $res['summary'] ?? NULL).",
                         meta = meta || ".self::_quote_json($db, $meta ?: NULL).",
                         finished_at = now(), error = NULL
                     WHERE id = ".(int) $row['id']." AND status = 'new'");

                // резюме поиска — в общую базу знаний (не критично для ответа)
                try
                {
                    if (class_exists('Service_Nj560_Knowledge'))
                    {
                        Service_Nj560_Knowledge::remember('nj550', 'job_search',
                            Service_Nj560_Knowledge::make_key($norm['direction'].'|'.$norm['city']),
                            'Вакансии: '.mb_strtolower($norm['direction']).' ('.$norm['city'].')',
                            (string) ($res['summary'] ?? ''),
                            ['code' => $row['code'], 'queries' => $res['queries'] ?? []],
                            (string) ($res['summary'] ?? ''));
                    }
                }
                catch (Exception $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj550: база знаний недоступна: :e', [':e' => $e->getMessage()]);
                }

                return self::by_code($row['code']);
            }
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_FAILED
            AND (int) $job['attempts'] >= (int) $job['max_attempts'])
        {
            $db->query(Database::UPDATE,
                "UPDATE nj_550_searches SET status = 'error', error = "
                    .$db->quote((string) ($job['error'] ?: 'Воркер не смог выполнить поиск')).",
                     finished_at = now()
                 WHERE id = ".(int) $row['id']." AND status = 'new'");

            return self::by_code($row['code']);
        }
        elseif ($job['status'] === Service_Nj120_JobQueue::STATUS_CANCELLED)
        {
            // Авто-стоп молчащего воркера: «идёт вечно» → понятная ошибка.
            $db->query(Database::UPDATE,
                "UPDATE nj_550_searches SET status = 'error', error = "
                    .$db->quote('Воркер не ответил — попробуйте ещё раз (или обратитесь к администратору)').",
                     finished_at = now()
                 WHERE id = ".(int) $row['id']." AND status = 'new'");

            return self::by_code($row['code']);
        }

        return $row;
    }

    // ---------------------------------------------------------------------
    // Часть управления (/nj550/admin)
    // ---------------------------------------------------------------------

    /** Последние поиски (управление): строки + счётчик найденных вакансий. */
    public static function admin_list($limit = 50, $status = NULL, $offset = 0, $q = NULL)
    {
        $db     = self::_db();
        $wheres = [];

        if (in_array($status, ['new', 'done', 'error'], TRUE))
        {
            $wheres[] = 's.status = '.$db->quote((string) $status);
        }

        if ($q !== NULL AND trim((string) $q) !== '')
        {
            $like     = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $q)).'%';
            $wheres[] = '(s.direction ILIKE '.$db->quote($like).'
                          OR s.city ILIKE '.$db->quote($like).'
                          OR s.code ILIKE '.$db->quote($like).'
                          OR s.visitor_id ILIKE '.$db->quote($like).')';
        }

        $where = $wheres ? 'WHERE '.implode(' AND ', $wheres) : '';

        return $db->query(Database::SELECT,
            'SELECT s.*,
                    (SELECT COUNT(*) FROM nj_550_results r WHERE r.search_id = s.id) AS results_cnt
             FROM nj_550_searches s '.$where.'
             ORDER BY s.id DESC LIMIT '.max(1, (int) $limit).' OFFSET '.max(0, (int) $offset), FALSE)->as_array();
    }

    /** Перезапуск поиска админом: сброс статуса + новая задача в очередь. */
    public static function admin_requeue($id)
    {
        $db  = self::_db();
        $row = $db->query(Database::SELECT,
            'SELECT id, code, city, direction, status FROM nj_550_searches WHERE id = '.(int) $id, FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Поиск не найден'];
        }

        $job_id = self::_push_job((int) $row['id'], (string) $row['code'],
            ['city' => (string) $row['city'], 'direction' => (string) $row['direction']]);

        if ($job_id === 0)
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $db->query(Database::UPDATE,
            "UPDATE nj_550_searches SET status = 'new', error = NULL, finished_at = NULL,
                job_id = ".(int) $job_id." WHERE id = ".(int) $row['id']);

        return ['ok' => TRUE];
    }

    /** Удалить поиск (результаты уйдут каскадом). */
    public static function admin_delete($id)
    {
        self::_db()->query(Database::DELETE,
            'DELETE FROM nj_550_searches WHERE id = '.(int) $id, FALSE);

        return ['ok' => TRUE];
    }

    // ---------------------------------------------------------------------
    // MCP-инструменты (вызываются агентом через nj160_mcp, Bearer MCP_TOKEN)
    // ---------------------------------------------------------------------

    /** Последние поиски: nj550_recent_searches. */
    public static function mcp_recent(array $args)
    {
        $status = isset($args['status']) ? (string) $args['status'] : NULL;
        $limit  = max(1, min(50, (int) ($args['limit'] ?? 10)));

        $rows = self::admin_list($limit, $status);

        return ['searches' => array_map(function ($r) {
            return [
                'id' => (int) $r['id'], 'code' => $r['code'], 'city' => $r['city'],
                'direction' => $r['direction'], 'status' => $r['status'],
                'results' => (int) $r['results_cnt'], 'created_at' => $r['created_at'],
            ];
        }, $rows)];
    }

    /** Поиск по коду с результатами: nj550_get_search. */
    public static function mcp_get(array $args)
    {
        $row = self::by_code((string) ($args['code'] ?? ''));

        if ($row === NULL)
        {
            return ['error' => 'Поиск не найден'];
        }

        return [
            'code'      => $row['code'],
            'city'      => $row['city'],
            'direction' => $row['direction'],
            'status'    => $row['status'],
            'summary'   => $row['summary'],
            'error'     => $row['error'],
            'results'   => self::results((int) $row['id']),
        ];
    }

    /** Создать поиск от имени агента: nj550_create_search (отдельный лимит). */
    public static function mcp_create(array $args)
    {
        $norm = self::normalize((string) ($args['city'] ?? ''), (string) ($args['direction'] ?? ''));

        if (isset($norm['error']))
        {
            return ['error' => $norm['error']];
        }

        $uid = 'mcp-agent';

        if ( ! self::_rate_uid_ok($uid, 30, 100))
        {
            return ['error' => 'Лимит агентских поисков исчерпан, попробуйте позже'];
        }

        $db   = self::_db();
        $code = (string) self::_cfg('code_prefix', self::CODE_PREFIX).bin2hex(random_bytes(5));

        $row = $db->query(Database::SELECT,
            'INSERT INTO nj_550_searches (code, visitor_id, city, direction, client_ip)
             VALUES ('.$db->quote($code).", 'mcp-agent', "
                .$db->quote($norm['city']).', '.$db->quote($norm['direction']).", 'mcp')
             RETURNING id, code", FALSE)->current();

        if ( ! $row)
        {
            return ['error' => 'Не удалось сохранить запрос'];
        }

        $job_id = self::_push_job((int) $row['id'], $code, $norm);

        if ($job_id === 0)
        {
            return ['error' => 'Очередь задач недоступна'];
        }

        $db->query(Database::UPDATE,
            'UPDATE nj_550_searches SET job_id = '.(int) $job_id.' WHERE id = '.(int) $row['id']);

        return [
            'code'     => $code,
            'url'      => '/jobs/'.$code,
            'status'   => 'запущен, результат появится через 20–60 секунд',
            'check'    => 'nj550_get_search {"code": "'.$code.'"}',
        ];
    }

    /** Лимит по uid (общий с _rate_ok, но с настраиваемыми порогами). */
    protected static function _rate_uid_ok($uid, $per_hour, $per_day)
    {
        $db  = self::_db();
        $cnt = $db->query(Database::SELECT,
            'SELECT
                COUNT(*) FILTER (WHERE created_at > now() - interval \'1 hour\') AS h,
                COUNT(*) FILTER (WHERE created_at > now() - interval \'1 day\')  AS d
             FROM nj_550_searches WHERE visitor_id = '.$db->quote((string) $uid), FALSE)->current();

        return $cnt
            AND (int) $cnt['h'] < (int) $per_hour
            AND (int) $cnt['d'] < (int) $per_day;
    }

    /** JSON для API статуса. */
    public static function status_payload(array $row)
    {
        return [
            'ok'          => TRUE,
            'code'        => $row['code'],
            'status'      => $row['status'],
            'summary'     => $row['summary'],
            'error'       => $row['error'],
            'meta'        => $row['meta'] ? json_decode((string) $row['meta'], TRUE) : NULL,
            'created_at'  => $row['created_at'],
            'finished_at' => $row['finished_at'],
        ];
    }

    // ---------------------------------------------------------------------

    protected static function _push_job($search_id, $code, array $norm)
    {
        return Service_Nj120_JobQueue::push((string) self::_cfg('job.channel', 'nj550.jobs'), [
            'search_id' => (int) $search_id,
            'code'      => (string) $code,
            'city'      => (string) $norm['city'],
            'direction' => (string) $norm['direction'],
        ], [
            'visitor_id'   => Visitor::uid(),
            'module'       => 'nj550',
            'max_attempts' => (int) self::_cfg('job.max_attempts', 2),
            'timeout_sec'  => (int) self::_cfg('job.timeout_sec', 240),
        ]);
    }

    /**
     * Сохранить карточки вакансий (idempotent по (search_id, url_hash)).
     * Из http-режима результат приходит сюда же лениво.
     */
    protected static function _save_results($search_id, array $jobs)
    {
        $db   = self::_db();
        $seen = [];

        foreach ($jobs as $job)
        {
            if ( ! is_array($job)) continue;

            $url = trim((string) ($job['url'] ?? ''));

            if ($url === '' OR strpos($url, 'http') !== 0) continue;

            $hash = md5($url);

            if (isset($seen[$hash])) continue;
            $seen[$hash] = TRUE;

            $title   = mb_substr(trim((string) ($job['title'] ?? '')), 0, 300);
            $company = mb_substr(trim((string) ($job['company'] ?? '')), 0, 200);
            $source  = mb_substr(trim((string) ($job['source'] ?? parse_url($url, PHP_URL_HOST) ?: '')), 0, 120);
            $snippet = mb_substr(trim((string) ($job['snippet'] ?? '')), 0, 1000);
            $rel     = max(0, min(10, (int) ($job['relevance'] ?? 0)));

            if ($title === '') continue;

            $db->query(Database::SELECT,
                'INSERT INTO nj_550_results (search_id, title, company, url, url_hash, source, snippet, relevance)
                 VALUES ('.(int) $search_id.', '.$db->quote($title).', '.self::_quote_nullable($db, $company).',
                         '.$db->quote($url).', '.$db->quote($hash).', '.self::_quote_nullable($db, $source).',
                         '.self::_quote_nullable($db, $snippet).', '.(int) $rel.')
                 ON CONFLICT (search_id, url_hash) DO NOTHING', FALSE);
        }
    }

    /** Не превышен ли лимит поисков у посетителя. */
    protected static function _rate_ok($uid)
    {
        $db  = self::_db();
        $cnt = $db->query(Database::SELECT,
            'SELECT
                COUNT(*) FILTER (WHERE created_at > now() - interval \'1 hour\') AS h,
                COUNT(*) FILTER (WHERE created_at > now() - interval \'1 day\')  AS d
             FROM nj_550_searches WHERE visitor_id = '.$db->quote((string) $uid), FALSE)->current();

        return $cnt
            AND (int) $cnt['h'] < (int) self::_cfg('rate.per_hour', 8)
            AND (int) $cnt['d'] < (int) self::_cfg('rate.per_day', 30);
    }

    protected static function _quote_nullable(Database $db, $value)
    {
        return ($value === '' OR $value === NULL) ? 'NULL' : $db->quote((string) $value);
    }

    /** JSONB-литерал или NULL (для конкатенации в SQL). */
    protected static function _quote_json(Database $db, $value)
    {
        return $value === NULL ? 'NULL' : $db->quote(json_encode($value, JSON_UNESCAPED_UNICODE)).'::jsonb';
    }
}
