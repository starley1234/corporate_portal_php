<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Журнал N2J» (nj602_content) для nj110_catalog.
 * Публично позиционируется как редакционный журнал (без упоминаний
 * автоматической генерации — решение редакции от 22.09, SEO).
 */

return [
    'id'        => 602,
    'slug'      => 'nj602_content',
    'title'     => 'Журнал N2J',
    'short'     => 'Статьи о технологиях для малого бизнеса: живой язык, схемы, ссылки на источники. Каждый материал проверяет редакция перед публикацией',
    'icon'      => 'fas fa-newspaper',
    'href'      => '/content',
    'group'     => 'Платформа',
    // 06.10.2026: из левого меню убран в группу «Платформа» — ссылка на странице
    // «Администрирование» (/admin); в каталоге /services и палитре остаётся.
    'menu'       => FALSE,
    'sort'      => 602,
    'badge'     => 'новое',
    'keywords'  => 'журнал блог статьи публикации технологии малый бизнес источники схемы mermaid seo',
    'api'       => ['/content/api/create'],
    'admin'     => ['title' => 'Контент · модерация', 'href' => '/nj602/admin'],
    'auth'      => FALSE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj602.article' => [
                'label'     => 'Статья в журнал (nj602)',
                'url'       => '/nj602/admin',

                // Статья доставлена воркером nj602.article: статья в nj_602_articles
                // создана/принята (finished_at проставлен при api_save) — задача должна
                // быть done, «Готово за 24 ч» обязан её посчитать.
                'reconcile' => "SELECT 1 FROM nj_602_articles a
             WHERE a.code = j.payload->>'code'
               AND a.status IN ('review', 'published')
               AND a.finished_at IS NOT NULL",
            ],
        ],
    ],

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/nj603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        'content' => [
                'label'   => 'Свежая статья в журнал (nj602)',
                'every'   => '2 hours',
                'enabled' => TRUE,
                'url'     => '/content/api/create',
                'payload' => ['topic' => ''],
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/nj100/errors) ----------------
    'health' => [
        'nj_602_articles' => [
            'label' => 'nj602 · статья',
            'code'  => 'code',   // SQL-выражение «кода» записи
            'href'  => '/nj602/admin',
        ],
    ],
];
