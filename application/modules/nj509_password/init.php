<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj509_password («Генератор паролей»).
 */

Route::set('nj509_password_page', 'password')
    ->defaults([
        'controller' => 'Nj509_Password',
        'action'     => 'index',
    ]);

Route::set('nj509_api', 'nj509/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj509_Api',
        'action'     => 'generate',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
