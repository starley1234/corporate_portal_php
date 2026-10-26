<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj623_roman («Римские числа»).
 */

Route::set('nj623_page', 'roman')
    ->defaults([
        'controller' => 'Nj623_Roman',
        'action'     => 'index',
    ]);

Route::set('nj623_api', 'nj623/api(/<action>)')
    ->defaults([
        'controller' => 'Nj623_Api',
        'action'     => 'convert',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
