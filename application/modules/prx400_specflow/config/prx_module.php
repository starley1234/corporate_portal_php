<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow — Оцифровка спецификаций.
 *
 * Скан PDF спецификации/BOM → семантический шаблон → таблица позиций →
 * математический контроль → матчинг с НСИ (fuzzy) → верификация оператором →
 * JSON для ERP. Разбор детерминированный (воркер без LLM), шаблоны —
 * декларативные JSON в config/templates (Config-Driven).
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
    'updated_at' => '2026-11-25',
    'keywords' => 'спецификация вп bom ведомость позиции нси номенклатура таблица',
    'api_scopes' => [
        'prx400.export' => [
            'title' => 'Экспорт спецификаций',
            'description' => 'JSON проверенной спецификации (позиции, НСИ-коды, верификация)',
            'rate_limit' => 60,
        ],
    ],
    'worker' => ['channels' => [
        'specflow.parse' => ['label' => 'Разбор спецификации', 'url' => '/prx120/monitor'],
    ]],
];
