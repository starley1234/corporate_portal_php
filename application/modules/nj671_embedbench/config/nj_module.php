<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест «Стенда эмбеддингов» (nj671_embedbench) для nj110_catalog.
 *
 * Аудитория — admin: это диагностический инструмент владельца, а не сервис для
 * посетителей. Прогон занимает очередь и время эмбеддинг-сервера, а вывод
 * содержит адреса внутренних сервисов — показывать это гостю незачем.
 */
return [
    'id'        => 671,
    'slug'      => 'nj671_embedbench',
    'title'     => 'Стенд эмбеддингов',
    'short'     => 'Сравнение двух путей получить вектор на одном материале: текстовый EMBEDDING_API_URL и мультимодальный EMBEDDING_MM_API_URL — качество поиска, цена входа, повторяемость, картинка↔слова и готовый вывод, что брать для какой задачи',
    'icon'      => 'fas fa-scale-balanced',
    'href'      => '/embedbench',
    'group'     => 'Разработчику',
    'sort'      => 671,
    'created_at' => '2026-10-22',
    'updated_at' => '2026-10-22',
    'audience'  => 'admin',
    'status'    => 'stable',
    'keywords'  => 'эмбеддинги сравнение бенчмарк recall косинус мультимодальный текст embedding api qwen veclab стенд',

    // --- Пятый контракт: канал очереди -------------------------------------
    // Задача закрыта, когда прогон получил вердикт: либо воркер записал
    // результат, либо прогон честно упал. Иначе монитор отправил бы задачу в
    // ретрай, хотя отчёт уже лежит на сайте.
    'worker'    => [
        'channels' => [
            'nj671.bench' => [
                'label'     => 'Стенд эмбеддингов · сравнение двух путей (nj671)',
                'url'       => '/embedbench',
                'reconcile' => "SELECT 1 FROM nj_671_runs r
             WHERE r.id = (j.payload->>'run_id')::bigint
               AND r.status IN ('done', 'error', 'cancelled')",
            ],
        ],
    ],

    // --- Пятый контракт: чистка данных (/nj100/cleanup) ---------------------
    'cleanup'   => [
        'nj_671_runs' => [
            'label'      => 'nj671 · прогоны стенда эмбеддингов',
            'note'       => 'Отчёты старше 180 дней: сравнивать полугодовалые цифры с текущими моделями смысла нет.',
            'days'       => 180,
            'count_sql'  => 'SELECT count(*) FROM nj_671_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_671_runs WHERE id IN (
                                SELECT id FROM nj_671_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
