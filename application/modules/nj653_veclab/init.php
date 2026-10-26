<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты модуля nj653_veclab («Лаборатория векторов»).
 *
 * Порядок важен: `veclab/api/...`, `veclab/admin` и `veclab/map` объявлены ДО
 * страницы, иначе их адреса перехватила бы она.
 */

Route::set('nj653_api', 'veclab/api(/<action>)')
    ->defaults([
        'controller' => 'Nj653_Api',
        'action'     => 'status',
    ]);

Route::set('nj653_admin', 'veclab/admin(/<action>)')
    ->defaults([
        'controller' => 'Nj653_Admin',
        'action'     => 'index',
    ]);

Route::set('nj653_map', 'veclab/map')
    ->defaults([
        'controller' => 'Nj653_Map',
        'action'     => 'index',
    ]);

Route::set('nj653_result', 'veclab/result/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj653_Veclab',
        'action'     => 'result',
    ]);

Route::set('nj653_page', 'veclab')
    ->defaults([
        'controller' => 'Nj653_Veclab',
        'action'     => 'index',
    ]);

// Таблицы: nj_653_runs, nj_653_items (миграция 001). Канал очереди:
// nj653.embed — обработчик workers/n2j_workers/handlers/nj653_veclab.py.
