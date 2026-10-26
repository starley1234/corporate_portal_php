<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj606_leads: «Поток заказчиков».
 *
 * /leads                 — потоки + лента компаний;
 * /leads/api/collect     — пиналка nj603: задачи сбора в nj120;
 * /leads/api/companies   — приём компаний от воркера.
 */

// status/<code> — живой статус-ряд страницы потока (эталон /trends/<code>)
Route::set('nj606_api', 'leads/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj606_Api',
        'action'     => 'collect',
    ]);

Route::set('nj606_admin', 'leads/admin')
    ->defaults([
        'controller' => 'Nj606_Admin',
        'action'     => 'index',
    ]);

Route::set('nj606_page', 'leads')
    ->defaults([
        'controller' => 'Nj606_Leads',
        'action'     => 'index',
    ]);
