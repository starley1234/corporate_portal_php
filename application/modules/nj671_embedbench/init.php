<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты «Стенда эмбеддингов» (nj671_embedbench).
 *
 * Порядок важен: `embedbench/api/...` объявлен ДО `embedbench/result/<id>`,
 * иначе «api» уехало бы в страницу результата как id.
 *
 *   GET  /embedbench              форма: какой набор гонять
 *   POST /embedbench/run          поставить прогон и уйти на результат
 *   GET  /embedbench/result/<id>  отчёт: два пути, метрики, решение
 *   GET  /embedbench/api/status   состояние прогона (живой статус-ряд)
 *   POST /embedbench/api/worker_result  приёмка отчёта от воркера
 */

Route::set('nj671_api', 'embedbench/api(/<action>)')
    ->defaults([
        'controller' => 'Nj671_Api',
        'action'     => 'status',
    ]);

Route::set('nj671_result', 'embedbench/result/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj671_Bench',
        'action'     => 'result',
    ]);

Route::set('nj671_page', 'embedbench(/<action>)')
    ->defaults([
        'controller' => 'Nj671_Bench',
        'action'     => 'index',
    ]);

// Таблица: nj_671_runs (миграция 001). Канал очереди: nj671.bench —
// обработчик workers/n2j_workers/handlers/nj671_embedbench.py.
