<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx218_module_manager.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов. Страница модуля доступна также в /services.
 */

return [
    'id' => 218,
    'slug' => 'prx218_module_manager',
    'kind' => 'module',
    'status' => 'stable',
    'acl' => 'module_manager.view',
    'href' => '/prx218_module_manager',
    'nav_paths' => ['/prx218'],
    'icon' => 'fas fa-cubes',
    'group' => 'Платформа',
    'audience' => 'admin',
    'title' => 'Менеджер модулей (общий модуль)',
    'short' => 'реестр модулей, сканер, зависимости, LLM-анализ, scaffold',
];
