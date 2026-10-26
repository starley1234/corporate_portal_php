<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj664_jsondecode («Декодер JSON»).
 *
 * Зависимостей нет: модуль stateless (без таблиц, очередей и воркеров).
 * Разбор идёт в браузере; сервер нужен только вкладкам PHP (print_r и код).
 */

Route::set('nj664_page', 'json-decode')
    ->defaults([
        'controller' => 'Nj664_Jsondecode',
        'action'     => 'index',
    ]);

Route::set('nj664_api', 'nj664/api(/<action>)')
    ->defaults([
        'controller' => 'Nj664_Api',
        'action'     => 'decode',
    ]);
