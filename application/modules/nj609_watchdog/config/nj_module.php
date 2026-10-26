<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест «Сторож контрагентов» (nj609_watchdog) для nj110_catalog.
 */

return [
    'id'       => 609,
    'slug'     => 'nj609_watchdog',
    'title'    => 'Сторож контрагентов',
    'short'    => 'Суды, банкротства, смены в ЕГРЮЛ и плохие новости по вашим клиентам и поставщикам — еженедельная проверка с фильтром шума',
    'icon'     => 'fas fa-shield-halved',
    'href'     => '/watchdog',
    'card_view' => '609/card',
    'group'    => 'Продажи',
    'sort'     => 609,
    'badge'    => 'beta',
    'keywords' => 'проверка контрагентов мониторинг суды банкротство ЕГРЮЛ риски должники надёжность',
    // раздел управления модуля: чип в шапке (только администратору),
    // как у «Тренды · управление» — страница управления не теряет чипы раздела
    'admin'     => ['title' => 'Вотчдог · управление', 'href' => '/watchdog/admin'],
    'auth'     => FALSE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj609.watchdog' => [
                'label'     => 'Сторож контрагентов (nj609)',
                'url'       => '/watchdog',

                // Сторож контрагентов (nj609): прогон проверки зафиксирован по subject
                // ПОСЛЕ захвата задачи. Событий может и не быть («тишина в источниках»),
                // поэтому признак — сам факт завершённой проверки, а не наличие событий.
                'reconcile' => "SELECT 1 FROM nj_609_subjects s
             WHERE s.id = CAST(j.payload->>'subject_id' AS bigint)
               AND s.last_run_at > COALESCE(j.claimed_at, j.created_at)",
            ],
        ],
    ],

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/nj603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        // Сторож контрагентов (nj609_watchdog): проверка раз в неделю на
        // субъекта
        'watchdog' => [
                'label'   => 'Сторож контрагентов (nj609) — проверка',
                'every'   => '6 hours',
                'enabled' => TRUE,
                'url'     => '/watchdog/api/collect',
                'payload' => [],
        ],
    ],

    // --- Пятый контракт: источник глобального поиска ----------------------
    // Перенесено 28.09.2026 из Service_Nj100_Search: свою таблицу и свой SQL
    // знает владелец. Плейсхолдеры подставляет агрегатор:
    //   :l — образец для ILIKE, :per — лимит на источник,
    //   :scope — «AND user_id = N» для личных источников (иначе пусто),
    //   :qenc — ссылка с url-кодированным запросом (нужна только базе знаний).
    'search' => [
        'subject' => [
            'label' => 'Контрагенты',
            'icon'  => 'fas fa-shield-halved',
            'sort'  => 80,
            'scope' => 'user',
            'sql'   => "SELECT 'subject' AS kind, code, company AS t1, '' AS t2, left(coalesce(inn, note), 110) AS sub,
                        '/watchdog' AS url, created_at
                 FROM nj_609_subjects
                 WHERE company ILIKE :l OR inn ILIKE :l :scope
                 ORDER BY created_at DESC LIMIT :per",
        ],
    ],
];
