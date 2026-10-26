<?php defined('SYSPATH') or die('No direct script access.');

// API объявляется до обычной страницы, чтобы /employee/api не попадал в
// маршруты профиля при расширении набора действий.
Route::set('prl300_employee_api_colleague', 'employee/api/colleague/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl300_Api', 'action' => 'colleague']);
Route::set('prl300_employee_api', 'employee/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prl300_Api', 'action' => 'me']);
Route::set('prl300_employee_directory', 'employee/directory')
    ->defaults(['controller' => 'Prl300_Profile', 'action' => 'directory']);
Route::set('prl300_employee_colleague', 'employee/colleague/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl300_Profile', 'action' => 'colleague']);
Route::set('prl300_employee', 'employee')
    ->defaults(['controller' => 'Prl300_Profile', 'action' => 'index']);
Route::set('prl300_employee_admin', 'prl300/admin(/<action>(/<id>))', [
        'action' => '[a-z_]+',
        'id' => '\d+',
    ])
    ->defaults(['controller' => 'Prl300_Admin', 'action' => 'index']);
