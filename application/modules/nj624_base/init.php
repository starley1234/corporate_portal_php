<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj624_page', 'base')
    ->defaults([
        'controller' => 'Nj624_Base',
        'action'     => 'index',
    ]);

Route::set('nj624_api', 'nj624/api(/<action>)')
    ->defaults([
        'controller' => 'Nj624_Api',
        'action'     => 'convert',
    ]);
