<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj613_digest: «Дайджест руководителю».
 *   /digest         — подписка и мои сводки;
 *   /digest/api/*   — пиналка и приём резюме (токен воркера);
 *   /nj613/admin    — обзор для администратора.
 */

Route::set('nj613_api', 'digest/api(/<action>)')
    ->defaults([
        'controller' => 'Nj613_Api',
        'action'     => 'tick',
    ]);

Route::set('nj613_admin', 'nj613/admin')
    ->defaults([
        'controller' => 'Nj613_Admin',
        'action'     => 'index',
    ]);

Route::set('nj613_page', 'digest')
    ->defaults([
        'controller' => 'Nj613_Digest',
        'action'     => 'index',
    ]);
