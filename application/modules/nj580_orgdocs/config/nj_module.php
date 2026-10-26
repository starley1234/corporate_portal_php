<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Документы организации» (nj580_orgdocs) для nj110_catalog.
 */

return [
    'id'        => 580,
    'slug'      => 'nj580_orgdocs',
    'title'     => 'Документы организации',
    'short'     => 'Пакет документов организации: кадры, охрана труда, регламенты — Word + PDF + Excel по ГОСТ',
    'icon'      => 'fas fa-folder-open',
    'href'      => '/docs',
    'group'     => 'Документы',
    'sort'      => 580,
    'keywords'  => 'документы организация устав положение приказ должностная инструкция штатное расписание охрана труда персональные данные регламент кадров делопроизводство gost word pdf excel docx',
    'api'       => ['/docs/api/status/<code>'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Документы · управление', 'href' => '/nj580/admin'],
    'auth'      => TRUE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj580.generate' => [
                'label'     => 'Документы организации',
                'url'       => '/docs/<code>',
            ],
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/nj100/errors) ----------------
    'health' => [
        'nj_580_packages' => [
            'label' => 'nj580 · пакет документов',
            'code'  => 'code',   // SQL-выражение «кода» записи
            'href'  => '/docs/',
        ],
    ],

    // --- Пятый контракт: своё в ленте «последнее» (/nj100) -----------------------
    'recent' => [
        'nj_580_packages' => [
            'title'  => "org_name",   // SQL-выражение заголовка
            'status' => 'status',
            'base'   => '/docs/',
            'icon'   => 'fas fa-file-lines',
        ],
    ],

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'orgdocs' => [
            'label' => 'Досье организаций',
            'icon'  => 'fas fa-file-alt',
            'sort'  => 0,
            'scope' => 'user',
            'sql'   => "SELECT 'orgdocs' AS kind, code, title AS t1, org_name AS t2, left(regexp_replace(summary, E'[\\n\\r\\t ]+', ' ', 'g'), 110) AS sub,
                        '/docs/' || code AS url, created_at
                 FROM nj_580_packages
                 WHERE title ILIKE :l OR org_name ILIKE :l OR summary ILIKE :l :scope
                 ORDER BY created_at DESC LIMIT :per",
        ],
    ],
];
