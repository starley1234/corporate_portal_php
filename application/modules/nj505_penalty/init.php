<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj505_penalty (пени по НК, проценты по ст. 395 ГК, неустойка).
 */

// Страница калькулятора: /penalty
Route::set('nj505_page', 'penalty')
    ->defaults([
        'controller' => 'Nj505_Penalty',
        'action'     => 'index',
    ]);

// JSON API
Route::set('nj505_api', 'nj505/api(/<action>)')
    ->defaults([
        'controller' => 'Nj505_Api',
        'action'     => 'calc',
    ]);

// Зависимости: Service_Nj501_Vat (деньги), Service_Nj508_Calendar (разбор дат) — модули подключены раньше.
