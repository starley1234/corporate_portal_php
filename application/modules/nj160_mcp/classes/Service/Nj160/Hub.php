<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj160: обработчики MCP-инструментов настройки самого хаба.
 * Вызываются только с админского ключа (проверка access — в Registry/Rpc).
 */
class Service_Nj160_Hub {

    /** nj160_hub_overview: текущая конфигурация каталога. */
    public static function mcp_overview(array $args): array
    {
        return Service_Nj160_HubConfig::snapshot(TRUE);
    }

    /** nj160_hub_configure: ограничить категории/инструменты или сбросить. */
    public static function mcp_configure(array $args): array
    {
        $reset = ! empty($args['reset']);
        $changed = [];

        if ($reset)
        {
            Service_Nj160_HubConfig::set(Service_Nj160_HubConfig::K_ALLOWED, NULL);
            Service_Nj160_HubConfig::set(Service_Nj160_HubConfig::K_DISABLED, NULL);
            $changed[] = 'сброшены все ограничения';

            return ['ok' => TRUE, 'changed' => $changed, 'state' => Service_Nj160_HubConfig::snapshot(TRUE)];
        }

        if (array_key_exists('allow_categories', $args))
        {
            $cats = $args['allow_categories'];

            if ( ! is_array($cats))
            {
                return ['error' => 'allow_categories должен быть списком строк-категорий'];
            }

            $cats = array_values(array_filter(array_map('strval', $cats), static fn($s) => trim($s) !== ''));
            Service_Nj160_HubConfig::set(Service_Nj160_HubConfig::K_ALLOWED, $cats === [] ? NULL : $cats);
            $changed[] = $cats === [] ? 'ограничение категорий снято (видны все)' : 'разрешённые категории: '.implode(', ', $cats);
        }

        if (array_key_exists('disable_tools', $args))
        {
            $tools = $args['disable_tools'];

            if ( ! is_array($tools))
            {
                return ['error' => 'disable_tools должен быть списком имён инструментов'];
            }

            $tools = array_values(array_filter(array_map('strval', $tools), static fn($s) => trim($s) !== ''));
            Service_Nj160_HubConfig::set(Service_Nj160_HubConfig::K_DISABLED, $tools === [] ? NULL : $tools);
            $changed[] = $tools === [] ? 'чёрный список инструментов очищен' : 'выключены инструменты: '.implode(', ', $tools);
        }

        if ($changed === [])
        {
            return ['error' => 'не передано ни одного изменения — смотри nj160_hub_overview или передай allow_categories/disable_tools/reset'];
        }

        return ['ok' => TRUE, 'changed' => $changed, 'state' => Service_Nj160_HubConfig::snapshot(TRUE)];
    }

}
