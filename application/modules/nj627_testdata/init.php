<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj627_page', 'testdata')
    ->defaults([
        'controller' => 'Nj627_Testdata',
        'action'     => 'index',
    ]);

Route::set('nj627_api', 'nj627/api(/<action>)')
    ->defaults([
        'controller' => 'Nj627_Api',
        'action'     => 'generate',
    ]);
