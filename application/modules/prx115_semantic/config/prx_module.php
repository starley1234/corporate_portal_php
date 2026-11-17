<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест модуля prx115_semantic.
 *
 * Создан 28.09.2026 вместе с пятым контрактом (см. application/classes/Manifest.php):
 * модуль объявляет свои каналы очереди сам, а не строкой в чужом конфиге.
 * Полей title/href здесь нет намеренно — это не карточка витрины, в меню и на
 * /services модуль не показывается.
 */

return [
    // Назначение модуля: отсюда его берёт реестр в docs/ARCHITECTURE.md §2
    // (bin/console module:list --markdown). Без `href` в меню и на /services
    // модуль не показывается.
    'title' => 'Семантический индекс',
    'short' => 'эмбеддинги и семантический поиск (pgvector / real[]-фолбэк)',

    // --- Пятый контракт: каналы очереди ------------------------------------
    // Перенесено 28.09.2026 из config/prx120_jobs.php (reconcile_hooks и
    // channels_ui): канал описывает его ВЛАДЕЛЕЦ, а не ядро очереди.
    'worker' => [
        'channels' => [
            'semantic.index' => [
                'label'     => 'Семантический индекс (поиск)',
                'url'       => '/prx115',

                // Семантический индекс: признак доставки — записанные векторы сущностей
                // ИМЕННО этой задачи (payload: collection, entity, items[].id).
                // Коллекция создаётся ещё ДО постановки (ensure_collection), поэтому её
                // существование ничего не говорит о завершении прогона; а векторы
                // появляются только после реальной (или пропущенной) работы воркера.
                // Живой воркер держит пульс — reconcile его задачи не трогает.
                'reconcile' => "SELECT 1 FROM prx_115_collections c
             WHERE c.name = j.payload->>'collection'
               AND EXISTS (
                 SELECT 1 FROM prx_115_vectors v
                 WHERE v.collection_id = c.id
                   AND v.entity = j.payload->>'entity'
                   AND EXISTS (
                     SELECT 1 FROM jsonb_array_elements(COALESCE(j.payload->'items', '[]'::jsonb)) AS it
                     WHERE v.entity_id = it->>'id'
                   )
               )",
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/prx100/cleanup) ----------------------------
    'cleanup' => [
        'prx115_query_cache' => [
            'label'      => 'prx115 · кэш векторов поисковых запросов',
            'note'       => 'Векторы текстов запросов: модуль чистит сам (30 дней / 500 строк), '
                           .'кнопка — для ручной уборки сразу.',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM prx_115_query_cache WHERE used_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_115_query_cache WHERE qhash IN (
                                SELECT qhash FROM prx_115_query_cache WHERE used_at < now() - :old LIMIT :limit)',
        ],
        'prx115_searches' => [
            'label'      => 'prx115 · журнал поисковых запросов',
            'note'       => 'Что искали и что нашлось: модуль держит только последние '
                           .'searches.keep записей, кнопка — убрать и старые.',
            'days'       => 30,
            'count_sql'  => 'SELECT count(*) FROM prx_115_searches WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_115_searches WHERE id IN (
                                SELECT id FROM prx_115_searches WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
