<?php defined('SYSPATH') or die('No direct script access.');

/** Публичная витрина проверки ИНН (NJ-517); данные пользователя не сохраняются в URL. */
Route::set('nj517_page', 'inn')
    ->defaults([
        'controller' => 'Nj517_Inn',
        'action'     => 'index',
    ]);

Route::set('nj517_api', 'nj517/api(/<action>)')
    ->defaults([
        'controller' => 'Nj517_Api',
        'action'     => 'lookup',
    ]);
