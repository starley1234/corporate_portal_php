<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj120_jobs (очередь фоновых задач).
 */

// --- Роуты: HTTP-API очереди для python-воркеров ------------------------------
Route::set('nj120_api', 'nj120/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj120_Api',
        'action'     => 'stats',
    ]);

// «Мои задачи» — задачи текущего посетителя из всех модулей;
// /tasks/<code> — страница одной задачи по коду артефакта (ссылки из
// уведомлений nj597 вида /tasks/cmp17e47f29 больше не 404)
Route::set('nj120_tasks', 'tasks(/<code>)')
    ->defaults([
        'controller' => 'Nj120_Tasks',
        'action'     => 'index',
    ]);

// «Ресурсы и потребление» — управление: отчёты воркеров о ресурсах (nj120/004)
Route::set('nj120_usage', 'nj120/usage')
    ->defaults([
        'controller' => 'Nj120_Usage',
        'action'     => 'index',
    ]);

// «Бюджеты токенов» — управление: лимиты по ролям и владельцам (nj120/006)
Route::set('nj120_budgets', 'nj120/budgets')
    ->defaults([
        'controller' => 'Nj120_Budgets',
        'action'     => 'index',
    ]);

// «Документация воркеров» — управление: автосправка по обработчикам (mermaid-схемы)
Route::set('nj120_workers', 'nj120/workers')
    ->defaults([
        'controller' => 'Nj120_Workers',
        'action'     => 'index',
    ]);

// «Задача» — управление: одна задача очереди с журналом событий-прогресса
Route::set('nj120_task', 'nj120/task(/<id>)', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj120_Task',
        'action'     => 'index',
    ]);

// «Монитор воркеров» — управление: очередь/пульс/управление задачами
Route::set('nj120_monitor', 'nj120/monitor')
    ->defaults([
        'controller' => 'Nj120_Monitor',
        'action'     => 'index',
    ]);
