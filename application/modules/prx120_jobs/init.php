<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prx120_jobs (очередь фоновых задач).
 */

// --- Роуты: HTTP-API очереди для python-воркеров ------------------------------
Route::set('prx120_api', 'prx120/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Prx120_Api',
        'action'     => 'stats',
    ]);

// «Мои задачи» — задачи текущего посетителя из всех модулей;
// /tasks/<code> — страница одной задачи по коду артефакта (ссылки из
// уведомлений prx597 вида /tasks/cmp17e47f29 больше не 404)
Route::set('prx120_tasks', 'tasks(/<code>)')
    ->defaults([
        'controller' => 'Prx120_Tasks',
        'action'     => 'index',
    ]);

// «Ресурсы и потребление» — управление: отчёты воркеров о ресурсах (prx120/004)
Route::set('prx120_usage', 'prx120/usage')
    ->defaults([
        'controller' => 'Prx120_Usage',
        'action'     => 'index',
    ]);

// «Бюджеты токенов» — управление: лимиты по ролям и владельцам (prx120/006)
Route::set('prx120_budgets', 'prx120/budgets')
    ->defaults([
        'controller' => 'Prx120_Budgets',
        'action'     => 'index',
    ]);

// «Документация воркеров» — управление: автосправка по обработчикам (mermaid-схемы)
Route::set('prx120_workers', 'prx120/workers')
    ->defaults([
        'controller' => 'Prx120_Workers',
        'action'     => 'index',
    ]);

// «Задача» — управление: одна задача очереди с журналом событий-прогресса
Route::set('prx120_task', 'prx120/task(/<id>)', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Prx120_Task',
        'action'     => 'index',
    ]);

// «Каналы очереди» — управление: сводка по каналам, лимиты, повтор упавших
Route::set('prx120_channels', 'prx120/channels')
    ->defaults([
        'controller' => 'Prx120_Channels',
        'action'     => 'index',
    ]);

// «Монитор воркеров» — управление: очередь/пульс/управление задачами
Route::set('prx120_monitor', 'prx120/monitor')
    ->defaults([
        'controller' => 'Prx120_Monitor',
        'action'     => 'index',
    ]);

// Канонический адрес главной модуля (префикс+слаг): /prx120_jobs.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx120_jobs_home', 'prx120_jobs')

    ->defaults([
        'controller' => 'Prx120_Monitor',
        'action'     => 'index',
    ]);

// Точка расширения для «Рабочего стола»: ядро спрашивает имя ACL монитора,
// не зная о модуле (docs/SKELETON-ADR.md Д2/Д7). Без модуля хука нет —
// блок «Монитор воркеров» на главной не показывается.
Prx_Hooks::on('nav.monitor_acl', function () {
    return 'prx120.monitor';
});
