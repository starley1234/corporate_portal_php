<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx110_catalog.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов. Страница модуля доступна также в /services.
 */

return [
    'id' => 110,
    'slug' => 'prx110_catalog',
    'kind' => 'module',
    'status' => 'stable',
    'acl' => 'portal.access',
    'href' => '/prx110_catalog',
    'icon' => 'fas fa-grip',
    'group' => 'Платформа',
    'audience' => 'auth',
    'title' => 'Каталог сервисов',
    'short' => 'манифесты `config/prx_module.php` → меню сайдбара, карточки главной, чипы шапки, быстрый поиск',
];
