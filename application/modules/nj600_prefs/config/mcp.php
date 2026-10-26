<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты nj600_prefs («Аккумулятор предпочтений»).
 * Персональные данные не отдаются без явного owner_key (приватность).
 */

return [
    'tools' => [
        [
            'name'        => 'nj600_platform_top',
            'access'      => 'admin', // чужие/платформенные данные — только ключ администратора
            'description' => 'Агрегаты интересов платформы N2J.ru: топ разделов и поисковых фраз (без персональных данных).',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'handler'     => 'Service_Nj600_Prefs::mcp_platform_top',
        ],
        [
            'name'        => 'nj600_profile_by_key',
            'access'      => 'admin', // чужие/платформенные данные — только ключ администратора
            'description' => 'Компактный профиль интересов одного владельца (owner_key вида u:<id> или g:<ubid>): топ разделов, фраз, источников.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'owner_key' => ['type' => 'string', 'description' => 'u:1 или g:<ubid>'],
                ],
                'required' => ['owner_key'],
            ],
            'handler'     => 'Service_Nj600_Prefs::mcp_profile_by_key',
        ],
        [
            'name'        => 'nj600_module_stats',
            'access'      => 'admin', // чужие/платформенные данные — только ключ администратора
            'description' => 'Популярность модулей N2J.ru за 7 дней: заходы по разделам против результата (готовые/упавшие задачи воркеров); флаг «уходят не добившись результата».',
            'inputSchema' => ['type' => 'object', 'properties' => []],
            'handler'     => 'Service_Nj600_Prefs::mcp_module_stats',
        ],
        [
            'name'        => 'nj600_recommend',
            'access'      => 'admin', // персональные рекомендации — только ключ администратора
            'description' => 'Рекомендации сервисов N2J.ru одному владельцу (owner_key вида u:<id> или g:<ubid>) с причинами: правила администратора, похожие посетители, тот же раздел каталога.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'owner_key' => ['type' => 'string', 'description' => 'u:1 или g:<ubid>'],
                    'limit'     => ['type' => 'integer', 'description' => 'сколько вернуть (1–10, по умолчанию 5)'],
                ],
                'required' => ['owner_key'],
            ],
            'handler'     => 'Service_Nj600_Reco::mcp_recommend',
        ],
    ],
];
