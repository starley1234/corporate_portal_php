<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Чек по фото» (nj650_receipt) для nj110_catalog.
 */

return [
    'id'        => 650,
    'slug'      => 'nj650_receipt',
    'title'     => 'Чек по фото',
    'short'     => 'Сфотографируйте чек — сервис прочитает позиции, сверит сумму с итогом, покажет траты по категориям и поделит счёт между участниками (с чаевыми и без потерянных копеек)',
    'icon'      => 'fas fa-receipt',
    'href'      => '/receipt',
    'card_view' => 'nj650/card',
    'group'     => 'Фото и ИИ',
    'sort'      => 650,
    'badge'     => 'новое',
    'keywords'  => 'чек фото распознать счёт разделить поделить ресторан траты категории расходы сумма позиции ocr vlm',
    'api'       => ['/receipt/api/photo', '/receipt/api/status'],
    'auth'      => FALSE,

    // --- Пятый контракт: канал очереди -------------------------------------
    'worker' => [
        'channels' => [
            'nj650.receipt' => [
                'label'     => 'Чек по фото: распознавание',
                'url'       => '/receipt',
                'reconcile' => "SELECT 1 FROM nj_650_runs r\n             WHERE r.id = (j.payload->>'run_id')::bigint\n               AND r.status IN ('done', 'error', 'cancelled')",
            ],
        ],
    ],

    // --- Пятый контракт: чистка данных (/nj100/cleanup) ---------------------
    'cleanup' => [
        'nj_650_runs' => [
            'label'      => 'nj650 · разборы чеков',
            'note'       => 'Прогоны распознавания чеков старше 90 дней. На фото — покупки человека, хранить их вечно незачем (сам кадр удаляется сразу после разбора).',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM nj_650_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_650_runs WHERE id IN (
                                SELECT id FROM nj_650_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
