<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj590_design («Дизайн страницы»).
 *
 * Публичные адреса: /design — создание прогона, /design/<code> — прогон
 * с вариантами оформления. HTML-варианты — /design/<code>/file/<id>
 * (inline, для iframe-превью). API воркера — /design/api/*.
 * Таблицы: nj_590_* (миграция 001). Генерация — воркер nj590.design:
 * воркер скачивает страницу, LLM разбирает её и пишет N однофайловых
 * HTML-вариантов улучшенного дизайна (workers/n2j_workers/handlers/nj590_design.py).
 */

Route::set('nj590_api', 'design/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj590_Api',
        'action'     => 'status',
    ]);

// файл варианта — ВЫШЕ /design/<code>(/<action>), иначе «file» съедается как <action>
Route::set('nj590_run_file', 'design/<code>/file/<id>', ['code' => '[0-9a-z]+', 'id' => '\d+'])
    ->defaults([
        'controller' => 'Nj590_Design',
        'action'     => 'file',
    ]);

Route::set('nj590_view', 'design/<code>(/<action>)', ['code' => '[0-9a-z]+', 'action' => '(pick|cancel)'])
    ->defaults([
        'controller' => 'Nj590_Design',
        'action'     => 'view',
    ]);

Route::set('nj590_page', 'design')
    ->defaults([
        'controller' => 'Nj590_Design',
        'action'     => 'index',
    ]);

Route::set('nj590_admin', 'nj590/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj590_Admin',
        'action'     => 'index',
    ]);
