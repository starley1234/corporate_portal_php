<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Init file for Module Manager (Registry)
 * Module ID: 218
 * Prefix: prx218_
 */

/**
 * 1. Регистрация роутов модуля
 * Используется ID модуля (218) в именах роутов для предотвращения конфликтов.
 */

// Роут для API методов (JSON ответы)
Route::set('prx218_module_manager_api', 'prx218/api(/<action>(/<id>))')
    ->defaults(array(
        'directory'  => 'Prx218',
        'controller' => 'Api',
        'action'     => 'index',
    ));

// Роут для сканера и LLM анализа
Route::set('prx218_module_manager_scanner', 'prx218/scanner(/<action>(/<id>))')
    ->defaults(array(
        'directory'  => 'Prx218',
        'controller' => 'Scanner',
        'action'     => 'index',
    ));

// Основной роут для интерфейса (Index)
Route::set('prx218_module_manager_admin', 'prx218(/<controller>(/<action>(/<id>)))')
    ->defaults(array(
        'directory'  => 'Prx218',
        'controller' => 'Index',
        'action'     => 'index',
    ));

/**
 * 2. Регистрация событий (Events)
 * Модуль подписывается на системные события и объявляет свои.
 */

// Смена статуса модуля любым путём (UI, API) — в аудит. Payload — ID и скаляры:
// подписчик при необходимости перечитывает модуль через сервис.
Event::listen('prx218.module.status_changed', function ($payload, $event_name, $initiator_id) {
    if (class_exists('Audit')) {
        Audit::log('module_manager', 'status_change', $payload['module_id'], [
            'from' => $payload['old_status'],
            'to'   => $payload['new_status'],
        ]);
    }
});

// Сканер нашёл новый модуль — сбрасываем кэш списка реестра.
Event::listen('prx218.scanner.module_discovered', function ($payload, $event_name, $initiator_id) {
    Kohana_Cache::instance()->delete('prx218_modules_list');
});

/**
 * 3. Дополнительные настройки
 * Можно добавить проверку на существование необходимых таблиц в режиме Development
 */
if (Kohana::$environment === Kohana::DEVELOPMENT) {
    // Логика проверки миграций или структуры БД при необходимости
}