<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj645_llmlab («Лаборатория LLM»).
 */

Route::set('nj645_page', 'llmlab')
    ->defaults([
        'controller' => 'Nj645_Llmlab',
        'action'     => 'index',
    ]);

Route::set('nj645_api', 'llmlab/api(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj645_Api',
        'action'     => 'tokenize',
    ]);

Route::set('nj645_admin', 'nj645/admin(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj645_Admin',
        'action'     => 'index',
    ]);

// Таблицы: nj_645_* (миграция 001). Канал очереди: nj645.review —
// прогон промпта реальной LLM, обработчик workers/n2j_workers/handlers/nj645_review.py.
