<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Контроль качества» (nj639_qc) для nj110_catalog.
 */

return [
    'id'        => 639,
    'slug'      => 'qc',
    'title'     => 'Контроль качества',
    'short'     => 'Эталон, правило и фото изделия — VLM сверит кадр с эталоном и вернёт вердикт по вашей JSON-схеме',
    'icon'      => 'fas fa-clipboard-check',
    'href'      => '/qc',
    'group'     => 'Контроль качества',
    'sort'      => 639,
    'keywords'  => 'контроль качества брак дефект изделие эталон vlm камера rtsp onvif ftp kafka rabbitmq mqtt json схема верификация снимок',
    'api'       => ['/qc/api/run', '/qc/api/result', '/qc/api/source', '/qc/api/worker_result'],
    'card_view' => 'nj639/card',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj639.analyze' => [

                // Контроль качества (nj639/nj640) и генератор изображений (nj641):
                // прогон закрыт в 'done' — результат записан в БД сайта приёмкой. ВАЖНО:
                // 'error' доставкой НЕ считается (его ставит и «зависла»-проверка).
                'reconcile' => "SELECT 1 FROM nj_639_runs r
             WHERE r.job_id = j.id AND r.status = 'done'",
            ],
        ],
    ],

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/nj603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        // Контроль качества (nj639): постановка задач тик-источникам камер/FTP
        'qc' => [
                'label'   => 'Контроль качества (nj639) — тик источников',
                'every'   => '30 minutes',
                'enabled' => TRUE,
                'url'     => '/qc/api/cron-poll',
                'payload' => [],
        ],
    ],
];
