<?php defined('SYSPATH') or die('No direct script access.');

/** QR-коды: содержимое и картинки обрабатываются только в браузере. */
Route::set('nj510_qr_page', 'qr')
    ->defaults([
        'controller' => 'Nj510_Qr',
        'action'     => 'index',
    ]);
