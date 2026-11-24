<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 397,
    'kind' => 'module',
    'slug' => 'prx397_invoice',
    'title' => 'Поля из счёта',
    'href' => '/prx397_invoice',
    'group' => 'ИИ-сервисы',
    'icon' => 'fas fa-file-invoice',
    'audience' => 'admin',
    'acl' => 'prx397.ui',
    'short' => 'PDF счёта/УПД → распознавание → структурированные поля и проверки',
    'status' => 'beta',
    'created_at' => '2026-11-24',
    'updated_at' => '2026-11-24',
    'api' => ['/api/v1/invoice-extractions', '/api/v1/invoice-extractions/{id}'],
    'api_scopes' => [
        'prx397.extract' => [
            'title' => 'Поля из счёта',
            'description' => 'Извлечение полей счёта/УПД из PDF с проверками',
            'rate_limit' => 60,
        ],
    ],
    'worker' => ['channels' => [
        'invoice.extract' => ['label' => 'Поля из счёта', 'url' => '/prx120/monitor'],
    ]],
    'schedule' => ['invoice_purge' => [
        'label' => 'Удаление истёкших файлов и результатов prx397',
        'every' => '10 minutes',
        'command' => 'bin/console prx397:purge',
    ]],
];
