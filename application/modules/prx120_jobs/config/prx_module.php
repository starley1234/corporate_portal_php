<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx120_jobs.
 *
 * Создан 28.09.2026 вместе с пятым контрактом (application/classes/Manifest.php):
 * модуль объявляет свои реестровые записи сам, а не строкой в чужом конфиге.
 * Страница управления опубликована в каталоге с ограничением по аудитории.
 */

return [
    'id' => 120,
    'slug' => 'prx120_jobs',
    'kind' => 'module',
    'status' => 'stable',
    'acl' => 'prx120.monitor',
    'href' => '/prx120_jobs',
    'icon' => 'fas fa-list-check',
    'group' => 'Платформа',
    'audience' => 'admin',
    'worker' => ['channels' => [
        'echo' => ['label' => 'Проверка воркера', 'url' => '/tasks'],
        'embeddings.index' => ['label' => 'Эмбеддинги', 'url' => '/tasks'],
    ]],
    // Назначение модуля: отсюда его берёт реестр в docs/ARCHITECTURE.md §2
    // (bin/console module:list --markdown).
    'title' => 'Очередь задач',
    'short' => 'асинхронный механизм платформы: задачи для python-воркеров',

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/prx603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        // Самообслуживание очереди — ВКЛЮЧЕНО (раньше стояло в паузе вместе с
        // сервисами). Пока тик выключен, зависшие processing разбирает только
        // заход админа в /prx120/monitor: владелец видел «Выполняется, пульс 18
        // мин» и решал, что воркер сломан. Тик сам работу НЕ запускает — он лишь
        // закрывает мёртвое (Service_Prx120_JobQueue::requeue_stale: reconcile →
        // авто-стоп молчащих → failed по исчерпанным попыткам).
        'maintenance' => [
                'label'   => 'Обслуживание очереди prx120 (requeue stale)',
                'every'   => '10 minutes',
                'enabled' => TRUE,
                'url'     => '/prx120/api/maintain',
                'payload' => [],
        ],
    ],

    // --- Пятый контракт: чистки данных (/prx100/cleanup) ----------------------------
    'cleanup' => [
        'prx120_jobs' => [
            'label'      => 'prx120 · завершённые/ошибочные задачи',
            'note'       => 'Готовые результаты старше 60 дней; учёт токенов (prx_120_usage) остаётся.',
            'days'       => 60,
            'count_sql'  => "SELECT count(*) FROM prx_120_jobs
                                WHERE status IN ('done', 'failed', 'cancelled')
                                  AND finished_at < now() - :old",
            'delete_sql' => "DELETE FROM prx_120_jobs WHERE id IN (
                                SELECT id FROM prx_120_jobs
                                WHERE status IN ('done', 'failed', 'cancelled')
                                  AND finished_at < now() - :old LIMIT :limit)",
        ],
        'prx120_usage' => [
            'label'      => 'prx120 · учёт токенов задач',
            'note'       => 'История расхода моделей (/prx120/usage, /graph3d/usage) старше 400 дней.',
            'days'       => 400,
            'count_sql'  => "SELECT count(*) FROM prx_120_usage WHERE created_at < now() - :old",
            'delete_sql' => "DELETE FROM prx_120_usage WHERE job_id IN (
                                SELECT job_id FROM prx_120_usage
                                WHERE created_at < now() - :old LIMIT :limit)",
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/prx100/errors) ----------------
    'health' => [
        'prx_120_jobs' => [
            'label' => 'prx120 · задача очереди',
            'code'  => "id::text",   // SQL-выражение «кода» записи
            'href'  => '/prx120_jobs',
        ],
    ],
];
