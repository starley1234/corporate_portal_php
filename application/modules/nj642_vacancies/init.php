<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj642_vacancies: «Вакансии компаний».
 *
 * /vacancies              — мои мониторы + лента вакансий;
 * /vacancies/<code>       — ОДИН монитор: живой ряд сбора, история, вакансии;
 * /vacancies/api/collect  — пиналка nj603: поставить задачи созревшим мониторам;
 * /vacancies/api/ingest   — приём вакансий от python-воркера (nj642.vacancies);
 * /vacancies/api/status/<code> — живой статус сбора (эталон /trends/<code>);
 * /vacancies/api/run, /vacancies/api/cancel, /vacancies/api/match — действия UI.
 */

Route::set('nj642_api', 'vacancies/api(/<action>(/<code>))')
    ->defaults([
        'controller' => 'Nj642_Api',
        'action'     => 'collect',
    ]);

Route::set('nj642_page', 'vacancies')
    ->defaults([
        'controller' => 'Nj642_Vacancies',
        'action'     => 'index',
    ]);

// Один монитор. Регистрируется ПОСЛЕ /vacancies/api — маршруты проверяются по
// порядку, иначе «api» попал бы в шаблон <code>.
Route::set('nj642_watch', 'vacancies/<code>', ['code' => '[0-9a-z_]+'])
    ->defaults([
        'controller' => 'Nj642_Vacancies',
        'action'     => 'watch',
    ]);
