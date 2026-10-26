<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj507_product', 'cooking/<product>', ['product' => '[a-z0-9-]+'])
    ->defaults(['controller' => 'Nj507_Cooking', 'action' => 'product']);

Route::set('nj507_page', 'cooking')
    ->defaults(['controller' => 'Nj507_Cooking', 'action' => 'index']);

Route::set('nj507_api', 'nj507/api(/<action>)')
    ->defaults(['controller' => 'Nj507_Api', 'action' => 'convert']);
