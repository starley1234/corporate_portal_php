<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj501_vat (Калькулятор НДС).
 */

// --- Роуты -------------------------------------------------------------------
// SEO-короткий URL страницы
Route::set('nj501_vat_page', 'vat')
    ->defaults([
        'controller' => 'Nj501_Vat',
        'action'     => 'index',
    ]);

// JSON API (интеграции)
Route::set('nj501_api', 'nj501/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj501_Api',
        'action'     => 'calc',
    ]);

// --- Зависимости --------------------------------------------------------------
// Нет: модуль stateless (без таблиц и очередей).
