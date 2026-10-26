<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сокращатель ссылок (модуль NJ-530, бренд «Need 2 Jump»).
 *
 * Длинный URL → короткий /x/<code> → редирект 302 + счётчик переходов.
 * Одна и та же ссылка получает один и тот же код (дедупликация по url).
 * Идентификаторы (IP создателя и кликнувших) сохраняются в открытом виде —
 * решение владельца от 09.2026; доступны только в части админки (/nj530/admin).
 *
 * Стиль БД — как в Service_Nj120_JobQueue. ORM чужих модулей не используем.
 */
class Service_Nj530_Jump {

    const CODE_ALPHABET = '123456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    const CODE_LEN = 6;
    const MAX_URL = 2048;

    protected static function _db()
    {
        static $db = NULL;

        return $db !== NULL ? $db : $db = Database::instance();
    }

    protected static function _code()
    {
        $alpha = self::CODE_ALPHABET;
        $max = strlen($alpha);
        $code = '';

        for ($i = 0; $i < self::CODE_LEN; $i++)
        {
            $code .= $alpha[random_int(0, $max - 1)];
        }

        return $code;
    }

    /**
     * Валидация и нормализация URL. Нормализованный или NULL.
     */
    public static function normalize($url)
    {
        $url = trim((string) $url);

        if ($url === '' OR strlen($url) > self::MAX_URL)
        {
            return NULL;
        }

        // относительные пути сайта (/vat) — тоже можно сократить
        if ($url[0] === '/')
        {
            $url = URL::base(TRUE).ltrim($url, '/');
        }

        $parsed = parse_url($url);

        if (empty($parsed['scheme']) OR empty($parsed['host'])
            OR ! in_array(strtolower($parsed['scheme']), ['http', 'https'], TRUE)
            OR filter_var($url, FILTER_VALIDATE_URL) === FALSE)
        {
            return NULL;
        }

        // нормализация: хост в нижний регистр
        return str_replace($parsed['host'], strtolower($parsed['host']), $url);
    }

    /**
     * Сократить. Возвращает ['code', 'url', 'existing'] или NULL.
     */
    /** IP клиента (за nginx берём X-Forwarded-For — см. nj520/nj540/nj550). */
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

    public static function create($url, $user_id = NULL, $ip = NULL)
    {
        $url = self::normalize($url);

        if ($url === NULL)
        {
            return NULL;
        }

        $db = self::_db();

        // дедупликация: та же ссылка → тот же код
        $row = $db->query(Database::SELECT,
            'SELECT code FROM nj_530_links WHERE url = '.$db->quote($url).' LIMIT 1',
            FALSE)->current();

        if ($row)
        {
            return ['code' => $row['code'], 'url' => $url, 'existing' => TRUE];
        }

        for ($try = 0; $try < 5; $try++)
        {
            $code = self::_code();

            $exists = $db->query(Database::SELECT,
                'SELECT 1 AS x FROM nj_530_links WHERE code = '.$db->quote($code), FALSE)->current();

            if ($exists)
            {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);

            $db->query(Database::INSERT,
                'INSERT INTO nj_530_links (code, url, host, user_id, creator_ip) VALUES ('
                    .$db->quote($code).', '.$db->quote($url).', '.$db->quote((string) $host).', '
                    .($user_id ? (int) $user_id : 'NULL').', '
                    .$db->quote((string) ($ip ?? self::client_ip())).')',
                FALSE);

            return ['code' => $code, 'url' => $url, 'existing' => FALSE];
        }

        return NULL;
    }

    /** Ссылка по коду или NULL. */
    public static function resolve($code)
    {
        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'SELECT id, code, url, host, clicks, created_at FROM nj_530_links WHERE code = '.$db->quote((string) $code),
            FALSE)->current();

        return $row ? $row : NULL;
    }

    /** +1 переход + запись в журнал кликов (IP кликнувшего). */
    public static function click($code)
    {
        $db = self::_db();

        $row = $db->query(Database::SELECT,
            'UPDATE nj_530_links SET clicks = clicks + 1 WHERE code = '.$db->quote((string) $code)
                .' RETURNING id, clicks', FALSE)->current();

        if ( ! $row)
        {
            return NULL;
        }

        try
        {
            $db->query(Database::INSERT,
                'INSERT INTO nj_530_clicks (link_id, ip) VALUES ('
                    .(int) $row['id'].', '.$db->quote((string) self::client_ip()).')', FALSE);
        }
        catch (Exception $e)
        {
            // журнал не должен ломать сам переход
            Kohana::$log->add(Log::WARNING, 'nj530: клик не записан: :e', [':e' => $e->getMessage()]);
        }

        return (int) $row['clicks'];
    }

    // ---------------------------------------------------------------------
    // Часть управления (/nj530/admin)
    // ---------------------------------------------------------------------

    /** Список ссылок (управление) с поиском по коду/URL/хосту/IP создателя. */
    public static function admin_list($limit = 50, $q = NULL, $offset = 0)
    {
        $db     = self::_db();
        $wheres = [];

        if ($q !== NULL AND trim((string) $q) !== '')
        {
            $like     = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $q)).'%';
            $wheres[] = '(code ILIKE '.$db->quote($like).' OR url ILIKE '.$db->quote($like)
                .' OR host ILIKE '.$db->quote($like).' OR creator_ip ILIKE '.$db->quote($like).')';
        }

        $where = $wheres ? 'WHERE '.implode(' AND ', $wheres) : '';

        return $db->query(Database::SELECT,
            "SELECT l.*, COALESCE(u.username, '') AS username,
                    (SELECT COUNT(*) FROM nj_530_clicks c WHERE c.link_id = l.id) AS clicks_logged
             FROM nj_530_links l
             LEFT JOIN users u ON u.id = l.user_id
             ".$where." ORDER BY l.id DESC LIMIT ".max(1, (int) $limit)." OFFSET ".max(0, (int) $offset), FALSE)->as_array();
    }

    /** Последние переходы (управление): кто кликнул. */
    public static function admin_recent_clicks($limit = 30)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT c.ip, c.created_at, l.code, l.host
             FROM nj_530_clicks c JOIN nj_530_links l ON l.id = c.link_id
             ORDER BY c.id DESC LIMIT '.max(1, (int) $limit), FALSE)->as_array();
    }

    /** Удалить ссылку (клики уйдут каскадом). */
    public static function admin_delete($id)
    {
        self::_db()->query(Database::DELETE, 'DELETE FROM nj_530_links WHERE id = '.(int) $id, FALSE);

        return ['ok' => TRUE];
    }

    /** Последние ссылки пользователя (для страницы /jump). */
    public static function my($user_id, $limit = 10)
    {
        return self::_db()->query(Database::SELECT,
            'SELECT code, url, host, clicks, created_at FROM nj_530_links '
            .'WHERE user_id = '.(int) $user_id.' ORDER BY id DESC LIMIT '.(int) $limit,
            FALSE)->as_array();
    }
}
