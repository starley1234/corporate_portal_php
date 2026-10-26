<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj647_loan («Кредит и ипотека»).
 *
 * Зависимостей нет: модуль stateless (без таблиц, очередей и воркеров).
 */

Route::set('nj647_page', 'loan')
    ->defaults([
        'controller' => 'Nj647_Loan',
        'action'     => 'index',
    ]);

Route::set('nj647_api', 'nj647/api(/<action>)')
    ->defaults([
        'controller' => 'Nj647_Api',
        'action'     => 'calc',
    ]);
