<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj511_page', 'size')
    ->defaults(['controller' => 'Nj511_Size', 'action' => 'index']);

Route::set('nj511_api', 'nj511/api(/<action>)')
    ->defaults(['controller' => 'Nj511_Api', 'action' => 'convert']);
