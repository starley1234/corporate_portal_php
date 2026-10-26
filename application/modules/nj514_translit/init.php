<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj514_page', 'translit')
    ->defaults(['controller' => 'Nj514_Translit', 'action' => 'index']);

Route::set('nj514_api', 'nj514/api(/<action>)')
    ->defaults(['controller' => 'Nj514_Api', 'action' => 'convert']);
