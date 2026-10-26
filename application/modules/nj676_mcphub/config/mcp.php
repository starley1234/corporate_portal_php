<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инструменты каталога MCP для ИИ-агентов (/mcp/api/rpc и шлюз /mcphub/api/rpc).
 *
 * Описания написаны для слабых моделей: что делает, когда звать, что вернёт.
 * Права — 'user': каталог публичный, но персональные данные не отдаются.
 */
return [
    'tools' => [

        [
            'name'        => 'mcp_catalog_search',
            'access'      => 'user',
            'category'    => 'mcphub',
            'description' => 'Найти готовый MCP-сервер под задачу в живом каталоге N2J (MCP Hub). '
                .'Ищи словами задачи, а не названием: «поиск в интернете», «база данных Postgres», '
                .'«браузер», «почта Gmail». Вернёт slug, описание, транспорт, нужен ли ключ, '
                .'ссылку на карточку и команду установки. Дальше — mcp_server_plan для конфигурации.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'query'    => ['type' => 'string', 'description' => 'Что нужно делать, обычными словами'],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['development', 'web', 'data', 'ai', 'files', 'productivity',
                                   'cloud', 'communication', 'finance', 'security', 'media', 'research', 'other'],
                        'description' => 'Необязательное ограничение темы',
                    ],
                    'no_auth'  => ['type' => 'boolean', 'description' => 'true — только серверы без ключей и OAuth'],
                    'limit'    => ['type' => 'integer', 'description' => 'Сколько вернуть (1–8, по умолчанию 5)'],
                ],
                'required' => ['query'],
            ],
            'handler'     => 'Service_Nj676_Hub::catalog_search',
        ],

        [
            'name'        => 'mcp_server_plan',
            'access'      => 'user',
            'category'    => 'mcphub',
            'description' => 'Готовый план подключения MCP-сервера из каталога по его slug: фрагмент конфигурации '
                .'для Claude Desktop / Cursor / Windsurf, имена нужных переменных окружения и что проверить '
                .'перед запуском. Секретов не содержит — значения подставляет пользователь локально.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'Slug сервера из mcp_catalog_search'],
                ],
                'required' => ['slug'],
            ],
            'handler'     => 'Service_Nj676_Hub::server_plan',
        ],

    ],
];
