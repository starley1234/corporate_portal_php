<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj618_hash («Хеш-инструмент»).
 */

Route::set('nj618_page', 'hash')
    ->defaults([
        'controller' => 'Nj618_Hash',
        'action'     => 'index',
    ]);

Route::set('nj618_api', 'nj618/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj618_Api',
        'action'     => 'hash',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
