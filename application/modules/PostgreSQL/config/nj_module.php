<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля PostgreSQL.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов, а не пишется руками. Инфраструктурный модуль —
 * без `href`, поэтому в меню и на /services он не показывается.
 */

return [
    'title' => 'Драйвер PostgreSQL',
    'short' => 'нативный драйвер `pg_*` для Kohana Database',
];
