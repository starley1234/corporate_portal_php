<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx391_documents_api', 'prx391/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prx39x_Api', 'action' => 'health']);

Route::set('prx391_documents_file', 'documents/file/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'download']);
Route::set('prx391_documents_save', 'documents/save/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'save']);
Route::set('prx391_documents_close', 'documents/close/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'close']);
Route::set('prx391_documents_reopen', 'documents/reopen/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'reopen']);
Route::set('prx391_documents_show', 'documents/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'show']);
Route::set('prx391_documents', 'documents')
    ->defaults(['controller' => 'Prx391_Documents', 'action' => 'index']);
