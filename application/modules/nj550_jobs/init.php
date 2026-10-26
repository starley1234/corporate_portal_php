<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj550_jobs («Работа 2 Оффер» / Job 2 Offer).
 */

// Форма (GET) / создание поиска (POST)
Route::set('nj550_jobs_page', 'jobs')
    ->defaults([
        'controller' => 'Nj550_Jobs',
        'action'     => 'index',
    ]);

// Статус и результат: /jobs/<code>
Route::set('nj550_jobs_view', 'jobs/<code>', ['code' => '[0-9a-z]{4,16}'])
    ->defaults([
        'controller' => 'Nj550_Jobs',
        'action'     => 'view',
    ]);

// Часть управления: последние поиски и управление
Route::set('nj550_admin', 'nj550/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj550_Admin',
        'action'     => 'index',
    ]);

// JSON API
Route::set('nj550_api', 'nj550/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj550_Api',
        'action'     => 'status',
    ]);

// Таблицы: nj_550_searches, nj_550_results (миграция 001).
// Очередь: канал nj550.jobs (python-воркер, ddgs + LangChain).
