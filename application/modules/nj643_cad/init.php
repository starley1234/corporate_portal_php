<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: «CAD-детали» — 3D по описанию (LLM + headless CAD-движок).
 *
 * /cad                        — описание детали + мои детали;
 * /cad/<code>                 — одна деталь: ракурсы, параметры, файлы, ревизии;
 * /cad/file/<id>              — файл модели (STEP/STL/GLB/ракурс);
 * /cad/archive/<code>[/<rev>] — всё для цеха одним ZIP (файлы, код, README);
 * /cad/admin                  — управление: движок, детали, расход LLM;
 * /cad/api/status/<code>      — живой статус-ряд сборки (эталон /trends/<code>);
 * /cad/api/<action>           — действия страницы (CSRF) и приём от воркера (токен).
 *
 * Порядок маршрутов важен: /cad/api, /cad/file и /cad/archive регистрируются ДО /cad/<code>,
 * иначе «api»/«file» попали бы в шаблон кода детали.
 */

Route::set('nj643_api', 'cad/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj643_Api',
        'action'     => 'index',
        'id'         => NULL,
    ]);

// Архив детали — ДО /cad/<code>, иначе «archive» попал бы в шаблон кода.
Route::set('nj643_archive', 'cad/archive/<code>(/<rev>)', ['code' => '[0-9a-z_]+', 'rev' => '[0-9]+'])
    ->defaults([
        'controller' => 'Nj643_Cad',
        'action'     => 'archive',
        'rev'        => NULL,
    ]);

Route::set('nj643_file', 'cad/file/<id>')
    ->defaults([
        'controller' => 'Nj643_Cad',
        'action'     => 'file',
    ]);

Route::set('nj643_admin', 'cad/admin')
    ->defaults([
        'controller' => 'Nj643_Admin',
        'action'     => 'index',
    ]);

Route::set('nj643_part', 'cad/<code>', ['code' => '[0-9a-z_]+'])
    ->defaults([
        'controller' => 'Nj643_Cad',
        'action'     => 'part',
    ]);

Route::set('nj643_page', 'cad')
    ->defaults([
        'controller' => 'Nj643_Cad',
        'action'     => 'index',
    ]);
