<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj502_words («Сумма прописью»).
 */

// SEO-короткий URL страницы
Route::set('nj502_words_page', 'summa-propisyu')
    ->defaults([
        'controller' => 'Nj502_Words',
        'action'     => 'index',
    ]);

// JSON API (интеграции)
Route::set('nj502_api', 'nj502/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj502_Api',
        'action'     => 'convert',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
