<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj518_percent (калькулятор процентов).
 */

Route::set('nj518_page', 'percent')
    ->defaults([
        'controller' => 'Nj518_Percent',
        'action'     => 'index',
    ]);

Route::set('nj518_api', 'nj518/api(/<action>)')
    ->defaults([
        'controller' => 'Nj518_Api',
        'action'     => 'calc',
    ]);

// Зависимости: нет — модуль stateless (без таблиц и очередей).
