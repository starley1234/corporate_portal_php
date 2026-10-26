<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prl120_jobs.
 *
 * Создан 28.09.2026 вместе с пятым контрактом (application/classes/Manifest.php):
 * модуль объявляет свои реестровые записи сам, а не строкой в чужом конфиге.
 * Полей title/href здесь нет намеренно — это не карточка витрины.
 */

return [
    'worker' => ['channels' => [
        'echo' => ['label' => 'Проверка воркера', 'url' => '/tasks'],
        'embeddings.index' => ['label' => 'Эмбеддинги', 'url' => '/tasks'],
    ]],
    // Назначение модуля: отсюда его берёт реестр в docs/ARCHITECTURE.md §2
    // (bin/console module:list --markdown). Без `href` в меню и на /services
    // модуль не показывается.
    'title' => 'Очередь задач',
    'short' => 'асинхронный механизм платформы: задачи для python-воркеров',

    // --- Пятый контракт: расписание пинков --------------------------------
    // Перенесено 28.09.2026 из config/prl603_kicker.php: как часто дёргать
    // собственный эндпоинт, решает владелец, а не планировщик.
    'schedule' => [
        // Самообслуживание очереди — ВКЛЮЧЕНО (раньше стояло в паузе вместе с
        // сервисами). Пока тик выключен, зависшие processing разбирает только
        // заход админа в /prl120/monitor: владелец видел «Выполняется, пульс 18
        // мин» и решал, что воркер сломан. Тик сам работу НЕ запускает — он лишь
        // закрывает мёртвое (Service_Prl120_JobQueue::requeue_stale: reconcile →
        // авто-стоп молчащих → failed по исчерпанным попыткам).
        'maintenance' => [
                'label'   => 'Обслуживание очереди prl120 (requeue stale)',
                'every'   => '10 minutes',
                'enabled' => TRUE,
                'url'     => '/prl120/api/maintain',
                'payload' => [],
        ],
    ],

    // --- Пятый контракт: чистки данных (/prl100/cleanup) ----------------------------
    'cleanup' => [
        'prl120_jobs' => [
            'label'      => 'prl120 · завершённые/ошибочные задачи',
            'note'       => 'Готовые результаты старше 60 дней; учёт токенов (prl_120_usage) остаётся.',
            'days'       => 60,
            'count_sql'  => "SELECT count(*) FROM prl_120_jobs
                                WHERE status IN ('done', 'failed', 'cancelled')
                                  AND finished_at < now() - :old",
            'delete_sql' => "DELETE FROM prl_120_jobs WHERE id IN (
                                SELECT id FROM prl_120_jobs
                                WHERE status IN ('done', 'failed', 'cancelled')
                                  AND finished_at < now() - :old LIMIT :limit)",
        ],
        'prl120_usage' => [
            'label'      => 'prl120 · учёт токенов задач',
            'note'       => 'История расхода моделей (/prl120/usage, /graph3d/usage) старше 400 дней.',
            'days'       => 400,
            'count_sql'  => "SELECT count(*) FROM prl_120_usage WHERE created_at < now() - :old",
            'delete_sql' => "DELETE FROM prl_120_usage WHERE job_id IN (
                                SELECT job_id FROM prl_120_usage
                                WHERE created_at < now() - :old LIMIT :limit)",
        ],
    ],

    // --- Пятый контракт: источник «хвоста ошибок» (/prl100/errors) ----------------
    'health' => [
        'prl_120_jobs' => [
            'label' => 'prl120 · задача очереди',
            'code'  => "id::text",   // SQL-выражение «кода» записи
            'href'  => '/prl120/monitor',
        ],
    ],
];
