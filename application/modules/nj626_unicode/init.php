<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj626_page', 'unicode')
    ->defaults([
        'controller' => 'Nj626_Unicode',
        'action'     => 'index',
    ]);

Route::set('nj626_api', 'nj626/api(/<action>)')
    ->defaults([
        'controller' => 'Nj626_Api',
        'action'     => 'analyze',
    ]);
