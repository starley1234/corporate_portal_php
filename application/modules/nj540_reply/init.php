<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj540_reply («Отзыв 2 Ответ» / Review 2 Reply).
 */

// Форма (GET) / создание (POST)
Route::set('nj540_review_page', 'review')
    ->defaults([
        'controller' => 'Nj540_Review',
        'action'     => 'index',
    ]);

// Статус и результат: /review/<code>
Route::set('nj540_review_view', 'review/<code>', ['code' => '[0-9a-z]{6,16}'])
    ->defaults([
        'controller' => 'Nj540_Review',
        'action'     => 'view',
    ]);

// JSON API: статус + перегенерация
Route::set('nj540_api', 'nj540/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj540_Api',
        'action'     => 'status',
    ]);

// Таблица: nj_540_replies (миграция 001). Очередь: канал nj540.reply (python-воркер).
