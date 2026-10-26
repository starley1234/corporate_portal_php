<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj605_radar: «Радар заказов».
 *
 * /radar            — мои радары + лента находок;
 * /radar/<code>     — ОДИН радар: живой статус сбора и его находки;
 * /radar/api/collect — пиналка nj603: поставить задачи сбора в nj120;
 * /radar/api/items   — приём находок от python-воркера;
 * /radar/api/status/<code> — живой статус сбора (спиннер/этап/секундомер).
 */

// status/<code> — живой статус-ряд страницы радара (эталон /trends/<code>)
Route::set('nj605_api', 'radar/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj605_Api',
        'action'     => 'collect',
    ]);

Route::set('nj605_admin', 'radar/admin')
    ->defaults([
        'controller' => 'Nj605_Admin',
        'action'     => 'index',
    ]);

Route::set('nj605_page', 'radar')
    ->defaults([
        'controller' => 'Nj605_Radar',
        'action'     => 'index',
    ]);

// Один радар со своими находками. Регистрируется ПОСЛЕ /radar/admin: маршруты
// проверяются по порядку, иначе «admin» попал бы в шаблон <code>.
Route::set('nj605_watch', 'radar/<code>', ['code' => '[0-9a-z_]+'])
    ->defaults([
        'controller' => 'Nj605_Radar',
        'action'     => 'watch',
    ]);
