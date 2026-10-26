<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj625_page', 'bytes')
    ->defaults([
        'controller' => 'Nj625_Bytes',
        'action'     => 'index',
    ]);

Route::set('nj625_api', 'nj625/api(/<action>)')
    ->defaults([
        'controller' => 'Nj625_Api',
        'action'     => 'convert',
    ]);
