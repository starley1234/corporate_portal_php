<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx103_platform.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов, а не пишется руками. Инфраструктурный модуль —
 * без `href`, поэтому в меню и на /services он не показывается.
 */

return [
    'id' => 103,
    'slug' => 'prx103_platform',
    'kind' => 'platform',
    'title' => 'Инструменты платформы',
    'short' => 'CLI-инструменты: `module:list`, `module:scaffold`, `dev:doctor`',
];
