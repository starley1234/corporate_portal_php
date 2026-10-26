<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj617_uuid («Генератор идентификаторов»).
 */

Route::set('nj617_page', 'uuid')
    ->defaults([
        'controller' => 'Nj617_Uuid',
        'action'     => 'index',
    ]);

Route::set('nj617_api', 'nj617/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj617_Api',
        'action'     => 'generate',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
