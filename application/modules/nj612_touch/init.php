<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj612_touch: «Серии касаний».
 *   /touches          — мои серии + создание;
 *   /touches/<code>   — таймлайн серии;
 *   /touches/api/*    — API воркера и пиналки (токен воркера).
 */

Route::set('nj612_api', 'touches/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj612_Api',
        'action'     => 'tick',
    ]);

Route::set('nj612_admin', 'nj612/admin')
    ->defaults([
        'controller' => 'Nj612_Admin',
        'action'     => 'index',
    ]);

Route::set('nj612_view', 'touches/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj612_Touch',
        'action'     => 'view',
    ]);

Route::set('nj612_page', 'touches')
    ->defaults([
        'controller' => 'Nj612_Touch',
        'action'     => 'index',
    ]);
