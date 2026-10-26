<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты nj603_kicker («Пиналка»).
 */

return [
    'tools' => [
        [
            'name'        => 'nj603_schedules',
            'access'      => 'admin', // чужие/платформенные данные — только ключ администратора
            'description' => 'Расписания «Пиналки» N2J.ru: какие модули и как часто пинаются по крону, когда последний пинок и сколько их было.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'handler'     => 'Service_Nj603_Kicker::mcp_schedules',
        ],
    ],
];
