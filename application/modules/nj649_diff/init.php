<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj649_diff («Сравнение текстов»).
 *
 * Зависимостей нет: модуль stateless (без таблиц, очередей и воркеров).
 */

Route::set('nj649_page', 'diff')
    ->defaults([
        'controller' => 'Nj649_Diff',
        'action'     => 'index',
    ]);

Route::set('nj649_api', 'nj649/api(/<action>)')
    ->defaults([
        'controller' => 'Nj649_Api',
        'action'     => 'compare',
    ]);
