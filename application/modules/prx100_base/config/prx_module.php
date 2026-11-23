<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx100_base.
 *
 * Создан 28.09.2026: реестр модулей в docs/ARCHITECTURE.md §2 теперь
 * генерируется из манифестов, а не пишется руками. Инфраструктурный модуль —
 * без `href`, поэтому в меню и на /services он не показывается.
 */

return [
    'id' => 102,
    'slug' => 'prx100_base',
    'kind' => 'platform',
    'title' => 'База PRX (общий модуль)',
    'short' => 'контроллеры, Auth/RBAC, пользователи, профили',
    'cleanup' => [
        'prx_idempotency_keys' => [
            'label' => 'Идемпотентные ключи API',
            'note' => 'Старше TTL: повтор с таким ключом уже невозможен, результат не выдаётся.',
            'days' => 7,
            'count_sql' => 'SELECT count(*) FROM prx_idempotency_keys WHERE expires_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_idempotency_keys WHERE id IN (
                                SELECT id FROM prx_idempotency_keys WHERE expires_at < now() - :old LIMIT :limit)',
        ],
    ],
];
