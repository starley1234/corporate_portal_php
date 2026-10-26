<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj621_cron («Cron-человечизатор»).
 */

Route::set('nj621_page', 'cron')
    ->defaults([
        'controller' => 'Nj621_Cron',
        'action'     => 'index',
    ]);

Route::set('nj621_api', 'nj621/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj621_Api',
        'action'     => 'explain',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
