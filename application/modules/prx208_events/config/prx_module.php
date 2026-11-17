<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx208_events.
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

    // --- Пятый контракт: чистки данных (/prx100/cleanup) ----------------------------
    'cleanup' => [
        'prx208_events' => [
            'label'      => 'prx208 · журнал шины событий',
            'note'       => 'События старше 90 дней (подписки и колокольчик не затрагиваются).',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM prx_208_events WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_208_events WHERE id IN (
                                SELECT id FROM prx_208_events WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
