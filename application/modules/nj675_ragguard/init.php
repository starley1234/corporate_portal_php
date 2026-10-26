<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj675_ragguard', 'ragguard')
    ->defaults([
        'controller' => 'Nj675_RagGuard',
        'action'     => 'index',
    ]);
