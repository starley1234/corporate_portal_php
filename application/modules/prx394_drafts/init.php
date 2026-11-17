<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx394_drafts_api_input', 'prx394/api/input/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Api', 'action' => 'input']);
Route::set('prx394_drafts_api_result', 'prx394/api/result/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Api', 'action' => 'result']);
Route::set('prx394_drafts_api', 'prx394/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prx394_Api', 'action' => 'health']);

Route::set('prx394_drafts_new', 'replies/new/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Replies', 'action' => 'create']);
Route::set('prx394_drafts_save', 'replies/save/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Replies', 'action' => 'save']);
Route::set('prx394_drafts_approve', 'replies/approve/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Replies', 'action' => 'approve']);
Route::set('prx394_drafts_send', 'replies/send/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Replies', 'action' => 'send']);
Route::set('prx394_drafts_reject', 'replies/reject/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Replies', 'action' => 'reject']);
Route::set('prx394_drafts_document', 'replies/document/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx394_Replies', 'action' => 'document']);
Route::set('prx394_drafts', 'replies')
    ->defaults(['controller' => 'Prx394_Replies', 'action' => 'index']);
