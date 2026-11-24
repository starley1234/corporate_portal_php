<?php defined('SYSPATH') or die('No direct script access.');

/** Решение о публикации в каталоге не меняет статус разработки и ACL модуля. */
final class Service_Prx110_Publication
{
    private static ?array $decisions = NULL;

    public static function eligible(array $module): bool
    {
        $href = (string) ($module['href'] ?? '');
        return ($module['kind'] ?? 'module') === 'module'
            && preg_match('~\A/(?!/)[^\x00-\x20\\\\]+\z~', $href) === 1
            && Service_Prx110_Catalog::resolve_status($module) !== 'deprecated';
    }

    public static function decision(string $name): ?string
    {
        if (self::$decisions === NULL)
        {
            self::$decisions = [];
            if (class_exists('Prx_Db'))
            {
                try
                {
                    $rows = Prx_Db::select('SELECT module_name, state FROM prx_110_publications');
                    self::$decisions = array_column($rows, 'state', 'module_name');
                }
                catch (Throwable $e)
                {
                    Log_Plain::event(Log::ERROR, 'prx110: не удалось прочитать решения о публикации (:type)', [':type' => get_class($e)]);
                }
            }
        }
        return self::$decisions[$name] ?? NULL;
    }

    public static function approve(string $name, Prx_Actor $actor): void
    {
        self::set_state($name, 'approved', $actor);
    }

    public static function set_state(string $name, string $state, Prx_Actor $actor): void
    {
        $actor->require('prx112.approve');
        if ( ! in_array($state, ['approved', 'wip'], TRUE))
        {
            throw HTTP_Exception::factory(400, 'Некорректное состояние публикации');
        }
        $module = Service_Prx110_Catalog::get($name);
        if ($module === NULL || $module['module_name'] !== $name || ! self::eligible($module))
        {
            throw HTTP_Exception::factory(404, 'Нет подключённого сервиса для изменения публикации');
        }

        Prx_Db::execute('INSERT INTO prx_110_publications (module_name, state, approved_by)
            VALUES (:name, :state, :actor) ON CONFLICT (module_name) DO UPDATE
            SET state = EXCLUDED.state, approved_by = EXCLUDED.approved_by, approved_at = now()
            WHERE prx_110_publications.state <> EXCLUDED.state', [
            ':name' => $name, ':state' => $state, ':actor' => $actor->id(),
        ]);
        self::$decisions = NULL;
    }
}
