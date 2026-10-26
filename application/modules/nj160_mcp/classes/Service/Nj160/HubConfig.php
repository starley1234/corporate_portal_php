<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj160: конфигурация хаба (таблица nj_160_settings).
 *
 * allowed_categories — NULL (все) или список категорий; категория инструмента
 * берётся из 'category' декларации либо = имя модуля. disabled_tools — точечный
 * чёрный список. Управляется MCP-инструментами nj160_hub_* (только управление)
 * и карточкой админа на /mcp.
 */
class Service_Nj160_HubConfig {

    const K_ALLOWED = 'allowed_categories';
    const K_DISABLED = 'disabled_tools';

    protected static function _db()
    {
        return Database::instance();
    }

    /** Значение настройки (NULL = не задана) — декодированный JSON. */
    public static function get(string $key)
    {
        try
        {
            $row = self::_db()->query(Database::SELECT,
                'SELECT value FROM nj_160_settings WHERE key = '.self::_db()->quote($key).' LIMIT 1', FALSE)->current();

            if ( ! $row OR $row['value'] === NULL)
            {
                return NULL;
            }

            $v = json_decode((string) $row['value'], TRUE);

            return is_array($v) ? $v : NULL;
        }
        catch (Throwable $e)
        {
            return NULL; // таблицы ещё нет — хаб работает «по умолчанию»
        }
    }

    /** Сохранить настройку (NULL = сброс). */
    public static function set(string $key, ?array $value)
    {
        $db = self::_db();

        if ($value === NULL)
        {
            $db->query(Database::DELETE, 'DELETE FROM nj_160_settings WHERE key = '.$db->quote($key), FALSE);

            return;
        }

        $db->query(Database::INSERT,
            'INSERT INTO nj_160_settings (key, value, updated_at)
             VALUES ('.$db->quote($key).', '.$db->quote(json_encode(array_values($value), JSON_UNESCAPED_UNICODE)).", now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()", FALSE);
    }

    /** Категории, разрешённые к показу агентам; NULL — без ограничений. */
    public static function allowed_categories()
    {
        return self::get(self::K_ALLOWED);
    }

    /** Точечно выключенные инструменты; NULL/[] — все включены. */
    public static function disabled_tools()
    {
        $v = self::get(self::K_DISABLED);

        return is_array($v) ? $v : [];
    }

    /** Снимок для UI/инструмента: категории и их инструменты. */
    public static function snapshot(bool $is_admin): array
    {
        $allowed = self::allowed_categories();
        $disabled = self::disabled_tools();
        $cats = [];

        foreach (Service_Nj160_Registry::tools_raw() as $tool)
        {
            $cat = (string) ($tool['category'] ?? $tool['module']);
            $cats[$cat] = $cats[$cat] ?? ['category' => $cat, 'tools' => [], 'admin_only' => 0];
            $cats[$cat]['tools'][] = [
                'name'   => (string) $tool['name'],
                'desc'   => (string) ($tool['description'] ?? ''),
                'access' => (string) ($tool['access'] ?? 'user'),
                'off'    => in_array((string) $tool['name'], $disabled, TRUE),
            ];

            if ((string) ($tool['access'] ?? 'user') === 'admin')
            {
                $cats[$cat]['admin_only']++;
            }
        }

        ksort($cats);

        return [
            'allowed_categories' => $allowed,
            'disabled_tools'     => $disabled,
            'categories'         => array_values($cats),
        ];
    }

}
