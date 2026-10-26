<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj629_page', 'color')
    ->defaults([
        'controller' => 'Nj629_Color',
        'action'     => 'index',
    ]);

Route::set('nj629_api', 'nj629/api(/<action>)')
    ->defaults([
        'controller' => 'Nj629_Api',
        'action'     => 'convert',
    ]);
