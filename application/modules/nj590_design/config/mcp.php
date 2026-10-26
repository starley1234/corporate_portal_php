<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj590_design («Дизайн страницы»).
 * Собираются хабом nj160_mcp и отдаются агентам по /mcp/api/rpc.
 */

return [
    'tools' => [
        [
            'name'        => 'nj590_run_status',
            'description' => 'Статус прогона «Дизайн страницы» N2J.ru по коду: исходный URL, пожелания, состояние, список вариантов (номер, название, концепция), какой вариант выбран. Без содержимого HTML-вариантов.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'код прогона, напр. dsn1a2b3c4d'],
                ],
                'required' => ['code'],
            ],
            'handler' => 'Service_Nj590_Design::mcp_run',
        ],
    ],
];
