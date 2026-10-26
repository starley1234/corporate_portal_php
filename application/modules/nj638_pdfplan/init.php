<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj638_pdfplan: «Конструктор А4».
 *   /pdf-plan           — страница сервиса;
 *   /pdf-plan/api/<action> — JSON API (build, run).
 */

Route::set('nj638_page', 'pdf-plan')
    ->defaults([
        'controller' => 'Nj638_Pdfplan',
        'action'     => 'index',
    ]);

Route::set('nj638_api', 'pdf-plan/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj638_Api',
        'action'     => 'build',
        'id'         => NULL,
    ]);
