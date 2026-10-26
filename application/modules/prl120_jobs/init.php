<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prl120_jobs (очередь фоновых задач).
 */

// --- Роуты: HTTP-API очереди для python-воркеров ------------------------------
Route::set('prl120_api', 'prl120/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Prl120_Api',
        'action'     => 'stats',
    ]);

// «Мои задачи» — задачи текущего посетителя из всех модулей;
// /tasks/<code> — страница одной задачи по коду артефакта (ссылки из
// уведомлений prl597 вида /tasks/cmp17e47f29 больше не 404)
Route::set('prl120_tasks', 'tasks(/<code>)')
    ->defaults([
        'controller' => 'Prl120_Tasks',
        'action'     => 'index',
    ]);

// «Ресурсы и потребление» — управление: отчёты воркеров о ресурсах (prl120/004)
Route::set('prl120_usage', 'prl120/usage')
    ->defaults([
        'controller' => 'Prl120_Usage',
        'action'     => 'index',
    ]);

// «Бюджеты токенов» — управление: лимиты по ролям и владельцам (prl120/006)
Route::set('prl120_budgets', 'prl120/budgets')
    ->defaults([
        'controller' => 'Prl120_Budgets',
        'action'     => 'index',
    ]);

// «Документация воркеров» — управление: автосправка по обработчикам (mermaid-схемы)
Route::set('prl120_workers', 'prl120/workers')
    ->defaults([
        'controller' => 'Prl120_Workers',
        'action'     => 'index',
    ]);

// «Задача» — управление: одна задача очереди с журналом событий-прогресса
Route::set('prl120_task', 'prl120/task(/<id>)', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Prl120_Task',
        'action'     => 'index',
    ]);

// «Монитор воркеров» — управление: очередь/пульс/управление задачами
Route::set('prl120_monitor', 'prl120/monitor')
    ->defaults([
        'controller' => 'Prl120_Monitor',
        'action'     => 'index',
    ]);
