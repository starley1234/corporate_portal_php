<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj508_calendar (производственный календарь и даты).
 */

// /calendar и /calendar/2026 (страница года — отдельный адрес для поисковиков)
Route::set('nj508_page', 'calendar(/<year>)', ['year' => '[0-9]{4}'])
    ->defaults([
        'controller' => 'Nj508_Calendar',
        'action'     => 'index',
    ]);

// JSON API (интеграции и сама страница)
Route::set('nj508_api', 'nj508/api(/<action>)')
    ->defaults([
        'controller' => 'Nj508_Api',
        'action'     => 'calc',
    ]);

// Зависимости: нет — модуль stateless (данные года — config/nj508_calendar.php).
