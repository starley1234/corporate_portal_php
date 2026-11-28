<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx103_platform.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов. Страница модуля доступна также в /services.
 */

return [
    'id' => 103,
    'slug' => 'prx103_platform',
    'kind' => 'module',
    'status' => 'stable',
    'acl' => 'prx100.admin',
    'href' => '/prx103_platform',
    'icon' => 'fas fa-gears',
    'group' => 'Платформа',
    'audience' => 'admin',
    'title' => 'Инструменты платформы',
    'short' => 'CLI (`module:list`, `module:scaffold`, `dev:doctor`) и страница самопроверки `/prx100/selfcheck`',

    // Журнал администратора (/prx100/audit): срок хранения — год.
    'cleanup' => [
        'prx_103_audit_log' => [
            'label' => 'prx103 · журнал администратора',
            'note' => 'Старше 365 дней. Записи журнала не правятся, только удаляются по сроку.',
            'days' => 365,
            'count_sql' => 'SELECT count(*) FROM prx_103_audit_log WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_103_audit_log WHERE id IN (
                                SELECT id FROM prx_103_audit_log WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
