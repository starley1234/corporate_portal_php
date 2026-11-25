<?php defined('SYSPATH') or die('No direct script access.');

// API объявляется до обычной страницы, чтобы /employee/api не попадал в
// маршруты профиля при расширении набора действий.
Route::set('prx300_employee_api_colleague', 'employee/api/colleague/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx300_Api', 'action' => 'colleague']);
Route::set('prx300_employee_api', 'employee/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prx300_Api', 'action' => 'me']);
Route::set('prx300_employee_directory', 'employee/directory')
    ->defaults(['controller' => 'Prx300_Profile', 'action' => 'directory']);
Route::set('prx300_employee_colleague', 'employee/colleague/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx300_Profile', 'action' => 'colleague']);
Route::set('prx300_employee', 'employee')
    ->defaults(['controller' => 'Prx300_Profile', 'action' => 'index']);

// Канонический адрес главной модуля (префикс+слаг): /prx300_employee.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx300_employee_home', 'prx300_employee')

    ->defaults(['controller' => 'Prx300_Profile', 'action' => 'index']);
Route::set('prx300_employee_admin', 'prx300/admin(/<action>(/<id>))', [
        'action' => '[a-z_]+',
        'id' => '\d+',
    ])
    ->defaults(['controller' => 'Prx300_Admin', 'action' => 'index']);

// Оргконтекст для Prx_Actor: база страницы о prx300 не знает, спрашивает точку
// расширения (docs/SKELETON-ADR.md Д2). Без модуля оргконтекст пуст.
Prx_Hooks::on('actor.org', function ($user_id) {
    return (array) Service_Prx300_Directory::context_for_user($user_id);
});
