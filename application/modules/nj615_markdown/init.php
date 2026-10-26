<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj615_markdown («Markdown-инструмент»).
 */

Route::set('nj615_page', 'markdown')
    ->defaults([
        'controller' => 'Nj615_Markdown',
        'action'     => 'index',
    ]);

Route::set('nj615_api', 'nj615/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj615_Api',
        'action'     => 'render',
    ]);

// Зависимостей нет: модуль stateless (без таблиц и очередей).
