<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj616_convert («Кодировщик»).
 */

Route::set('nj616_page', 'convert')
    ->defaults([
        'controller' => 'Nj616_Convert',
        'action'     => 'index',
    ]);

Route::set('nj616_api', 'nj616/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj616_Api',
        'action'     => 'convert',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
