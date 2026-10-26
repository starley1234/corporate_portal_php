<?php defined('SYSPATH') or die('No direct script access.');

/** Маршруты NJ-656: более длинный адрес действия должен идти до страницы. */
Route::set('nj656_action', 'meters/<code>/<action>', [
    'code' => '[A-Za-z0-9]{10}',
    'action' => 'reading|settings|delete|export',
])
    ->defaults([
        'controller' => 'Nj656_Meters',
        'action' => 'view',
    ]);

Route::set('nj656_meter', 'meters/<code>', ['code' => '[A-Za-z0-9]{10}'])
    ->defaults([
        'controller' => 'Nj656_Meters',
        'action' => 'view',
    ]);

Route::set('nj656_page', 'meters')
    ->defaults([
        'controller' => 'Nj656_Meters',
        'action' => 'index',
    ]);
