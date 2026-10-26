<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Sketch 2 Solid» (3D по тексту и фото) для nj110_catalog.
 */

return [
    'id'        => 570,
    'slug'      => 'nj570_models',
    'title'     => '3D-модель по тексту и фото',
    'short'     => 'Опишите деталь или покажите эскиз — получите готовую STL-модель для 3D-печати',
    'icon'      => 'fas fa-cube',
    'href'      => '/3d',
    'group'     => 'Сервисы',
    'sort'      => 570,
    'keywords'  => '3d модель stl openscad печать конструкция деталь чертеж эскиз cad print model 3д',
    'api'       => ['/3d/api/status/<code>'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => '3D · управление', 'href' => '/nj570/admin'],
    'auth'      => TRUE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj570.generate' => [
                'label'     => '3D-модель',
                'url'       => '/3d/<code>',
            ],
            'nj570.clarify' => [
                'label'     => '3D-модель (опрос)',
                'url'       => '/3d/<code>',
            ],
            'nj570.render' => [
                'label'     => '3D-модель (рендер)',
                'url'       => '/3d/<code>',
            ],
        ],
    ],

    // --- Пятый контракт: своё в ленте «последнее» (/nj100) -----------------------
    'recent' => [
        'nj_570_models' => [
            'title'  => "title",   // SQL-выражение заголовка
            'status' => 'status',
            'base'   => '/3d/',
            'icon'   => 'fas fa-cube',
        ],
    ],

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'model' => [
            'label' => '3D-модели',
            'icon'  => 'fas fa-cube',
            'sort'  => 20,
            'scope' => 'user',
            'sql'   => "SELECT 'model' AS kind, code, title AS t1, '' AS t2, left(description, 110) AS sub,
                        '/3d/' || code AS url, created_at
                 FROM nj_570_models
                 WHERE title ILIKE :l OR description ILIKE :l :scope
                 ORDER BY created_at DESC LIMIT :per",
        ],
    ],
];
