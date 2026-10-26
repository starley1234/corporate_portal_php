<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj631_page', 'text')
    ->defaults([
        'controller' => 'Nj631_Text',
        'action'     => 'index',
    ]);

Route::set('nj631_api', 'nj631/api(/<action>)')
    ->defaults([
        'controller' => 'Nj631_Api',
        'action'     => 'tool',
    ]);
