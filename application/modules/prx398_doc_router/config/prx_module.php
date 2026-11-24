<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 398,
    'kind' => 'module',
    'slug' => 'prx398_doc_router',
    'title' => 'Маршрутизация документов',
    'href' => '/prx398_doc_router',
    'group' => 'ИИ-сервисы',
    'icon' => 'fas fa-route',
    'audience' => 'admin',
    'acl' => 'prx398.ui',
    'short' => 'PDF → классификация → извлечение → валидация → маршрут/ручная проверка',
    'status' => 'beta',
    'created_at' => '2026-11-24',
    'updated_at' => '2026-11-24',
    'api' => ['/api/v1/document-routes', '/api/v1/document-routes/{id}'],
    'api_scopes' => [
        'prx398.route' => [
            'title' => 'Маршрутизация документов',
            'description' => 'Классификация и маршрутизация PDF-документов',
            'rate_limit' => 60,
        ],
    ],
    'worker' => ['channels' => [
        'doc.route' => ['label' => 'Маршрутизация документов', 'url' => '/prx120/monitor'],
    ]],
    'schedule' => ['doc_route_purge' => [
        'label' => 'Удаление истёкших файлов и результатов prx398',
        'every' => '10 minutes',
        'command' => 'bin/console prx398:purge',
    ]],
];
