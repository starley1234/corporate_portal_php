<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx595_useradmin.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов. Страница модуля доступна также в /services.
 */

return [
    'id' => 595,
    'slug' => 'prx595_useradmin',
    'kind' => 'module',
    'status' => 'stable',
    'acl' => 'prx595.users.admin',
    'href' => '/prx595_useradmin',
    'icon' => 'fas fa-users-gear',
    'group' => 'Платформа',
    'audience' => 'admin',
    'title' => 'Администрирование пользователей',
    'short' => 'роли и блокировки поверх ядра users/roles, журнал активности; только для роли admin',
];
