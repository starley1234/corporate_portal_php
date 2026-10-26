<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация nj601_billing (Robokassa).
 * В кабинете Robokassa указать Result URL: https://n2j.ru/billing/result
 * (Success/Fail передаются в запросе явно).
 */

Route::set('nj601_pay', 'billing/pay')
    ->defaults([
        'controller' => 'Nj601_Billing',
        'action'     => 'pay',
    ]);

// nj601_billing: страница баланса/пакетов кредитов /billing
Route::set('nj601_home', 'billing')
    ->defaults([
        'controller' => 'Nj601_Balance',
        'action'     => 'home',
    ]);

Route::set('nj601_result', 'billing/result')
    ->defaults([
        'controller' => 'Nj601_Billing',
        'action'     => 'result',
    ]);

Route::set('nj601_success', 'billing/success')
    ->defaults([
        'controller' => 'Nj601_Billing',
        'action'     => 'success',
    ]);

Route::set('nj601_fail', 'billing/fail')
    ->defaults([
        'controller' => 'Nj601_Billing',
        'action'     => 'fail',
    ]);

Route::set('nj601_admin', 'nj601/admin')
    ->defaults([
        'controller' => 'Nj601_Admin',
        'action'     => 'index',
    ]);
