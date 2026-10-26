<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj607_industry: «Карта отрасли» и «Разведка под запрос».
 *
 * /industry            — формы (авто-карта / заказ разведки) + мои отчёты;
 * /industry/<code>     — просмотр отчёта (Markdown → HTML);
 * /industry/api/*      — API воркера (progress/result/fail, X-Worker-Token);
 * /nj607/admin         — заказы разведки и авто-сборы (управление).
 */

Route::set('nj607_api', 'industry/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj607_Api',
        'action'     => 'progress',
    ]);

Route::set('nj607_admin', 'nj607/admin')
    ->defaults([
        'controller' => 'Nj607_Admin',
        'action'     => 'index',
    ]);

// Сущность онтологии — ВЫШЕ /industry/<code>, иначе «entity» съедается как код
Route::set('nj607_entity', 'industry/entity/<slug>', ['slug' => '[^/]+'])
    ->defaults([
        'controller' => 'Nj607_Industry',
        'action'     => 'entity',
    ]);

Route::set('nj607_view', 'industry/<code>', ['code' => '[0-9a-z]+'])
    ->defaults([
        'controller' => 'Nj607_Industry',
        'action'     => 'view',
    ]);

Route::set('nj607_page', 'industry')
    ->defaults([
        'controller' => 'Nj607_Industry',
        'action'     => 'index',
    ]);
