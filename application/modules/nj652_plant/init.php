<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты модуля nj652_plant («Растение по фото»).
 *
 * Порядок важен: `plant/api/...` объявлен ДО `plant/<id>`, иначе адрес API
 * попал бы в страницу разбора как «код объекта».
 */

Route::set('nj652_api', 'plant/api(/<action>)')
    ->defaults([
        'controller' => 'Nj652_Api',
        'action'     => 'photo',
    ]);

Route::set('nj652_page', 'plant')
    ->defaults([
        'controller' => 'Nj652_Plant',
        'action'     => 'index',
    ]);

Route::set('nj652_run', 'plant/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj652_Plant',
        'action'     => 'run',
    ]);

// Таблица: nj_652_runs (миграция 001). Канал очереди: nj652.plant —
// обработчик workers/n2j_workers/handlers/nj652_plant.py.
