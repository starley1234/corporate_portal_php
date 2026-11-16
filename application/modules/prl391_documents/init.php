<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prl391_documents_api', 'prl391/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prl391_Api', 'action' => 'health']);

Route::set('prl391_documents_file', 'documents/file/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl391_Documents', 'action' => 'download']);
Route::set('prl391_documents_save', 'documents/save/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl391_Documents', 'action' => 'save']);
Route::set('prl391_documents_close', 'documents/close/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl391_Documents', 'action' => 'close']);
Route::set('prl391_documents_reopen', 'documents/reopen/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl391_Documents', 'action' => 'reopen']);
Route::set('prl391_documents_show', 'documents/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl391_Documents', 'action' => 'show']);
Route::set('prl391_documents', 'documents')
    ->defaults(['controller' => 'Prl391_Documents', 'action' => 'index']);
