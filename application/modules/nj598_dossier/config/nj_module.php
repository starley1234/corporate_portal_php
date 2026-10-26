<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «AI Досье на работодателя» (nj598_dossier) для nj110_catalog.
 */

return [
    'id'        => 598,
    'slug'      => 'nj598_dossier',
    'title'     => 'AI Досье на работодателя',
    'short'     => 'Разведка компании перед откликом: отзывы сотрудников, реальный стек, красные флаги, шпаргалка к собеседованию',
    'icon'      => 'fas fa-user-secret',
    'href'      => '/dossier',
    'group'     => 'Карьера',
    'sort'      => 551,
    'admin'     => ['title' => 'Досье · управление', 'href' => '/nj598/admin'],
    'keywords'  => 'работодатель компания досье разведка отзывы сотрудников стек собеседование скрининг красные флаги суды сокращения инвестор карьера вакансия',
    'api'       => ['/dossier/api/status/<code>'],
    'auth'      => FALSE, // как вакансии: доступно и анонам (кука ubid)

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj598.dossier' => [
                'label'     => 'AI Досье на работодателя',
                'url'       => '/dossier/<code>',

                // Досье (nj598): save_result ставит dossier.status='done'; job_id
                // пишется при постановке. Иначе «досье готово, а в мониторе Ошибка» —
                // типовая жалоба, когда свипер отказал задачу за секунды до доставки.
                'reconcile' => "SELECT 1 FROM nj_598_dossiers d
             WHERE d.job_id = j.id AND d.status = 'done'",
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ----------------------------
    'cleanup' => [
        'nj598_dossiers' => [
            'label'      => 'nj598 · старые досье (done/error)',
            'note'       => 'ОСТОРОЖНО: артефакты пользователей. Порог 180 дней.',
            'days'       => 180,
            'count_sql'  => "SELECT count(*) FROM nj_598_dossiers
                                WHERE status IN ('done', 'error') AND finished_at < now() - :old",
            'delete_sql' => "DELETE FROM nj_598_dossiers WHERE id IN (
                                SELECT id FROM nj_598_dossiers
                                WHERE status IN ('done', 'error') AND finished_at < now() - :old LIMIT :limit)",
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/nj100/errors) ----------------
    'health' => [
        'nj_598_dossiers' => [
            'label' => 'nj598 · досье',
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
        'dossier' => [
            'label' => 'AI-досье',
            'icon'  => 'fas fa-user-secret',
            'sort'  => 70,
            'scope' => 'user',
            'sql'   => "SELECT 'dossier' AS kind, code, company AS t1, query AS t2, '' AS sub,
                        '/dossier/' || code AS url, created_at
                 FROM nj_598_dossiers
                 WHERE company ILIKE :l OR query ILIKE :l :scope
                 ORDER BY created_at DESC LIMIT :per",
        ],
    ],
];
