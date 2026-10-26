<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj630_page', 'ipcalc')
    ->defaults([
        'controller' => 'Nj630_Ipcalc',
        'action'     => 'index',
    ]);

Route::set('nj630_api', 'nj630/api(/<action>)')
    ->defaults([
        'controller' => 'Nj630_Api',
        'action'     => 'range',
    ]);
