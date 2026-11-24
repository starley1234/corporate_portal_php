<?php defined('SYSPATH') or die('No direct script access.');

/** Решение о публикации в каталоге не меняет статус разработки и ACL модуля. */
final class Service_Prx110_Publication
{
    private static ?array $approved = NULL;

    public static function eligible(array $module): bool
    {
        $href = (string) ($module['href'] ?? '');
        return ($module['kind'] ?? 'module') === 'module'
            && preg_match('~\A/(?!/)[^\x00-\x20\\\\]+\z~', $href) === 1
            && Service_Prx110_Catalog::resolve_status($module) !== 'deprecated';
    }

    public static function is_approved(string $name): bool
    {
        if (self::$approved === NULL)
        {
            self::$approved = [];
            if (class_exists('Prx_Db'))
            {
                try
                {
                    $rows = Prx_Db::select('SELECT module_name FROM prx_110_publications');
                    self::$approved = array_fill_keys(array_column($rows, 'module_name'), TRUE);
                }
                catch (Throwable $e)
                {
                    Log_Plain::event(Log::ERROR, 'prx110: не удалось прочитать решения о публикации (:type)', [':type' => get_class($e)]);
                }
            }
        }
        return isset(self::$approved[$name]);
    }

    public static function approve(string $name, Prx_Actor $actor): void
    {
        $actor->require('prx112.approve');
        $module = Service_Prx110_Catalog::get($name);
        if ($module === NULL || $module['module_name'] !== $name || ! self::eligible($module))
        {
            throw HTTP_Exception::factory(404, 'Нет подключённого сервиса, который можно одобрить');
        }
        if (Service_Prx110_Catalog::resolve_manifest_state($module) === 'approved')
        {
            return;
        }

        Prx_Db::execute('INSERT INTO prx_110_publications (module_name, approved_by)
            VALUES (:name, :actor) ON CONFLICT (module_name) DO NOTHING', [
            ':name' => $name, ':actor' => $actor->id(),
        ]);
        self::$approved = NULL;
    }
}
