<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Растение по фото» (nj652_plant) для nj110_catalog.
 */

return [
    'id'        => 652,
    'slug'      => 'nj652_plant',
    'title'     => 'Растение по фото',
    'short'     => 'Сфотографируйте растение — сервис определит вид, скажет, что с ним не так (пятна, вредители, перелив), и составит план ухода с календарём полива и подкормок',
    'icon'      => 'fas fa-seedling',
    'href'      => '/plant',
    'card_view' => 'nj652/card',
    'group'     => 'Фото и ИИ',
    'sort'      => 652,
    'badge'     => 'новое',
    'keywords'  => 'растение по фото определить цветок болезни листьев уход полив подкормка вредители комнатные растения vlm',
    'api'       => ['/plant/api/photo', '/plant/api/status'],
    'auth'      => FALSE,

    // --- Пятый контракт: канал очереди -------------------------------------
    'worker' => [
        'channels' => [
            'nj652.plant' => [
                'label'     => 'Растение по фото: определение и уход',
                'url'       => '/plant',
                'reconcile' => "SELECT 1 FROM nj_652_runs r\n             WHERE r.id = (j.payload->>'run_id')::bigint\n               AND r.status IN ('done', 'error', 'cancelled')",
            ],
        ],
    ],

    // --- Пятый контракт: чистка данных (/nj100/cleanup) ---------------------
    'cleanup' => [
        'nj_652_runs' => [
            'label'      => 'nj652 · разборы растений',
            'note'       => 'Прогоны определения растений старше 90 дней (сам кадр удаляется сразу после разбора).',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM nj_652_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_652_runs WHERE id IN (
                                SELECT id FROM nj_652_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
