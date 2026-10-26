<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj632_page', 'csv')
    ->defaults([
        'controller' => 'Nj632_Csv',
        'action'     => 'index',
    ]);

Route::set('nj632_api', 'nj632/api(/<action>)')
    ->defaults([
        'controller' => 'Nj632_Api',
        'action'     => 'convert',
    ]);
