<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj513_page', 'date-words')
    ->defaults(['controller' => 'Nj513_Datewords', 'action' => 'index']);

Route::set('nj513_api', 'nj513/api(/<action>)')
    ->defaults(['controller' => 'Nj513_Api', 'action' => 'convert']);
