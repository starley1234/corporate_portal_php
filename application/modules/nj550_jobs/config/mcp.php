<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj550_jobs («Работа 2 Оффер»).
 * Собираются хабом nj160_mcp и отдаются агентам по /mcp/api/rpc.
 */

return [
    'tools' => [
        [
            'name'        => 'nj550_recent_searches',
            'access'      => 'admin', // чужие/платформенные данные — только ключ администратора
            'description' => 'Последние поиски вакансий пользователей N2J.ru: город, направление, статус, число найденных вакансий.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['new', 'done', 'error'], 'description' => 'фильтр по статусу'],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'сколько вернуть (по умолчанию 10)'],
                ],
            ],
            'handler' => 'Service_Nj550_Jobs::mcp_recent',
        ],
        [
            'name'        => 'nj550_get_search',
            'description' => 'Поиск вакансий по коду: параметры, статус, краткое резюме рынка и найденные вакансии со ссылками.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'публичный код поиска, напр. jb1a2b3c4d5'],
                ],
                'required' => ['code'],
            ],
            'handler' => 'Service_Nj550_Jobs::mcp_get',
        ],
        [
            'name'        => 'nj550_create_search',
            'description' => 'Запустить поиск вакансий: город + направление. Вернёт код и ссылку; результат готов через 20-60 секунд (потом nj550_get_search).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'city'      => ['type' => 'string', 'description' => 'город, напр. «Казань»'],
                    'direction' => ['type' => 'string', 'description' => 'должность или сфера, напр. «повар»'],
                ],
                'required' => ['city', 'direction'],
            ],
            'handler' => 'Service_Nj550_Jobs::mcp_create',
        ],
    ],
];
