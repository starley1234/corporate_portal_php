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
    'api' => [
        '/api/v1/spec-extractions', '/api/v1/spec-extractions/{id}', '/api/v1/spec-extractions/{id}/export',
        '/api/v1/spec-extractions/{id}/files/{n}', '/api/v1/spec-extractions/{id}/rows', '/api/v1/spec-extractions/{id}/rows/{row}',
        '/api/v1/spec-extractions/{id}/verify-bulk', '/api/v1/spec-extractions/{id}/rematch', '/api/v1/spec-extractions/{id}/relaunch', '/api/v1/spec-extractions/{id}/finish',
        '/api/v1/spec-extractions/webhook', '/api/v1/spec-catalog',
    ],
    'api_scopes' => [
        'prx400.extract' => [
            'title' => 'Оцифровка спецификаций',
            'description' => 'Загрузка PDF спецификации и получение позиций с кодами НСИ и статусом проверки',
            'rate_limit' => 60,
        ],
        'prx400.review' => [
            'title' => 'Проверка спецификаций (HITL)',
            'description' => 'Правка, подтверждение и коды НСИ позиций, перезапуск и завершение проверки — поверх prx400.extract',
            'rate_limit' => 600,
        ],
    ],
    'notify' => [
        'events' => [
            'prx400.spec.review' => [
                'title' => 'Спецификация разобрана — нужна проверка',
                'description' => 'Позиции распознаны и сопоставлены с НСИ; спорные строки ждут оператора.',
                'category' => 'documents',
                'icon' => 'fas fa-table-list',
                'channels' => 'in_app',
                'roles' => 'admin',
                'digest' => FALSE,
                'placeholders' => 'title,href',
            ],
            'prx400.spec.done' => [
                'title' => 'Проверка спецификации завершена',
                'description' => 'Спецификация готова к выгрузке в ERP.',
                'category' => 'documents',
                'icon' => 'fas fa-circle-check',
                'channels' => 'in_app',
                'roles' => 'admin',
                'digest' => TRUE,
                'placeholders' => 'title,href',
            ],
            'prx400.spec.failed' => [
                'title' => 'Не удалось разобрать спецификацию',
                'description' => 'Разбор завершился ошибкой: другой режим, шаблон или файл.',
                'category' => 'documents',
                'icon' => 'fas fa-file-circle-exclamation',
                'channels' => 'in_app,email',
                'roles' => 'admin',
                'digest' => FALSE,
                'placeholders' => 'title,error,href',
            ],
        ],
    ],
    'worker' => ['channels' => [
        'specflow.parse' => ['label' => 'Разбор спецификации', 'url' => '/prx120/monitor'],
    ]],
    'schedule' => ['specflow_purge' => [
        'label' => 'Сверка разборов с очередью, срок хранения и осиротевшие файлы prx400',
        'every' => '10 minutes',
        'command' => 'bin/console prx400:purge',
    ],
    'specflow_hooks' => [
        'label' => 'Доставка webhook prx400 (spec.review/done/failed) с повторами',
        'every' => '1 minute',
        'command' => 'bin/console prx400:hooks',
    ]],
];
