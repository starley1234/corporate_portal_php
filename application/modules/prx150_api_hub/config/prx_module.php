<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест prx150_api_hub — ключи и права внешнего API.
 *
 * Модуль сам не отдаёт бизнес-данных: он проверяет ключ, scope и лимит,
 * пишет использование и показывает их администратору. Сервисы объявляют
 * свои scope в `api_scopes` своего манифеста — центрального списка нет.
 */
return [
    'id' => 150,
    'slug' => 'prx150_api_hub',
    'kind' => 'module',
    'title' => 'API-доступ',
    'short' => 'Ключи внешних потребителей, права на сервисы и журнал использования',
    'icon' => 'fas fa-key',
    'href' => '/prx150_api_hub',
    'group' => 'Платформа',
    'sort' => 150,
    'status' => 'beta',
    'audience' => 'admin',
    'acl' => 'prx150.admin',
    'created_at' => '2026-11-23',
    'api' => [],
    'cleanup' => [
        'prx_150_api_usage' => [
            'label' => 'prx150 · журнал API-запросов',
            'note' => 'Старше 90 дней; ключи и права не затрагиваются.',
            'days' => 90,
            'count_sql' => 'SELECT count(*) FROM prx_150_api_usage WHERE created_at < now() - :old',
            'delete_sql' => 'DELETE FROM prx_150_api_usage WHERE id IN (
                                SELECT id FROM prx_150_api_usage WHERE created_at < now() - :old LIMIT :limit)',
        ],
    ],
];
