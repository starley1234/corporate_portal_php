<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Init file for Module Manager (Registry)
 * Module ID: 218
 * Prefix: prl218_
 */

/**
 * 1. Регистрация роутов модуля
 * Используется ID модуля (218) в именах роутов для предотвращения конфликтов.
 */

// Роут для API методов (JSON ответы)
Route::set('prl218_module_manager_api', 'prl218/api(/<action>(/<id>))')
    ->defaults(array(
        'directory'  => 'Prl218',
        'controller' => 'Api',
        'action'     => 'index',
    ));

// Роут для сканера и LLM анализа
Route::set('prl218_module_manager_scanner', 'prl218/scanner(/<action>(/<id>))')
    ->defaults(array(
        'directory'  => 'Prl218',
        'controller' => 'Scanner',
        'action'     => 'index',
    ));

// Основной роут для интерфейса (Index)
Route::set('prl218_module_manager_admin', 'prl218(/<controller>(/<action>(/<id>)))')
    ->defaults(array(
        'directory'  => 'Prl218',
        'controller' => 'Index',
        'action'     => 'index',
    ));

/**
 * 2. Регистрация событий (Events)
 * Модуль подписывается на системные события и объявляет свои.
 */

// Пример: Логирование изменений статуса модуля в системный аудит
Event::listen('prl218.module.status_changed', function($data) {
    // $data содержит module_id, old_status, new_status
    if (class_exists('Audit')) {
        Audit::log('module_manager', 'status_change', $data['module_id'], [
            'from' => $data['old_status'],
            'to'   => $data['new_status']
        ]);
    }
});

// Пример: Очистка кэша реестра при регистрации нового модуля
Event::listen('prl218.module.registered', function($module_obj) {
    Kohana_Cache::instance()->delete('prl218_modules_list');
});

/**
 * 3. Дополнительные настройки
 * Можно добавить проверку на существование необходимых таблиц в режиме Development
 */
if (Kohana::$environment === Kohana::DEVELOPMENT) {
    // Логика проверки миграций или структуры БД при необходимости
}