<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj673_aiwatch', 'aiwatch')
    ->defaults([
        'controller' => 'Nj673_AiWatch',
        'action'     => 'index',
    ]);
