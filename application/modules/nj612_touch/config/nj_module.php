<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест «Серии касаний» (nj612_touch) для nj110_catalog.
 */

return [
    'id'        => 612,
    'slug'      => 'nj612_touch',
    'title'     => 'Серии касаний',
    'short'     => 'Первое письмо и вежливые follow-up по компании: черновики пишет ИИ, отправка по кнопке, ответ контакта останавливает серию сам',
    'icon'      => 'fas fa-hand-point-right',
    'href'      => '/touches',
    'card_view' => '612/card',
    'group'     => 'Продажи',
    'sort'      => 612,
    'badge'     => 'новое',
    'keywords'  => 'письма follow-up касания напоминания холодные продажи серия автопоследовательность b2b',
    'api'       => ['/touches/api/step', '/touches/api/tick'],
    'admin'     => ['title' => 'Касания · управление', 'href' => '/nj612/admin'],
    'auth'      => TRUE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj612.touch' => [
                'label'     => 'Серия касаний (nj612)',
                'url'       => '/touches',

                // Серия касаний (nj612): шаг письма переведён из 'drafting' в черновик/
                // отправку по sequence.code из payload — результат принят сайтом.
                'reconcile' => "SELECT 1 FROM nj_612_steps s
             JOIN nj_612_sequences q ON q.id = s.sequence_id
             WHERE q.code = j.payload->>'code'
               AND s.seq = CAST(j.payload->>'seq' AS integer)
               AND s.status <> 'drafting'",
            ],
        ],
    ],

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/nj603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        // Серии касаний (nj612_touch): автоотправка созревших шагов + детект
        // ответов
        'touches' => [
                'label'   => 'Серии касаний (nj612) — тик',
                'every'   => '30 minutes',
                'enabled' => TRUE,
                'url'     => '/touches/api/tick',
                'payload' => [],
        ],
    ],
];
