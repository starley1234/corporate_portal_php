<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Профиль компании» (nj591_company_profile) для nj110_catalog.
 */

return [
    'id'        => 591,
    'slug'      => 'nj591_company_profile',
    'title'     => 'Профиль компании',
    'short'     => 'Глубокий профиль компании по домену (или названию): сфера, размер, контакты, стек, ЛПР — в JSON',
    'icon'      => 'fas fa-building',
    'href'      => '/company',
    'group'     => 'Продажи',
    'sort'      => 591,
    'keywords'  => 'компания профиль домен лид б2б продажи лпр контакты стек размер сфера json исследование конкурент cold outreach',
    'api'       => ['/company/api/status/<code>'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Профиль · управление', 'href' => '/nj591/admin'],
    'auth'      => TRUE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj591.company' => [
                'label'     => 'Профиль компании',
                'url'       => '/company/<code>',
            ],
            'nj591.profile' => [

                // Профиль компании (nj591): приёмка set_result ставит
                // profile.status='done' и привязку profile.job_id — результат на сайте,
                // задача обязана быть done.
                'reconcile' => "SELECT 1 FROM nj_591_profiles p
             WHERE p.job_id = j.id AND p.status = 'done'",
            ],
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/nj100/errors) ----------------
    'health' => [
        'nj_591_profiles' => [
            'label' => 'nj591 · профиль компании',
            'code'  => 'code',   // SQL-выражение «кода» записи
            'href'  => '/company/',
        ],
    ],

    // --- Пятый контракт: своё в ленте «последнее» (/nj100) -----------------------
    'recent' => [
        'nj_591_profiles' => [
            'title'  => "query",   // SQL-выражение заголовка
            'status' => 'status',
            'base'   => '/company/',
            'icon'   => 'fas fa-building',
        ],
    ],

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'profile' => [
            'label' => 'Карточки компаний',
            'icon'  => 'fas fa-building',
            'sort'  => 10,
            'scope' => 'user',
            'sql'   => "SELECT 'profile' AS kind, code, company AS t1, domain AS t2, left(query, 110) AS sub,
                        '/company/' || code AS url, created_at
                 FROM nj_591_profiles
                 WHERE company ILIKE :l OR domain ILIKE :l OR query ILIKE :l :scope
                 ORDER BY created_at DESC LIMIT :per",
        ],
    ],
];
