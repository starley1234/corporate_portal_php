<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты самого хаба (nj160_mcp): настройка каталога инструментов.
 * Только для ключей уровня admin — обычный агент их не видит и не вызовет.
 * Описания написаны для слабых моделей: коротко, по-русски, с примерами.
 */

return [
    'tools' => [

        [
            'name'        => 'nj160_hub_overview',
            'access'      => 'admin',
            'category'    => 'hub',
            'description' => 'Конфигурация MCP-хаба N2J.ru: какие категории инструментов есть, какие включены для агентов, кто админские. Вызывай ПЕРЕД изменениями, чтобы увидеть текущее состояние.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'handler'     => 'Service_Nj160_Hub::mcp_overview',
        ],

        [
            'name'        => 'nj160_hub_configure',
            'access'      => 'admin',
            'category'    => 'hub',
            'description' => 'Настроить MCP-хаб N2J.ru: оставить агентам только выбранные категории инструментов и/или выключить отдельные инструменты. Чтобы вернуть всё как было — передай {"reset": true}. Пример: {"allow_categories": ["nj591_company_profile", "nj605_radar"]} — агентам будут видны только эти категории.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'allow_categories' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'список категорий (имена модулей: nj591_company_profile, nj605_radar, hub…), которые ВИДНЫ агентам; не передавай, если не хочешь менять',
                    ],
                    'disable_tools' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'точечно выключить инструменты по именам (nj602_article, …); не передавай, если не хочешь менять',
                    ],
                    'reset' => [
                        'type'        => 'boolean',
                        'description' => 'true — сбросить ВСЕ ограничения (видны все категории и инструменты)',
                    ],
                ],
            ],
            'handler'     => 'Service_Nj160_Hub::mcp_configure',
        ],

    ],
];
