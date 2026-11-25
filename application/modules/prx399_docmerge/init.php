<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Роуты prx399_docmerge. Канонический адрес главной — /prx399_docmerge
 * (префикс+слаг), алиас /prx399_index работает через Manifest (ModuleHome).
 * API воркера живёт под /prx399/api и защищён X-Worker-Token, а не сессией.
 */

// Канонический адрес главной модуля (префикс+слаг).
Route::set('prx399_docmerge_home', 'prx399_docmerge')
    ->defaults([
        'directory' => 'Prx399',
        'controller' => 'Ui',
        'action' => 'index',
    ]);

Route::set('prx399_docmerge_ui', 'prx399_docmerge(/<action>(/<id>))')
    ->defaults([
        'directory' => 'Prx399',
        'controller' => 'Ui',
        'action' => 'index',
    ]);

// API для воркера: health, delta_input/<file_id>, delta_result/<file_id>,
// file/<file_id> (PDF), audit_input/<contract_id>, audit_result/<contract_id>.
Route::set('prx399_docmerge_api', 'prx399/api(/<action>(/<id>))')
    ->defaults([
        'directory' => 'Prx399',
        'controller' => 'Api',
        'action' => 'index',
    ]);
