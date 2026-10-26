<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj636_pdfsign: «Подписи и реквизиты PDF».
 *   /pdf-sign           — страница сервиса;
 *   /pdf-sign/api/<action> — JSON API (stamp, count, run).
 */

Route::set('nj636_page', 'pdf-sign')
    ->defaults([
        'controller' => 'Nj636_Pdfsign',
        'action'     => 'index',
    ]);

Route::set('nj636_api', 'pdf-sign/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj636_Api',
        'action'     => 'stamp',
        'id'         => NULL,
    ]);
