<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Разбор состава» (nj646_label) для nj110_catalog.
 *
 * Группа «Единицы и быт» показывает до 5 пунктов; у сервиса 'pin' => TRUE,
 * чтобы он остался в меню при любом пополнении группы (владелец просил
 * «модуль добавить в меню»).
 */

return [
    'id'       => 646,
    'slug'     => 'nj646_label',
    'title'    => 'Разбор состава',
    'short'    => 'Вставьте состав с этикетки (еда или косметика) или сфотографируйте её — сервис разберёт ингредиенты по своей базе знаний, предупредит о рисках под ваш профиль и запомнит новые вещества',
    'icon'     => 'fas fa-bottle-droplet',
    'href'     => '/label',
    'card_view' => 'nj646/card',
    'group'    => 'Единицы и быт',
    'sort'     => 646,
    'pin'      => TRUE,
    'badge'    => 'новое',
    'keywords' => 'состав этикетка анализ ингредиенты еда косметика инци добавки e добавки аллергия риск вред полезность кожа диета',
    'api'      => [
        '/label/api/analyze', '/label/api/photo', '/label/api/status',
        '/label/api/ingredient', '/label/api/search', '/label/api/profile',
    ],
    'admin'    => ['title' => 'Разборы состава', 'href' => '/nj646/admin'],
    'auth'     => FALSE,

    // --- Пятый контракт: каналы очереди ------------------------------------
    'worker' => [
        'channels' => [
            'nj646.label' => [
                'label'     => 'Разбор состава: фото этикетки',
                'url'       => '/label',
                'reconcile' => "SELECT 1 FROM nj_646_runs r\n             WHERE r.id = (j.payload->>'run_id')::bigint\n               AND r.status IN ('done', 'error', 'cancelled')",
            ],
            'nj646.study' => [
                'label'     => 'Разбор состава: изучение ингредиента',
                'url'       => '/label',
                'reconcile' => "SELECT 1 FROM nj_646_runs r\n             WHERE r.id = (j.payload->>'run_id')::bigint\n               AND r.status IN ('done', 'error', 'cancelled')",
            ],
        ],
    ],

    // --- Пятый контракт: чистки данных (/nj100/cleanup) ---------------------
    'cleanup' => [
        'nj_646_runs' => [
            'label'      => 'nj646 · прогоны распознавания',
            'note'       => 'Прогоны фото этикеток и ИИ-изучения старше 90 дней.',
            'days'       => 90,
            'count_sql'  => 'SELECT count(*) FROM nj_646_runs WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_646_runs WHERE id IN (
                                SELECT id FROM nj_646_runs WHERE created_at < now() - :old LIMIT :limit)',
        ],
        'nj_646_profiles' => [
            'label'      => 'nj646 · профили посетителей',
            'note'       => 'Профиль (кожа, диета, аллергии) — медицински чувствительные данные, привязанные к метке браузера. Год без обращений — профиль удаляем; вернуть его нечем, но и хранить вечно незачем.',
            'days'       => 365,
            'count_sql'  => 'SELECT count(*) FROM nj_646_profiles WHERE updated_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_646_profiles WHERE visitor IN (
                                SELECT visitor FROM nj_646_profiles WHERE updated_at < now() - :old LIMIT :limit)',
        ],
        'nj_646_analyses' => [
            'label'      => 'nj646 · история разборов',
            'note'       => 'Анонимная история разборов старше 180 дней (статистика веществ остаётся в карточках).',
            'days'       => 180,
            'count_sql'  => 'SELECT count(*) FROM nj_646_analyses WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM nj_646_analyses WHERE id IN (
                                SELECT id FROM nj_646_analyses WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
