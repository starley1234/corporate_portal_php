<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx395_extraction', 'api/v1/document-extractions(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx395_Api', 'action' => 'extraction']);
Route::set('prx395_worker_health', 'prx395/worker/health')
    ->defaults(['controller' => 'Prx395_Worker', 'action' => 'health']);
Route::set('prx395_worker', 'prx395/worker/<id>/<action>', [
    'id' => '[1-9][0-9]{0,17}', 'action' => 'file|result',
])->defaults(['controller' => 'Prx395_Worker']);

Route::set('prx395_ui', 'document-extract(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx395_Ui', 'action' => 'index']);
