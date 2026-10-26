<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj592_coldmail («Холодное письмо»).
 *
 * Публичные адреса: /coldmail — создание, /coldmail/<code> — варианты
 * персонализированного первого абзаца. API воркера — /coldmail/api/*.
 * Таблицы: nj_592_* (миграция 001). Генерация — воркер nj592.email:
 * профиль компании (свой прогон nj591 или свежий обход webread) + DDG-новости
 * → LLM → N вариантов первого абзаца с обоснованием.
 */

Route::set('nj592_api', 'coldmail/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj592_Api',
        'action'     => 'status',
    ]);

// delete — ВЫШЕ /coldmail/<code>, иначе «delete» съедается как <code>
Route::set('nj592_stop', 'coldmail/stop/<code>', ['code' => '[0-9a-z]+'])
    ->defaults(['controller' => 'Nj592/Coldmail', 'action' => 'stop']);

Route::set('nj592_delete', 'coldmail/<code>/delete', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj592_Coldmail',
        'action'     => 'delete',
    ]);

Route::set('nj592_view', 'coldmail/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj592_Coldmail',
        'action'     => 'view',
    ]);

Route::set('nj592_page', 'coldmail')
    ->defaults([
        'controller' => 'Nj592_Coldmail',
        'action'     => 'index',
    ]);

Route::set('nj592_admin', 'nj592/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj592_Admin',
        'action'     => 'index',
    ]);
