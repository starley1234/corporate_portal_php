<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Холодное письмо» (nj592_coldmail) для nj110_catalog.
 */

return [
    'id'        => 592,
    'slug'      => 'nj592_coldmail',
    'title'     => 'Холодное письмо',
    'short'     => 'Ультра-персонализированный первый абзац для холодного B2B-письма: по профилю компании и свежим новостям',
    'icon'      => 'fas fa-envelope-open-text',
    'href'      => '/coldmail',
    'group'     => 'Продажи',
    'sort'      => 592,
    'keywords'  => 'холодное письмо b2b продажа персонализация лид аутрич email абзац тема рассылка outreach лидогенерация',
    'api'       => ['/coldmail/api/status/<code>'],
    // раздел управления модуля: чип в шапке (только администратору)
    'admin'     => ['title' => 'Письмо · управление', 'href' => '/nj592/admin'],
    'auth'      => TRUE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj592.email' => [
                'label'     => 'Холодное письмо',
                'url'       => '/coldmail/<code>',
            ],
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/nj100/errors) ----------------
    'health' => [
        'nj_592_letters' => [
            'label' => 'nj592 · письма',
            'code'  => 'code',   // SQL-выражение «кода» записи
            'href'  => '',
        ],
    ],

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'letter' => [
            'label' => 'Холодные письма',
            'icon'  => 'fas fa-envelope',
            'sort'  => 60,
            'scope' => 'user',
            'sql'   => "SELECT 'letter' AS kind, code, company_query AS t1, '' AS t2, left(coalesce(profile_code, product), 110) AS sub,
                        '/coldmail/' || code AS url, created_at
                 FROM nj_592_letters
                 WHERE company_query ILIKE :l OR product ILIKE :l :scope
                 ORDER BY created_at DESC LIMIT :per",
        ],
    ],
];
