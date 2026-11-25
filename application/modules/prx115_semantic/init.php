<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prx115_semantic (эмбеддинги и семантический поиск).
 */

// --- Роуты -------------------------------------------------------------------
Route::set('prx115_api', 'prx115/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Prx115_Api',
        'action'     => 'stats',
    ]);

// Страница модуля: коллекции, состояние индексации, проверка и тестовый поиск.
// Регистрируется ПОСЛЕ API: /prx115/api/* уходит в JSON-контроллер, /prx115 — сюда.
Route::set('prx115_page', 'prx115(/<action>)')
    ->defaults([
        'controller' => 'Prx115_Page',
        'action'     => 'index',
    ]);

// Канонический адрес главной модуля (префикс+слаг): /prx115_semantic.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx115_semantic_home', 'prx115_semantic')

    ->defaults([
        'controller' => 'Prx115_Page',
        'action'     => 'index',
    ]);

// --- Очередь -------------------------------------------------------------------
// Канал semantic.index обрабатывает python-воркер (workers/, режим pg):
// получает векторы с эмбеддинг-сервера и пишет их в prx_115_vectors.
