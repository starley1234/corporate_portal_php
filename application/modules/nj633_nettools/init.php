<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj633_nettools: «Сетевые инструменты».
 *   /net           — страница сервиса;
 *   /net/api/<action> — JSON API (dns, whois, check, llm) + колбэк llm_cb.
 */

Route::set('nj633_page', 'net')
    ->defaults([
        'controller' => 'Nj633_Net',
        'action'     => 'index',
    ]);

Route::set('nj633_api', 'net/api(/<action>)')
    ->defaults([
        'controller' => 'Nj633_Api',
        'action'     => 'dns',
    ]);
