<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prl394_drafts_api_input', 'prl394/api/input/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Api', 'action' => 'input']);
Route::set('prl394_drafts_api_result', 'prl394/api/result/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Api', 'action' => 'result']);
Route::set('prl394_drafts_api', 'prl394/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prl394_Api', 'action' => 'health']);

Route::set('prl394_drafts_new', 'replies/new/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Replies', 'action' => 'create']);
Route::set('prl394_drafts_save', 'replies/save/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Replies', 'action' => 'save']);
Route::set('prl394_drafts_approve', 'replies/approve/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Replies', 'action' => 'approve']);
Route::set('prl394_drafts_send', 'replies/send/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Replies', 'action' => 'send']);
Route::set('prl394_drafts_reject', 'replies/reject/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Replies', 'action' => 'reject']);
Route::set('prl394_drafts_document', 'replies/document/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl394_Replies', 'action' => 'document']);
Route::set('prl394_drafts', 'replies')
    ->defaults(['controller' => 'Prl394_Replies', 'action' => 'index']);
