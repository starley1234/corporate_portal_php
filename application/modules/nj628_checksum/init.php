<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj628_page', 'checksum')
    ->defaults([
        'controller' => 'Nj628_Checksum',
        'action'     => 'index',
    ]);

Route::set('nj628_api', 'nj628/api(/<action>)')
    ->defaults([
        'controller' => 'Nj628_Api',
        'action'     => 'check',
    ]);
