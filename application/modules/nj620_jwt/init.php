<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj620_jwt («JWT-декодер»).
 */

Route::set('nj620_page', 'jwt')
    ->defaults([
        'controller' => 'Nj620_Jwt',
        'action'     => 'index',
    ]);

Route::set('nj620_api', 'nj620/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj620_Api',
        'action'     => 'decode',
    ]);

// Зависит от nj619_unixtime (перевод exp/iat в локальную дату).
