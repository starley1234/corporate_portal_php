<?php defined('SYSPATH') or die('No direct script access.');

return [
    'id' => 396,
    'slug' => 'prx396_pdf_chunks',
    'title' => 'Большие PDF по частям',
    'href' => '/prx396_pdf_chunks',
    'group' => 'ИИ-сервисы',
    'icon' => 'fas fa-file-pdf',
    'audience' => 'admin',
    'acl' => 'prx396.ui',
    'short' => 'Асинхронный API: большой PDF → текст по частям с продолжением после сбоя',
    'status' => 'beta',
    'created_at' => '2026-11-24',
    'updated_at' => '2026-11-24',
    'api' => ['/api/v1/pdf-chunks', '/api/v1/pdf-chunks/{id}', '/api/v1/pdf-chunks/{id}/result'],
    'api_scopes' => [
        'prx396.extract' => [
            'title' => 'Большой PDF → текст по частям',
            'description' => 'Асинхронное распознавание больших PDF с продолжением после сбоя',
            'rate_limit' => 60,
        ],
    ],
    'worker' => ['channels' => [
        'pdf.chunks' => ['label' => 'PDF → текст по частям', 'url' => '/prx120/monitor'],
    ]],
    'schedule' => ['pdf_chunks_purge' => [
        'label' => 'Удаление истёкших PDF и результатов prx396',
        'every' => '10 minutes',
        'command' => 'bin/console prx396:purge',
    ]],
];
