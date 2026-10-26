<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj570_models (3D-конструктор, «Sketch 2 Solid»).
 *
 * Публичные адреса: /3d — конструктор и каталог, /3d/<code> — модель.
 * Файлы STL/PNG — /3d/file/<id>; API воркера — /3d/api/*.
 * Таблицы: nj_570_* (миграция 001). Рендер — ТОЛЬКО локальный OpenSCAD на
 * сервере воркера (workers/n2j_workers/scad.py, бинарник+ BOSL2 — workers/openscad-renderer).
 */

Route::set('nj570_page', '3d')
    ->defaults([
        'controller' => 'Nj570_Models',
        'action'     => 'index',
    ]);

Route::set('nj570_api', '3d/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj570_Api',
        'action'     => 'status',
    ]);

Route::set('nj570_view', '3d/<code>(/<action>)', ['code' => '[0-9a-z]+', 'action' => '(edit|answers|rerender|rate|publish)'])
    ->defaults([
        'controller' => 'Nj570_Models',
        'action'     => 'view',
    ]);

Route::set('nj570_model_file', '3d/<code>/file/<id>', ['code' => '[0-9a-z]+', 'id' => '\d+'])
    ->defaults([
        'controller' => 'Nj570_Models',
        'action'     => 'file',
    ]);

Route::set('nj570_file', '3d/file/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj570_Models',
        'action'     => 'file',
    ]);

Route::set('nj570_admin', 'nj570/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj570_Admin',
        'action'     => 'index',
    ]);
