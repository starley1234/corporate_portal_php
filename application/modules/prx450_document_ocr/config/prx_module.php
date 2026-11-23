<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prx450_document_ocr — распознавание документов через внешний шлюз.
 *
 * Модуль работает главным образом как API: потребитель получает ключ prx150,
 * адрес /api/v1/document-ocr/parse и контракт «multipart file → Markdown».
 * Страница /prx450_document_ocr — админская проверка сервиса.
 */
return [
    'id' => 450,
    'slug' => 'prx450_document_ocr',
    'kind' => 'module',
    'title' => 'Распознавание документов',
    'short' => 'PDF и сканы → Markdown через внешний OCR-шлюз; API для интеграций',
    'icon' => 'fas fa-file-lines',
    'href' => '/prx450_document_ocr',
    'group' => 'ИИ-сервисы',
    'sort' => 450,
    'status' => 'beta',
    'audience' => 'admin',
    'acl' => 'prx450.view',
    'created_at' => '2026-11-23',
    'api' => ['/api/v1/document-ocr/parse'],
    'api_scopes' => [
        'prx450.parse' => [
            'title' => 'Распознавание документов',
            'description' => 'PDF и изображения → Markdown через внешний OCR-шлюз',
            'rate_limit' => 60,
        ],
    ],
];
