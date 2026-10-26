<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj622_regex («Тест regex»).
 */

Route::set('nj622_page', 'regex')
    ->defaults([
        'controller' => 'Nj622_Regex',
        'action'     => 'index',
    ]);

Route::set('nj622_api', 'nj622/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj622_Api',
        'action'     => 'test',
    ]);

// Зависимостей нет: модуль stateless. Серверный preg-прогон — изолирован,
// без интернета (офлайн-режим), сетевых вызовов модуль не делает.
