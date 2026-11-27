<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты документации архитектуры.
 *
 *   /architecture           — собранная страница (HTML)
 *   /architecture/data.json — та же модель для программ (JSON)
 *   /architecture/guide     — памятка программиста (философия, архитектура, имена)
 */
Route::set('prx101_architecture_data', 'architecture/data.json')
    ->defaults([
        'directory'  => 'Prx101',
        'controller' => 'Architecture',
        'action'     => 'data',
    ]);

// Памятка программиста: действие и ссылка «Памятка» в меню модуля были, маршрута — нет (404).
Route::set('prx101_architecture_guide', 'architecture/guide')
    ->defaults([
        'directory'  => 'Prx101',
        'controller' => 'Architecture',
        'action'     => 'guide',
    ]);

Route::set('prx101_architecture', 'architecture')
    ->defaults([
        'directory'  => 'Prx101',
        'controller' => 'Architecture',
        'action'     => 'index',
    ]);

// Канонический адрес главной модуля (префикс+слаг): /prx101_architecture.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx101_architecture_home', 'prx101_architecture')

    ->defaults([
        'directory'  => 'Prx101',
        'controller' => 'Architecture',
        'action'     => 'index',
    ]);
