<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты модуля nj570_models (3D-конструктор «Sketch 2 Solid»).
 * Собираются хабом nj160_mcp и отдаются агентам по /mcp/api/rpc.
 */

return [
    'tools' => [
        [
            'name'        => 'nj570_list_models',
            'description' => 'Опубликованные 3D-модели пользователей N2J.ru (текст+фото → OpenSCAD → STL): название, описание, рейтинг. Опционально текстовый поиск.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'q'     => ['type' => 'string', 'description' => 'поиск по названию/описанию (необязательно)'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 24, 'description' => 'сколько вернуть (по умолчанию 12)'],
                ],
            ],
            'handler' => 'Service_Nj570_Models::mcp_list',
        ],
        [
            'name'        => 'nj570_get_model',
            'description' => '3D-модель по коду: описание, ревизии (код OpenSCAD последней готовой), ссылки на STL/PNG.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'публичный код модели, напр. m3d1a2b3c4d'],
                ],
                'required' => ['code'],
            ],
            'handler' => 'Service_Nj570_Models::mcp_get',
        ],
    ],
];
