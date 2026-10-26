<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj130_agents (LLM-агенты).
 */

// --- Роуты -------------------------------------------------------------------
Route::set('nj130_api', 'nj130/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj130_Api',
        'action'     => 'agents',
    ]);

// GET /nj130/api/run/<id> — конфликтует с POST /nj130/api/run, поэтому
// статус читается через /nj130/api/status/<id> (и по совместимости /nj130/api/run_status/<id>).
Route::set('nj130_api_status', 'nj130/api/status/<id>', ['id' => '\d+'])
    ->defaults([
        'controller' => 'Nj130_Api',
        'action'     => 'status',
    ]);

// --- Очередь -------------------------------------------------------------------
// Канал agent.run — stateless: в payload уезжает конфиг агента и история,
// воркер возвращает reply; результат применяется при чтении статуса (sync_run).
