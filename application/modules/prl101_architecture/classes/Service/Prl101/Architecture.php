<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Доступ к собранной странице архитектуры.
 *
 * Источник истины — architecture/arch/*.yaml. Страница и JSON собираются
 * командой `make architecture` (Node-инструмент из INPUT_DATA) и лежат в
 * architecture/dist. Runtime в Node не нуждается: PHP только отдаёт готовый
 * файл после проверки прав.
 */
class Service_Prl101_Architecture
{
    const HTML = 'architecture/dist/architecture.html';
    const JSON = 'architecture/dist/architecture.json';

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
            .'#prl-arch-back{position:fixed;right:18px;bottom:18px;z-index:9999;'
            .'display:inline-flex;align-items:center;gap:8px;padding:10px 14px;'
            .'border:1px solid rgba(148,163,184,.35);border-radius:999px;'
            .'background:rgba(15,23,42,.88);color:#f8fafc;font:600 13px/1 system-ui,sans-serif;'
            .'text-decoration:none;box-shadow:0 8px 24px rgba(15,23,42,.28);backdrop-filter:blur(8px)}'
            .'#prl-arch-back:hover{background:#1e293b;color:#fff}'
            .'@media print{#prl-arch-back{display:none}}'
            .'</style>'
            .'<a id="prl-arch-back" href="/" title="Вернуться в портал">← Вернуться в портал</a>';

        $pos = strripos($html, '</body>');

        return $pos === FALSE ? $html.$link : substr($html, 0, $pos).$link.substr($html, $pos);
    }
}
