<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj530_jump (сокращатель ссылок «Need 2 Jump»).
 */

// Форма + создание + статистика
Route::set('nj530_jump_page', 'jump')
    ->defaults([
        'controller' => 'Nj530_Jump',
        'action'     => 'index',
    ]);

// Страница ссылки с QR-кодом (важно: ВЫШЕ редиректа x/<code>)
Route::set('nj530_qr', 'x/<code>/qr', ['code' => '[0-9a-zA-Z]{1,10}'])
    ->defaults([
        'controller' => 'Nj530_Jump',
        'action'     => 'qr',
    ]);

// Короткая ссылка → редирект
Route::set('nj530_go', 'x/<code>', ['code' => '[0-9a-zA-Z]{1,10}'])
    ->defaults([
        'controller' => 'Nj530_Go',
        'action'     => 'index',
    ]);

// JSON API
Route::set('nj530_api', 'nj530/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj530_Api',
        'action'     => 'shorten',
    ]);

// Таблица: nj_530_links (миграция 001).

// Часть управления: ссылки, переходы, идентификаторы
Route::set('nj530_admin', 'nj530/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj530_Admin',
        'action'     => 'index',
    ]);
