<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prl208_events.
 *
 * Создан 28.09.2026 вместе с пятым контрактом (application/classes/Manifest.php):
 * модуль объявляет свои реестровые записи сам, а не строкой в чужом конфиге.
 * Полей title/href здесь нет намеренно — это не карточка витрины.
 */

return [
    // Назначение модуля: отсюда его берёт реестр в docs/ARCHITECTURE.md §2
    // (bin/console module:list --markdown). Без `href` в меню и на /services
    // модуль не показывается.
    'title' => 'Шина событий (общий модуль)',
    'short' => 'журнал аудита и мост к уведомлениям (`Event::fire`)',

    // --- Пятый контракт: чистки данных (/prl100/cleanup) ----------------------------
    'cleanup' => [
        'prl208_events' => [
            'label'      => 'prl208 · журнал шины событий',
            'note'       => 'События старше 90 дней (подписки и колокольчик не затрагиваются).',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM prl_208_events WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prl_208_events WHERE id IN (
                                SELECT id FROM prl_208_events WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
