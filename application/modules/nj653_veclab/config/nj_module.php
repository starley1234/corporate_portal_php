<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Лаборатория векторов» (nj653_veclab) для nj110_catalog.
 *
 * Группа «Разработчику» режется до нескольких пунктов, поэтому 'pin' => TRUE:
 * иначе новый сервис уехал бы в «Ещё N …» и владелец его не увидел бы.
 */

return [
    'id'        => 653,
    'slug'      => 'nj653_veclab',
    'title'     => 'Лаборатория векторов',
    'short'     => 'Мультимодальная модель эмбеддингов (векторов смысла): текст и картинки попадают в одно пространство. Проверка модели, своя коллекция, поиск картинки по описанию и близость пары',
    'icon'      => 'fas fa-vector-square',
    'href'      => '/veclab',
    'card_view' => 'nj653/card',
    'group'     => 'Разработчику',
    'sort'      => 653,
    'pin'       => TRUE,
    'badge'     => 'новое',
    'keywords'  => 'эмбеддинги векторы мультимодальная модель поиск по картинке косинусная близость semantic search qwen3-vl-embedding pgvector кросс-модальный поиск',
    'api'       => [
        '/veclab/api/probe', '/veclab/api/add', '/veclab/api/search',
        '/veclab/api/compare', '/veclab/api/status', '/veclab/api/items',
        '/veclab/api/about',
    ],
    'mcp'       => [
        'nj653_status', 'nj653_collection', 'nj653_remember',
        'nj653_search', 'nj653_compare', 'nj653_result',
    ],
    'admin'     => ['title' => 'Лаборатория векторов · коллекция', 'href' => '/veclab/admin'],
    'auth'      => FALSE,

    // --- Пятый контракт: канал очереди -------------------------------------
    'worker' => [
        'channels' => [
            'nj653.embed' => [
                'label'     => 'Лаборатория векторов: мультимодальный эмбеддинг',
                'url'       => '/veclab',
                'reconcile' => "SELECT 1 FROM nj_653_runs r\n             WHERE r.id = (j.payload->>'run_id')::bigint\n               AND r.status IN ('done', 'error', 'cancelled')",
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ---------------------
    'cleanup' => [
        'nj_653_runs' => [
            'label'      => 'nj653 · прогоны лаборатории векторов',
            'note'       => 'Проверки модели, поиски и сравнения старше 30 дней (сами векторы коллекции живут отдельно).',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM nj_653_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_653_runs WHERE id IN (
                                SELECT id FROM nj_653_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_653_items' => [
            'label'      => 'nj653 · коллекция векторов',
            'note'       => 'Демонстрационные элементы коллекции старше 30 дней: это витрина, а не хранилище.',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM nj_653_items WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_653_items WHERE id IN (
                                SELECT id FROM nj_653_items WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
