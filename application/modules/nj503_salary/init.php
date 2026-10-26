<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj503_salary (калькулятор зарплаты: на руки ⇄ до налога).
 */

// Страница калькулятора: /salary
Route::set('nj503_page', 'salary')
    ->defaults([
        'controller' => 'Nj503_Salary',
        'action'     => 'index',
    ]);

// JSON API
Route::set('nj503_api', 'nj503/api(/<action>)')
    ->defaults([
        'controller' => 'Nj503_Api',
        'action'     => 'calc',
    ]);

// Зависимости: Service_Nj501_Vat (разбор и формат денег) — модуль nj501_vat должен быть подключён.
