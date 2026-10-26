<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Работа 2 Оффер» для nj110_catalog.
 */

return [
    'id'        => 550,
    'slug'      => 'jobs',
    'title'     => 'Поиск вакансий',
    'short'     => 'Вакансии города по вашему направлению: свежие объявления с сайтов работы и карьерных страниц компаний',
    'icon'      => 'fas fa-briefcase',
    'href'      => '/jobs',
    'group'     => 'Карьера',
    'sort'      => 550,
    'keywords'  => 'работа вакансии поиск карьера job вакансию найти устроиться компания hh резюме',
    'api'       => ['/nj550/api/status/<code>'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Вакансии · управление', 'href' => '/nj550/admin'],
    'card_view' => 'nj550/card',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj550.jobs' => [
                'label'     => 'Поиск вакансий',
                'url'       => '/jobs/<code>',
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ----------------------------
    'cleanup' => [
        'nj550_results' => [
            'label'      => 'nj550 · найденные вакансии',
            'note'       => 'Списки вакансий старше 30 дней; сами поиски (параметры) остаются.',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM nj_550_results WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_550_results WHERE id IN (
                                SELECT id FROM nj_550_results WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/nj100/errors) ----------------
    'health' => [
        'nj_550_searches' => [
            'label' => 'nj550 · поиск вакансий',
            'code'  => 'code',   // SQL-выражение «кода» записи
            'href'  => '',
        ],
    ],

    // --- Пятый контракт: своё в ленте «последнее» (/nj100) -----------------------
    'recent' => [
        'nj_550_searches' => [
            'title'  => "direction",   // SQL-выражение заголовка
            'status' => 'status',
            'base'   => '/jobs/',
            'icon'   => 'fas fa-briefcase',
        ],
    ],

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'job' => [
            'label' => 'Вакансии',
            'icon'  => 'fas fa-briefcase',
            'sort'  => 50,
            'scope' => 'all',
            'sql'   => "SELECT 'job' AS kind, search_id AS code, title AS t1, company AS t2, left(snippet, 110) AS sub,
                        '/jobs/' || search_id AS url, created_at
                 FROM nj_550_results
                 WHERE title ILIKE :l OR company ILIKE :l OR snippet ILIKE :l
                 ORDER BY created_at DESC LIMIT :per",
        ],
    ],
];
