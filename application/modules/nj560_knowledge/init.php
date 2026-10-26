<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj560_knowledge (база знаний, комбинированный RAG).
 */

// Страница управления базы знаний и действия (очистка, удаление)
Route::set('nj560_knowledge', 'nj560(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Nj560_Knowledge',
        'action'     => 'index',
    ]);

// Таблица: nj_560_docs (миграция 001). Векторы — коллекция nj560_knowledge в nj115.
