<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj609_watchdog: «Сторож контрагентов».
 *
 * /watchdog                 — мои контрагенты + лента событий;
 * /watchdog/api/collect     — пиналка nj603: задачи проверки в nj120;
 * /watchdog/api/events      — приём событий от воркера;
 * /watchdog/api/status/<code> — живой статус-ряд проверки контрагента (эталон /trends).
 */

Route::set('nj609_api', 'watchdog/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj609_Api',
        'action'     => 'collect',
    ]);

Route::set('nj609_admin', 'watchdog/admin')
    ->defaults([
        'controller' => 'Nj609_Admin',
        'action'     => 'index',
    ]);

Route::set('nj609_page', 'watchdog')
    ->defaults([
        'controller' => 'Nj609_Watchdog',
        'action'     => 'index',
    ]);
