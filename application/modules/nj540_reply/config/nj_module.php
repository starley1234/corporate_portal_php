<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Отзыв 2 Ответ» для nj110_catalog.
 */

return [
    'id'        => 540,
    'slug'      => 'reply',
    'title'     => 'Отзыв 2 Ответ',
    'short'     => 'ИИ-ответ на отзыв клиента: анализ тональности, выбор тона, готовый текст',
    'icon'      => 'fas fa-comment-dots',
    'href'      => '/review',
    'group'     => 'Текст',
    'sort'      => 540,
    'keywords'  => 'отзыв ответ review reply ИИ нейросеть llm чат gpt поддержка клиент магазин маркетплейс тональность',
    'api'       => ['/nj540/api/status/<code>'],
    'card_view' => 'nj540/card',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj540.reply' => [
                'label'     => 'Ответ на отзыв',
                'url'       => '/review/<code>',
            ],
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/nj100/errors) ----------------
    'health' => [
        'nj_540_replies' => [
            'label' => 'nj540 · ответы',
            'code'  => 'code',   // SQL-выражение «кода» записи
            'href'  => '',
        ],
    ],
];
