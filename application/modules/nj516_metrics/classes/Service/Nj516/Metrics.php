<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Обезличенный счётчик публичной витрины (модуль NJ-516).
 *
 * Запись — один атомарный upsert в день/сервис/группу/источник/аудиторию.
 * В таблице нет visitor_id, IP, user-agent, сырого referer, cookie ID,
 * поискового запроса или query-параметров. User-agent и referer читаются
 * только на время запроса: отсеять роботов и свести источник к одному из
 * четырёх значений (direct/internal/search/external).
 *
 * Манифесты nj110 — единственный источник списка услуг и групп. Считаются
 * только точные href публичных сервисов; /admin, /api, объекты и query не
 * попадают в счётчик. Пишет общий app.js после показа страницы.
 */
class Service_Nj516_Metrics {

    const TABLE = 'nj_516_views';

    /** @var array|null path => ['path','title','group'] */
    protected static $_pages = NULL;

    /** Публичные service href из манифестов. */
    public static function public_pages()
    {
        if (self::$_pages !== NULL)
        {
            return self::$_pages;
        }

        $out = [];

        if ( ! class_exists('Service_Nj110_Catalog'))
        {
            return self::$_pages = [];
        }

        foreach (Service_Nj110_Catalog::modules() as $m)
        {
            if (Service_Nj110_Catalog::resolve_audience($m) !== 'public'
                OR Service_Nj110_Catalog::resolve_status($m) === 'draft')
            {
                continue;
            }

            $path = self::normalize_path((string) ($m['href'] ?? ''));
            if ($path === NULL)
            {
                continue;
            }

            $out[$path] = [
                'path'  => $path,
                'title' => (string) ($m['title'] ?? $path),
                'group' => (string) ($m['group'] ?? 'Без раздела'),
            ];
        }

        return self::$_pages = $out;
    }

    /** Нормализовать только локальный путь; query и fragment никогда не возвращаются. */
    public static function normalize_path($path)
    {
        if ( ! is_string($path))
        {
            return NULL;
        }

        $path = trim($path);
        if ($path === '' OR $path[0] !== '/' OR strpos($path, '//') === 0 OR preg_match('/[\x00-\x20]/', $path) OR strpos($path, '\\') !== FALSE)
        {
            return NULL;
        }

        $path = parse_url($path, PHP_URL_PATH);
        if ( ! is_string($path) OR $path === '' OR strlen($path) > 200)
        {
            return NULL;
        }

        // Публичные href канонические без завершающего slash (корень остаётся '/').
        if ($path !== '/')
        {
            $path = rtrim($path, '/');
        }

        return $path !== '' ? $path : '/';
    }

    /** Найти страницу только по точному манифестному href. */
    public static function page_info($path)
    {
        $path = self::normalize_path($path);
        if ($path === NULL)
        {
            return NULL;
        }

        $pages = self::public_pages();
        return $pages[$path] ?? NULL;
    }

    /**
     * Свести переход к категории, не сохраняя hostname и сам URL.
     *
     * @param string $referer raw URL (используется только локально в запросе)
     * @param string $request_host HTTP_HOST (для различения internal/external)
     */
    public static function source_of($referer, $request_host = '')
    {
        $referer = trim((string) $referer);
        if ($referer === '')
        {
            return 'direct';
        }

        $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
        if ($host === '')
        {
            return 'direct';
        }

        $local = strtolower((string) parse_url('http://'.trim((string) $request_host), PHP_URL_HOST));
        if ($local !== '' AND $host === $local)
        {
            return 'internal';
        }

        if (preg_match('/(^|\.)(yandex\.[a-z.]+|google\.[a-z.]+|bing\.com|duckduckgo\.com|search\.brave\.com|yahoo\.[a-z.]+|mail\.ru)$/i', $host))
        {
            return 'search';
        }

        return 'external';
    }

    /** Роботы не должны раздувать просмотры; user-agent не сохраняется. */
    public static function is_bot($user_agent)
    {
        return (bool) preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|uptime|pingdom|curl\/|wget\/|python-requests|httpclient/i', (string) $user_agent);
    }

    /**
     * Увеличить ровно один дневной агрегат. Не создаёт cookie или visitor ID.
     *
     * @param string $path        window.location.pathname (не query)
     * @param string $referer     raw referer, не сохраняется
     * @param bool   $logged      в момент запроса пользователь вошёл?
     * @param string $user_agent  transient: только фильтр роботов
     * @param string $request_host HTTP_HOST для direct/internal/external
     * @return bool записано ли событие
     */
    public static function track_page($path, $referer = '', $logged = FALSE, $user_agent = '', $request_host = '')
    {
        if (self::is_bot($user_agent))
        {
            return FALSE;
        }

        $page = self::page_info($path);
        if ($page === NULL)
        {
            return FALSE;
        }

        $source = self::source_of($referer, $request_host);
        $audience = $logged ? 'logged' : 'guest';
        $db = Database::instance();

        // Атомарная агрегация: конкурентные открытия одной страницы не теряют счётчик.
        $sql = 'INSERT INTO '.self::TABLE.' (day, path, group_name, source, audience, views, updated_at)
                VALUES (current_date, '.$db->quote($page['path']).', '.$db->quote($page['group']).', '
            .$db->quote($source).', '.$db->quote($audience).', 1, now())
                ON CONFLICT (day, path, group_name, source, audience)
                DO UPDATE SET views = '.self::TABLE.'.views + 1, updated_at = now()';

        $db->query(Database::INSERT, $sql, FALSE);
        return TRUE;
    }

    /**
     * Агрегаты для закрытой админки. Разрешены окна 7, 30 и 90 дней.
     *
     * @return array
     */
    public static function admin_stats($days = 7)
    {
        $days = (int) $days;
        if ( ! in_array($days, [7, 30, 90], TRUE))
        {
            $days = 7;
        }

        $db = Database::instance();
        $from = $days - 1;
        $prev_from = ($days * 2) - 1;
        $prev_to = $days - 1;

        $total = $db->query(Database::SELECT,
            'SELECT COALESCE(sum(views), 0)::bigint AS views,
                    count(DISTINCT path)::integer AS services
             FROM '.self::TABLE.' WHERE day >= current_date - interval \''.$from.' days\'', FALSE)->current();
        $previous = $db->query(Database::SELECT,
            'SELECT COALESCE(sum(views), 0)::bigint AS views
             FROM '.self::TABLE.' WHERE day >= current_date - interval \''.$prev_from.' days\'
               AND day < current_date - interval \''.$prev_to.' days\'', FALSE)->current();

        $top = $db->query(Database::SELECT,
            'SELECT path, group_name,
                    sum(views)::bigint AS views,
                    sum(views) FILTER (WHERE audience = \'guest\')::bigint AS guests,
                    sum(views) FILTER (WHERE audience = \'logged\')::bigint AS logged
             FROM '.self::TABLE.' WHERE day >= current_date - interval \''.$from.' days\'
             GROUP BY path, group_name ORDER BY views DESC, path ASC LIMIT 20', FALSE)->as_array();

        $groups = $db->query(Database::SELECT,
            'SELECT group_name, sum(views)::bigint AS views
             FROM '.self::TABLE.' WHERE day >= current_date - interval \''.$from.' days\'
             GROUP BY group_name ORDER BY views DESC, group_name ASC', FALSE)->as_array();

        $sources = $db->query(Database::SELECT,
            'SELECT source, sum(views)::bigint AS views
             FROM '.self::TABLE.' WHERE day >= current_date - interval \''.$from.' days\'
             GROUP BY source ORDER BY views DESC, source ASC', FALSE)->as_array();

        $audiences = $db->query(Database::SELECT,
            'SELECT audience, sum(views)::bigint AS views
             FROM '.self::TABLE.' WHERE day >= current_date - interval \''.$from.' days\'
             GROUP BY audience ORDER BY audience ASC', FALSE)->as_array();

        $daily_rows = $db->query(Database::SELECT,
            'SELECT day::text AS day, sum(views)::bigint AS views
             FROM '.self::TABLE.' WHERE day >= current_date - interval \''.$from.' days\'
             GROUP BY day ORDER BY day ASC', FALSE)->as_array();

        $daily_map = [];
        foreach ($daily_rows as $row)
        {
            $daily_map[(string) $row['day']] = (int) $row['views'];
        }

        $daily = [];
        $start = (new DateTimeImmutable(gmdate('Y-m-d'), new DateTimeZone('UTC')))->modify('-'.$from.' days');
        for ($i = 0; $i <= $from; $i++)
        {
            $day = $start->modify('+'.$i.' days')->format('Y-m-d');
            $daily[] = ['day' => $day, 'views' => $daily_map[$day] ?? 0];
        }

        $views = $total ? (int) $total['views'] : 0;
        $prev_views = $previous ? (int) $previous['views'] : 0;

        return [
            'days'          => $days,
            'from'          => $daily ? $daily[0]['day'] : gmdate('Y-m-d'),
            'to'            => gmdate('Y-m-d'),
            'views'         => $views,
            'services'      => $total ? (int) $total['services'] : 0,
            'previous_views'=> $prev_views,
            'change_pct'    => $prev_views > 0 ? round(($views - $prev_views) / $prev_views * 100, 1) : NULL,
            'top'            => array_map(function ($r) {
                return ['path' => (string) $r['path'], 'group' => (string) $r['group_name'],
                    'views' => (int) $r['views'], 'guests' => (int) $r['guests'], 'logged' => (int) $r['logged']];
            }, $top),
            'groups'         => array_map(function ($r) { return ['name' => (string) $r['group_name'], 'views' => (int) $r['views']]; }, $groups),
            'sources'        => array_map(function ($r) { return ['name' => (string) $r['source'], 'views' => (int) $r['views']]; }, $sources),
            'audiences'      => array_map(function ($r) { return ['name' => (string) $r['audience'], 'views' => (int) $r['views']]; }, $audiences),
            'daily'          => $daily,
        ];
    }
}
