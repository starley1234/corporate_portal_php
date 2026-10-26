<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест «Дайджест руководителю» (nj613_digest) для nj110_catalog.
 */

return [
    'id'        => 613,
    'slug'      => 'nj613_digest',
    'title'     => 'Дайджест руководителю',
    'short'     => 'Раз в неделю — короткая человеческая сводка: что нашёл радар, сколько лидов и писем, что произошло во входящих. На страницу и на почту',
    'icon'      => 'fas fa-mug-hot',
    'href'      => '/digest',
    'group'     => 'Платформа',
    // 06.10.2026: из левого меню убран в группу «Платформа» — ссылка на странице
    // «Администрирование» (/admin); в каталоге /services и палитре остаётся.
    'menu'       => FALSE,
    'sort'      => 613,
    'hidden'      => TRUE, // аккаунтная страница: из левого меню убрана
    'badge'     => 'новое',
    'keywords'  => 'дайджест сводка неделя отчет руководитель резюме событий email',
    'api'       => ['/digest/api/tick', '/digest/api/item'],
    'admin'     => ['title' => 'Дайджест · управление', 'href' => '/nj613/admin'],
    'auth'      => TRUE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj613.digest' => [
                'label'     => 'Дайджест недели (nj613)',
                'url'       => '/digest',

                // Дайджест недели (nj613): сводка записана и заполнена (summary) —
                // результат доставлен.
                'reconcile' => "SELECT 1 FROM nj_613_digests d
             WHERE d.user_id = CAST(j.payload->>'user_id' AS bigint)
               AND d.created_at > COALESCE(j.claimed_at, j.created_at)
               AND COALESCE(d.summary, '') <> ''",
            ],
        ],
    ],

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/nj603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        // Дайджест руководителю (nj613_digest): сбор созревших подписок
        'digest' => [
                'label'   => 'Дайджест руководителю (nj613) — тик',
                'every'   => '1 hour',
                'enabled' => TRUE,
                'url'     => '/digest/api/tick',
                'payload' => [],
        ],
    ],
];
