<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест «Аккумулятор предпочтений» (nj600_prefs) для nj110_catalog.
 * hidden: модуль пассивный (фон), пользователю отдельная страница не нужна;
 * управление — через admin-чип (/nj600/admin).
 */

return [
    'id'        => 600,
    'slug'      => 'nj600_prefs',
    'title'     => 'Аккумулятор предпочтений',
    'short'     => 'Платформа запоминает контекст: разделы, фразы, источники — чтобы сервисы становились проактивными',
    'icon'      => 'fas fa-brain',
    'href'      => '/nj600/admin',
    'group'     => 'Платформа',
    // 06.10.2026: из левого меню убран в группу «Платформа» — ссылка на странице
    // «Администрирование» (/admin); в каталоге /services и палитре остаётся.
    'menu'       => FALSE,
    'sort'      => 600,
    'hidden'    => TRUE,
    'admin'     => ['title' => 'Предпочтения · управление', 'href' => '/nj600/admin'],
    'keywords'  => 'предпочтения контекст намерения персонализация рекомендации аналитика',
    'api'       => ['/prefs/api/profile', '/prefs/api/recommend', '/nj600/admin', '/nj600/admin/reco'],
    'auth'      => FALSE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/nj120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'nj600.compact' => [
                'label'     => 'Сжатие профилей (nj600)',
                'url'       => '/nj600/admin',
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ----------------------------
    'cleanup' => [
        'nj600_events' => [
            'label'      => 'nj600 · сырые события предпочтений',
            'note'       => 'Суммаризация уже в nj_600_profile — сырьё можно удалять смело.',
            'days'       => 21,
            'count_sql'  => 'SELECT count(*) FROM nj_600_events WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_600_events WHERE id IN (
                                SELECT id FROM nj_600_events WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj600_reco_log' => [
            'label'      => 'nj600 · журнал показов рекомендаций',
            'note'       => 'Показы/клики/отказы: CTR считается за 30 дней, отказы помнятся 60 — старше можно удалять.',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM nj_600_reco_log WHERE shown_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_600_reco_log WHERE id IN (
                                SELECT id FROM nj_600_reco_log WHERE shown_at < now() - :old LIMIT :limit)',
        ],
    ],
];
