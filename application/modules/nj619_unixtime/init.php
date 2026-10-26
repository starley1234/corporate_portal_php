<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj619_unixtime («Unix-время»).
 */

Route::set('nj619_page', 'unixtime')
    ->defaults([
        'controller' => 'Nj619_UnixTime',
        'action'     => 'index',
    ]);

Route::set('nj619_api', 'nj619/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj619_Api',
        'action'     => 'convert',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
