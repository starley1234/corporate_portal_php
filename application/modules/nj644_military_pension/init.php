<?php defined('SYSPATH') or die('No direct script access.');

// Справочный расчёт пенсии за выслугу лет: персональные значения остаются в браузере.
Route::set('nj644_military_pension_page', 'military-pension')
    ->defaults([
        'controller' => 'Nj644_Pension',
        'action' => 'index',
    ]);
