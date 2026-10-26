<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj674_mcpguard', 'mcpguard')
    ->defaults([
        'controller' => 'Nj674_McpGuard',
        'action'     => 'index',
    ]);
