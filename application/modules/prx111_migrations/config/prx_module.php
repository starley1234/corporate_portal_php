<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx111_migrations.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов. Страница модуля доступна также в /services.
 */

return [
    'id' => 111,
    'slug' => 'prx111_migrations',
    'kind' => 'module',
    'status' => 'stable',
    'acl' => 'prx111.view',
    'href' => '/prx111_migrations',
    'icon' => 'fas fa-database',
    'group' => 'Платформа',
    'audience' => 'admin',
    'title' => 'Миграции PostgreSQL',
    'short' => 'система миграций (`db:migrate`, веб-консоль `/migrate_pg`, таблица `migrations_pg`)',
];
