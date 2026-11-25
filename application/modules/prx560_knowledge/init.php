<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля prx560_knowledge (база знаний, комбинированный RAG).
 */

// Страница управления базы знаний и действия (очистка, удаление)
Route::set('prx560_knowledge', 'prx560(/<action>(/<id>))')
    ->defaults([
        'controller' => 'Prx560_Knowledge',
        'action'     => 'index',
    ]);

// Канонический адрес главной модуля (префикс+слаг): /prx560_knowledge.
// Короткий URL выше остаётся рабочим алиасом.
Route::set('prx560_knowledge_home', 'prx560_knowledge')

    ->defaults([
        'controller' => 'Prx560_Knowledge',
        'action'     => 'index',
    ]);

// Таблица: prx_560_docs (миграция 001). Векторы — коллекция prx560_knowledge в prx115.
