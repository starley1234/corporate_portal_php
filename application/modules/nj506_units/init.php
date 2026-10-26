<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj506_page', 'units')
    ->defaults(['controller' => 'Nj506_Units', 'action' => 'index']);

Route::set('nj506_api', 'nj506/api(/<action>)')
    ->defaults(['controller' => 'Nj506_Api', 'action' => 'calc']);
