<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj614_json («JSON-инструмент»).
 */

// --- Роуты -------------------------------------------------------------------
Route::set('nj614_page', 'json')
    ->defaults([
        'controller' => 'Nj614_Json',
        'action'     => 'index',
    ]);

// JSON API (интеграции, агенты)
Route::set('nj614_api', 'nj614/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj614_Api',
        'action'     => 'format',
    ]);

// --- Зависимости --------------------------------------------------------------
// Нет: модуль stateless (без таблиц и очередей).
