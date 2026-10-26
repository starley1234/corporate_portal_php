<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj637_watermark: «Водяные знаки на PDF».
 *   /watermark          — страница сервиса;
 *   /watermark/api/<action> — JSON API (apply, run).
 */

Route::set('nj637_page', 'watermark')
    ->defaults([
        'controller' => 'Nj637_Watermark',
        'action'     => 'index',
    ]);

Route::set('nj637_api', 'watermark/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj637_Api',
        'action'     => 'apply',
        'id'         => NULL,
    ]);
