<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Карта отрасли» (nj607_industry) для nj110_catalog.
 */

return [
    'id'       => 607,
    'slug'     => 'nj607_industry',
    'title'    => 'Карта отрасли',
    'short'    => 'Кто производит в отрасли, что и как: игроки с сайтами, сегменты, цены, каналы, возможности входа. Авто-отчёт за минуты или ручная разведка под запрос',
    'icon'     => 'fas fa-map',
    'href'     => '/industry',
    'card_view' => 'nj607/card',
    'group'    => 'Продажи',
    'sort'     => 607,
    'badge'    => 'новое',
    'keywords' => 'анализ рынка производители отрасль конкуренты игроки разведка исследование карта отрасли поставщики',
    // раздел управления модуля: чип в шапке (только администратору),
    // как у «Тренды · управление» — страница управления не теряет чипы раздела
    'admin'     => ['title' => 'Карта отрасли · управление', 'href' => '/nj607/admin'],
    'auth'     => FALSE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj607.industry' => [
                'label'     => 'Карта отрасли (nj607)',
                'url'       => '/industry',

                // Карта отрасли (nj607): отчёт доведён до status='done' и finished_at.
                'reconcile' => "SELECT 1 FROM nj_607_reports r
             WHERE r.code = j.payload->>'code'
               AND r.status = 'done'
               AND r.finished_at IS NOT NULL",
            ],
        ],
    ],
];
