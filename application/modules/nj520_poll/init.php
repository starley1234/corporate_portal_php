<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj520_poll (быстрые опросы «Ask 2 Vote»).
 */

// Форма создания (GET) / создание (POST)
Route::set('nj520_poll_page', 'poll')
    ->defaults([
        'controller' => 'Nj520_Poll',
        'action'     => 'index',
    ]);

// Страница опроса /p/<code>: GET — голосование и результаты, POST — голос
Route::set('nj520_poll_view', 'p/<code>', ['code' => '[0-9a-zA-Z]{1,8}'])
    ->defaults([
        'controller' => 'Nj520_Poll',
        'action'     => 'view',
    ]);

// JSON API
Route::set('nj520_api', 'nj520/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj520_Api',
        'action'     => 'stats',
    ]);

// Таблицы: nj_520_polls, nj_520_options, nj_520_votes (миграция 001).

// Страница управления опросов: список, голоса, IP проголосовавших, удаление
Route::set('nj520_admin', 'nj520/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj520_Admin',
        'action'     => 'index',
    ]);
