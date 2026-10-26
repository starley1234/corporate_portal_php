<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «MCP для ИИ-агентов» (nj160_mcp) для nj110_catalog.
 */

return [
    'id'        => 160,
    'slug'      => 'nj160_mcp',
    'title'     => 'MCP для ИИ-агентов',
    'short'     => 'Подключите N2J к Claude, Cursor или своему агенту: сервисы платформы — как инструменты ИИ',
    'icon'      => 'fas fa-plug',
    'href'      => '/mcp',
    'group'     => 'Платформа',
    // 06.10.2026: из левого меню убран в группу «Платформа» — ссылка на странице
    // «Администрирование» (/admin); в каталоге /services и палитре остаётся.
    'menu'       => FALSE,
    'sort'      => 160,
    'badge'     => 'новое',
    'keywords'  => 'mcp агент ии claude cursor подключение интеграция инструменты api',
    'api'       => ['/mcp/api/rpc'],
    'card_view' => 'nj160/card',
    'auth'      => FALSE,
];
