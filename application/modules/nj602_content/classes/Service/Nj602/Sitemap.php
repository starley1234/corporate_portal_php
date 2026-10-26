<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Адреса sitemap.xml из манифестов модулей (nj110_catalog).
 *
 * Раньше карта сайта была ручным списком (14 из 64 публичных сервисов).
 * Теперь каждый модуль с публичным `href` попадает в неё сам; дополнительные
 * адреса (страницы годов календаря и т.п.) модуль объявляет в манифесте:
 *
 *   'sitemap' => ['/calendar/2026', '/calendar/2027'],
 *
 * Чистые функции — тестируются без БД и без запроса.
 */
class Service_Nj602_Sitemap {

    /**
     * Адреса из манифестов.
     *
     * Берутся только публичные (audience=public), не черновые модули с
     * локальным href («/…» без плейсхолдеров и query).
     *
     * @param  array $modules манифесты (Service_Nj110_Catalog::visible_modules())
     * @return array список ['loc' => '/path', 'pri' => '0.6']
     */
    public static function module_urls(array $modules)
    {
        $urls = [];

        foreach ($modules as $m)
        {
            if (self::_resolved($m, 'resolve_audience', 'public') !== 'public'
                OR self::_resolved($m, 'resolve_status', 'stable') === 'draft')
            {
                continue;
            }

            $href = isset($m['href']) ? (string) $m['href'] : '';

            if (self::is_indexable($href))
            {
                $urls[] = ['loc' => $href, 'pri' => '0.6'];
            }

            foreach ((array) ($m['sitemap'] ?? []) as $extra)
            {
                if (self::is_indexable((string) $extra))
                {
                    $urls[] = ['loc' => (string) $extra, 'pri' => '0.5'];
                }
            }
        }

        return $urls;
    }

    /** Адрес годится для карты сайта: локальный путь без query, якоря и шаблонов. */
    public static function is_indexable($path)
    {
        return (bool) preg_match('~^/[A-Za-z0-9_\-./]*$~', $path)
            AND $path !== '' AND strpos($path, '//') === FALSE;
    }

    /** Слить списки адресов; первое вхождение побеждает (у ручного списка приоритет выше). */
    public static function merge(array $urls)
    {
        $seen = [];
        $out = [];

        foreach ($urls as $u)
        {
            $key = rtrim((string) $u['loc'], '/') ?: '/';

            if (isset($seen[$key]))
            {
                continue;
            }

            $seen[$key] = TRUE;
            $out[] = $u;
        }

        return $out;
    }

    protected static function _resolved(array $m, $method, $default)
    {
        if (class_exists('Service_Nj110_Catalog'))
        {
            try
            {
                return (string) call_user_func(['Service_Nj110_Catalog', $method], $m);
            }
            catch (Throwable $e)
            {
                // каталог недоступен — считаем модуль публичным/стабильным
            }
        }

        return $default;
    }
}
