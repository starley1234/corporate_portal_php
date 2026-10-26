<?php defined('SYSPATH') or die('No direct script access.');

/** Инициализация nj516_metrics: только закрытая админка и внутренний hit-endpoint. */

Route::set('nj516_admin', 'nj516/admin')
    ->defaults([
        'controller' => 'Nj516_Admin',
        'action'     => 'index',
    ]);

Route::set('nj516_api', 'nj516/api(/<action>)')
    ->defaults([
        'controller' => 'Nj516_Api',
        'action'     => 'hit',
    ]);
