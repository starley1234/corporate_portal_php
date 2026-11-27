<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow — Оцифровка спецификаций.
 *
 * Скан PDF спецификации/BOM → семантический шаблон → таблица позиций →
 * математический контроль → матчинг с НСИ (fuzzy) → верификация оператором →
 * JSON для ERP. Режим ocr — детерминированный (без LLM); vision и ocr_vision
 * распознают страницы мультимодальной LLM. Шаблоны — декларативные JSON
 * (config/templates + пользовательские из UI). Внешнее API — prx150.
 */
return [
    'id' => 400,
    'kind' => 'module',
    'slug' => 'prx400_specflow',
    'title' => 'Оцифровка спецификаций',
    'href' => '/prx400_specflow',
    'group' => 'ИИ-сервисы',
    'icon' => 'fas fa-table-list',
    'audience' => 'admin',
    'acl' => 'prx400.view',
    'short' => 'Скан спецификации/BOM → шаблон → проверенная таблица и JSON для ERP',
    'status' => 'beta',
    'created_at' => '2026-11-25',
    'updated_at' => '2026-11-27',
    'keywords' => 'спецификация вп bom ведомость позиции нси номенклатура таблица',
    'api' => ['/api/v1/spec-extractions', '/api/v1/spec-extractions/{id}'],
    'api_scopes' => [
        'prx400.extract' => [
            'title' => 'Оцифровка спецификаций',
            'description' => 'Загрузка PDF спецификации и получение позиций с кодами НСИ и статусом проверки',
            'rate_limit' => 60,
        ],
    ],
    'worker' => ['channels' => [
        'specflow.parse' => ['label' => 'Разбор спецификации', 'url' => '/prx120/monitor'],
    ]],
    'schedule' => ['specflow_purge' => [
        'label' => 'Сверка разборов с очередью, срок хранения и осиротевшие файлы prx400',
        'every' => '10 minutes',
        'command' => 'bin/console prx400:purge',
    ]],
];
