<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты nj604_news («Новости сайта»).
 */

return [
    'tools' => [
        [
            'name'        => 'nj604_latest_news',
            'description' => 'Последние новости платформы N2J.ru: обновления и новые инструменты (релизы из истории разработки + заметки).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'сколько новостей вернуть (1-10, по умолчанию 5)'],
                ],
            ],
            'handler'     => 'Service_Nj604_News::mcp_latest',
        ],
    ],
];
