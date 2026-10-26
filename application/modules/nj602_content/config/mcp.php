<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты nj602_content (журнал N2J).
 */

return [
    'tools' => [
        [
            'name'        => 'nj602_latest_articles',
            'description' => 'Последние статьи журнала N2J.ru: заголовок, лид, ссылка, дата.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'сколько статей вернуть (1-10, по умолчанию 5)'],
                ],
            ],
            'handler'     => 'Service_Nj602_Content::mcp_latest',
        ],
        [
            'name'        => 'nj602_article',
            'description' => 'Статья журнала N2J.ru целиком: текст (Markdown), mermaid-схема, граф сущностей, источники.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'слаг статьи (см. nj602_latest_articles)'],
                ],
                'required'   => ['slug'],
            ],
            'handler'     => 'Service_Nj602_Content::mcp_article',
        ],
    ],
];
