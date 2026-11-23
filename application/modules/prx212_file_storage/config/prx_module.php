<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx212_file_storage.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов, а не пишется руками. Инфраструктурный модуль —
 * без `href`, поэтому в меню и на /services он не показывается.
 */

return [
    'id' => 212,
    'slug' => 'prx212_file_storage',
    'kind' => 'platform',
    'title' => 'Файловое хранилище (общий модуль)',
    'short' => 'локальный и S3 бэкенды',
];
