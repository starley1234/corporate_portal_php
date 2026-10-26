<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj580_orgdocs («Документы организации»).
 *
 * Публичные адреса: /docs — конструктор пакета, /docs/<code> — пакет.
 * Файлы DOCX/PDF/XLSX/ZIP — /docs/file/<id>; API воркера — /docs/api/*.
 * Таблицы: nj_580_* (миграция 001). Генерация — воркер nj580.generate:
 * LLM (план → черновики → проверка) + DuckDuckGo-референсы + сборка
 * DOCX/PDF/XLSX по ГОСТ Р 7.0.97-2016 (workers/n2j_workers/docgen).
 */

Route::set('nj580_page', 'docs')
    ->defaults([
        'controller' => 'Nj580_Orgdocs',
        'action'     => 'index',
    ]);

Route::set('nj580_api', 'docs/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj580_Api',
        'action'     => 'status',
    ]);

// мастер-опросник — ВЫШЕ /docs/<code>, иначе «wizard» съедается как <code>
Route::set('nj580_custom', 'docs/custom')
    ->defaults([
        'controller' => 'Nj580_Orgdocs',
        'action'     => 'custom',
    ]);

Route::set('nj580_wizard', 'docs/wizard')
    ->defaults([
        'controller' => 'Nj580_Orgdocs',
        'action'     => 'wizard',
    ]);

Route::set('nj580_view', 'docs/<code>(/<action>)', ['code' => '[0-9a-z]+', 'action' => '(regen|feedback|delete)'])
    ->defaults([
        'controller' => 'Nj580_Orgdocs',
        'action'     => 'view',
    ]);

Route::set('nj580_pkg_file', 'docs/<code>/file/<id>', ['code' => '[0-9a-z]+', 'id' => '\d+'])
    ->defaults([
        'controller' => 'Nj580_Orgdocs',
        'action'     => 'file',
    ]);

Route::set('nj580_file', 'docs/file/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj580_Orgdocs',
        'action'     => 'file',
    ]);

Route::set('nj580_admin', 'nj580/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj580_Admin',
        'action'     => 'index',
    ]);
