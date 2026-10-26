<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты модуля nj651_meal («Калории по фото тарелки»).
 *
 * Порядок важен: `meal/api/...` объявлен ДО `meal/<id>`, иначе адрес API
 * попал бы в страницу разбора как «код объекта».
 */

Route::set('nj651_api', 'meal/api(/<action>)')
    ->defaults([
        'controller' => 'Nj651_Api',
        'action'     => 'photo',
    ]);

Route::set('nj651_page', 'meal')
    ->defaults([
        'controller' => 'Nj651_Meal',
        'action'     => 'index',
    ]);

Route::set('nj651_delete', 'meal/<id>/delete', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj651_Meal',
        'action'     => 'delete',
    ]);

Route::set('nj651_run', 'meal/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj651_Meal',
        'action'     => 'run',
    ]);

// Таблица: nj_651_runs (миграция 001). Канал очереди: nj651.meal —
// обработчик workers/n2j_workers/handlers/nj651_meal.py.
