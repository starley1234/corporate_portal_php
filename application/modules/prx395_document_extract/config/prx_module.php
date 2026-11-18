<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 395,
    'slug' => 'document_extract',
    'title' => 'Распознавание PDF',
    'href' => '/document-extract',
    'group' => 'ИИ-сервисы',
    'icon' => 'fas fa-file-pdf',
    'audience' => 'admin',
    'acl' => 'prx395.ui',
    'short' => 'Асинхронный API для корпоративных систем: PDF → текст',
    'status' => 'beta',
    'created_at' => '2026-11-18',
    'updated_at' => '2026-11-18',
    'api' => ['/api/v1/document-extractions', '/api/v1/document-extractions/{id}'],
    'worker' => ['channels' => [
        'document.extract' => ['label' => 'PDF → текст', 'url' => '/prx120/monitor'],
    ]],
    'schedule' => ['document_extract_purge' => [
        'label' => 'Удаление истёкших PDF и результатов prx395',
        'every' => '10 minutes',
        'command' => 'bin/console prx395:purge',
    ]],
];
