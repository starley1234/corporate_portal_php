<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj591_company_profile («Профиль компании»).
 *
 * Публичные адреса: /company — создание профиля, /company/<code> — результат
 * (сфера, размер, контакты, стек, ЛПР), /company/<code>/json — выгрузка JSON.
 * API воркера — /company/api/*. Таблицы: nj_591_* (миграция 001).
 * Сбор — воркер nj591.profile: общий конвейер webread (curl_cffi →
 * trafilatura → Markdown для малых LLM) + LLM со строгой JSON-схемой
 * (workers/n2j_workers/webread.py, handlers/nj591_profile.py).
 */

// API — ВЫШЕ /company/<code>, иначе «api» съедается как <code>
Route::set('nj591_api', 'company/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj591_Api',
        'action'     => 'status',
    ]);

Route::set('nj591_json', 'company/<code>/json', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj591_Company',
        'action'     => 'json',
    ]);

// delete — ВЫШЕ /company/<code>, иначе «delete» съедается как <code>
Route::set('nj591_delete', 'company/<code>/delete', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj591_Company',
        'action'     => 'delete',
    ]);

Route::set('nj591_view', 'company/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj591_Company',
        'action'     => 'view',
    ]);

Route::set('nj591_page', 'company')
    ->defaults([
        'controller' => 'Nj591_Company',
        'action'     => 'index',
    ]);

Route::set('nj591_admin', 'nj591/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj591_Admin',
        'action'     => 'index',
    ]);
