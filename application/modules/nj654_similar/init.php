<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты «Похожих картинок» (nj654_similar).
 *
 * Порядок важен: служебные адреса и отдельная страница результата объявлены
 * до общей страницы /similar, чтобы их не перехватил короткий маршрут.
 */

Route::set('nj654_api', 'similar/api(/<action>)')
    ->defaults([
        'controller' => 'Nj654_Api',
        'action'     => 'status',
    ]);

Route::set('nj654_admin', 'similar/admin(/<action>)')
    ->defaults([
        'controller' => 'Nj654_Admin',
        'action'     => 'index',
    ]);

Route::set('nj654_result', 'similar/result/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj654_Similar',
        'action'     => 'result',
    ]);

Route::set('nj654_page', 'similar(/<action>)')
    ->defaults([
        'controller' => 'Nj654_Similar',
        'action'     => 'index',
    ]);
