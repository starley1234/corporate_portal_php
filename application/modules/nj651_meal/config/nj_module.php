<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Калории по фото тарелки» (nj651_meal) для nj110_catalog.
 */

return [
    'id'        => 651,
    'slug'      => 'nj651_meal',
    'title'     => 'Калории по фото тарелки',
    'short'     => 'Сфотографируйте тарелку — сервис назовёт блюда, прикинет вес порций и посчитает калории, белки, жиры и углеводы, а заодно покажет долю от вашей суточной нормы',
    'icon'      => 'fas fa-utensils',
    'href'      => '/meal',
    'card_view' => 'nj651/card',
    'group'     => 'Фото и ИИ',
    'sort'      => 651,
    'badge'     => 'новое',
    'keywords'  => 'калории по фото ккал бжу тарелка еда подсчёт калорий норма калорий белки жиры углеводы питание vlm',
    'api'       => ['/meal/api/photo', '/meal/api/status', '/meal/api/preview'],
    'auth'      => FALSE,

    // --- Пятый контракт: канал очереди -------------------------------------
    'worker' => [
        'channels' => [
            'nj651.meal' => [
                'label'     => 'Калории по фото: разбор тарелки',
                'url'       => '/meal',
                'reconcile' => "SELECT 1 FROM nj_651_runs r\n             WHERE r.id = (j.payload->>'run_id')::bigint\n               AND r.status IN ('done', 'error', 'cancelled')",
            ],
        ],
    ],

    // --- Пятый контракт: чистка данных (/nj100/cleanup) ---------------------
    'cleanup' => [
        'nj_651_runs' => [
            'label'      => 'nj651 · разборы тарелок',
            'note'       => 'Прогоны подсчёта калорий старше 90 дней. Данные о питании — личные, хранить их вечно незачем (сам кадр удаляется сразу после разбора).',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM nj_651_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_651_runs WHERE id IN (
                                SELECT id FROM nj_651_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
