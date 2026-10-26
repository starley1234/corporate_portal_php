<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Камеры цеха» (nj640_cameras) для nj110_catalog.
 */

return [
    'id'        => 640,
    'slug'      => 'cameras',
    'title'     => 'Камеры цеха',
    'short'     => 'Камеры в цеху или офисе: трудовая дисциплина, СИЗ и загрузка оборудования — VLM по кадру, журнал событий и уведомления',
    'icon'      => 'fas fa-video',
    'href'      => '/cameras',
    'group'     => 'Контроль качества',
    'sort'      => 640,
    'keywords'  => 'камера видеонаблюдение цех офис дисциплина сиз каска жилет безопасность загрузка оборудования станок простой vlm журнал событий уведомления снимок rtsp onvif',
    'api'       => ['/cameras/api/run', '/cameras/api/camera', '/cameras/api/scene', '/cameras/api/runs'],
    'card_view' => 'nj640/card',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj640.analyze' => [
                'reconcile' => "SELECT 1 FROM nj_640_runs r
             WHERE r.job_id = j.id AND r.status = 'done'",
            ],
        ],
    ],

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/nj603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        // Камеры цеха (nj640): постановка задач тик-камерам видеонаблюдения
        'cameras' => [
                'label'   => 'Камеры цеха (nj640) — тик камер',
                'every'   => '10 minutes',
                'enabled' => TRUE,
                'url'     => '/cameras/api/cron-poll',
                'payload' => [],
        ],
    ],
];
