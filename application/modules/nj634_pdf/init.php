<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj634_pdf: «PDF-инструменты».
 *   /pdf            — страница сервиса;
 *   /pdf/api/<action> — JSON API (info, text, txt2pdf, split, merge, run).
 */

Route::set('nj634_page', 'pdf')
    ->defaults([
        'controller' => 'Nj634_Pdf',
        'action'     => 'index',
    ]);

Route::set('nj634_api', 'pdf/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj634_Api',
        'action'     => 'info',
        'id'         => NULL,
    ]);
