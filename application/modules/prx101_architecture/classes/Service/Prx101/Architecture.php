<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Доступ к собранной странице архитектуры.
 *
 * Источник истины — docs/architecture/model/*.yaml. Страница и JSON собираются
 * командой `make architecture` (движок в .tools/architecture) и лежат в
 * docs/architecture/dist. Runtime в Node не нуждается: PHP только отдаёт
 * готовый файл после проверки прав.
 */
class Service_Prx101_Architecture
{
    const HTML = 'docs/architecture/dist/architecture.html';
    const JSON = 'docs/architecture/dist/architecture.json';

    /**
     * Корень проекта (без завершающего слеша).
     */
    public static function root()
    {
        $root = realpath(APPPATH.'..');

        return $root === FALSE ? rtrim(APPPATH.'..', DIRECTORY_SEPARATOR) : $root;
    }

    public static function path($relative)
    {
        return self::root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Собранная страница или NULL, если её ещё не собирали.
     */
    public static function html()
    {
        $file = self::path(self::HTML);

        if ( ! is_file($file) OR ! is_readable($file))
        {
            return NULL;
        }

        $html = file_get_contents($file);

        if ($html === FALSE OR $html === '')
        {
            return NULL;
        }

        return self::_portal_link($html);
    }

    public static function json()
    {
        $file = self::path(self::JSON);

        return (is_file($file) AND is_readable($file)) ? file_get_contents($file) : NULL;
    }

    public static function built_at()
    {
        $file = self::path(self::HTML);

        return is_file($file) ? filemtime($file) : NULL;
    }

    /**
     * Ненавязчивый возврат в портал: страница архитектуры — отдельное
     * приложение со своим меню, но пользователь не должен теряться.
     */
    protected static function _portal_link($html)
    {
        $link = '<style>'
            .'#prx-arch-nav{position:fixed;right:18px;bottom:18px;z-index:9999;display:flex;gap:8px}'
            .'#prx-arch-nav a{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;'
            .'border:1px solid rgba(148,163,184,.35);border-radius:999px;'
            .'background:rgba(15,23,42,.88);color:#f8fafc;font:600 13px/1 system-ui,sans-serif;'
            .'text-decoration:none;box-shadow:0 8px 24px rgba(15,23,42,.28);backdrop-filter:blur(8px)}'
            .'#prx-arch-nav a:hover{background:#1e293b;color:#fff}'
            .'@media print{#prx-arch-nav{display:none}}'
            .'</style>'
            .'<nav id="prx-arch-nav" aria-label="Навигация по документации">'
            .'<a href="/architecture/guide" title="Философия, архитектура и правила именования — коротко">Памятка программиста</a>'
            .'<a href="/" title="Вернуться в портал">← Вернуться в портал</a></nav>';

        $pos = strripos($html, '</body>');

        return $pos === FALSE ? $html.$link : substr($html, 0, $pos).$link.substr($html, $pos);
    }
}
