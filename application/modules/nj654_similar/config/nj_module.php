<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Похожие картинки» (nj654_similar) для nj110_catalog.
 */

return [
    'id'        => 654,
    'slug'      => 'nj654_similar',
    'title'     => 'Похожие картинки',
    'short'     => 'Свой банк изображений с поиском по смыслу: опишите словами или покажите картинку — найдётся самое похожее. Массовая загрузка, уменьшение файлов на входе',
    'icon'      => 'fas fa-images',
    'href'      => '/similar',
    'card_view' => 'nj654/card',
    'group'     => 'Разработчику',
    'sort'      => 654,
    'pin'       => TRUE,
    'badge'     => 'новое',
    'keywords'  => 'поиск похожих изображений по картинке по описанию банк изображений эмбеддинги мультимодальный поиск дубликаты фотографий',
    'api'       => ['/similar/api/status'],
    // Чипы раздела собирает module_nav самого модуля (клик — «Похожие
    // картинки», «Журнал», «Банк картинок»). Свои чипы каталога модуль
    // отключает: рядом с «Похожие картинки» вставало «Похожие картинки ·
    // журнал» — тот же раздел двумя разными подписями, плюс разделитель «|».
    'admin'     => ['title' => 'Журнал', 'icon' => 'fas fa-clock-rotate-left', 'href' => '/similar/admin'],
    'header_chips' => 'own',
    'auth'      => FALSE,

    // --- Пятый контракт: канал очереди -------------------------------------
    'worker' => [
        'channels' => [
            'nj654.embed' => [
                'label'     => 'Похожие картинки: вектор элемента или запроса',
                'url'       => '/similar',
                // Задача закрыта, когда у всех её элементов есть вектор либо
                // отказ: сверять нечего, если строки уже не ждут.
                'reconcile' => "SELECT 1 FROM nj_654_items i
             WHERE i.id = (j.payload->'items'->0->>'id')::bigint
               AND i.status IN ('ready', 'error')",
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ---------------------
    'cleanup' => [
        'nj_654_queries' => [
            'label'      => 'nj654 · журнал поисков',
            'note'       => 'Что искали в банке картинок, старше 90 дней.',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM nj_654_queries WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_654_queries WHERE id IN (
                                SELECT id FROM nj_654_queries WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],

    // Секцию 'health' не объявляем намеренно: «хвост ошибок» читает
    // COALESCE(finished_at, updated_at, created_at), а у банка таких колонок
    // нет. Несчитанные элементы видны в своей админке отдельным блоком.
];
