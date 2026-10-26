<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты модуля nj650_receipt («Чек по фото»).
 *
 * Порядок важен: `receipt/api/...` объявлен ДО `receipt/<id>`, иначе адрес
 * API попал бы в страницу разбора как «код объекта».
 */

Route::set('nj650_api', 'receipt/api(/<action>)')
    ->defaults([
        'controller' => 'Nj650_Api',
        'action'     => 'photo',
    ]);

Route::set('nj650_page', 'receipt')
    ->defaults([
        'controller' => 'Nj650_Receipt',
        'action'     => 'index',
    ]);

Route::set('nj650_run', 'receipt/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj650_Receipt',
        'action'     => 'run',
    ]);

// Таблица: nj_650_runs (миграция 001). Канал очереди: nj650.receipt —
// обработчик workers/n2j_workers/handlers/nj650_receipt.py.
