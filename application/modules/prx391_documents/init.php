<?php defined('SYSPATH') or die('No direct script access.');

// Legacy-адрес /documents: 301 на новый /prx391_documents, чтобы старые
// закладки и письма не ломались. Сам модуль живёт под префиксом + слаг.
Route::set('prx391_documents_legacy', 'documents(/<path>)', ['path' => '[a-z0-9_/]*'])
    ->defaults(['controller' => 'Prx391_Legacy', 'action' => 'index']);

Route::set('prx391_documents_api', 'prx391_documents/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prx39x_Api', 'action' => 'health']);

Route::set('prx391_documents_file', 'prx391_documents/file/<id>', ['id' => '\\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'download']);
Route::set('prx391_documents_save', 'prx391_documents/save/<id>', ['id' => '\\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'save']);
Route::set('prx391_documents_close', 'prx391_documents/close/<id>', ['id' => '\\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'close']);
Route::set('prx391_documents_reopen', 'prx391_documents/reopen/<id>', ['id' => '\\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'reopen']);
Route::set('prx391_documents_progress', 'prx391_documents/progress/<id>', ['id' => '\\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'progress']);
Route::set('prx391_documents_show', 'prx391_documents/<id>', ['id' => '\\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'show']);
Route::set('prx391_documents', 'prx391_documents')
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'index']);
