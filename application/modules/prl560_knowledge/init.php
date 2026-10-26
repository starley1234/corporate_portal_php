<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prl560_knowledge (база знаний, комбинированный RAG).
 */

// Страница управления базы знаний и действия (очистка, удаление)
Route::set('prl560_knowledge', 'prl560(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Prl560_Knowledge',
        'action'     => 'index',
    ]);

// Таблица: prl_560_docs (миграция 001). Векторы — коллекция prl560_knowledge в prl115.
