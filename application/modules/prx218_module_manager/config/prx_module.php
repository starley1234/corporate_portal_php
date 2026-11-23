<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx218_module_manager.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов, а не пишется руками. Инфраструктурный модуль —
 * без `href`, поэтому в меню и на /services он не показывается.
 */

return [
    'id' => 218,
    'slug' => 'prx218_module_manager',
    'kind' => 'platform',
    'title' => 'Менеджер модулей (общий модуль)',
    'short' => 'реестр модулей, сканер, зависимости, LLM-анализ, scaffold',
];
