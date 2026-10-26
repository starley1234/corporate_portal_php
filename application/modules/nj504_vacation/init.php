<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj504_vacation (отпускные, даты отпуска, компенсация, выгодное планирование).
 */

// Страница калькулятора: /vacation
Route::set('nj504_page', 'vacation')
    ->defaults([
        'controller' => 'Nj504_Vacation',
        'action'     => 'index',
    ]);

// JSON API
Route::set('nj504_api', 'nj504/api(/<action>)')
    ->defaults([
        'controller' => 'Nj504_Api',
        'action'     => 'calc',
    ]);

// Зависимости: Service_Nj501_Vat (деньги), Service_Nj508_Calendar (календарь, даты, периоды).
