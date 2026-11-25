<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx398_doc_router_api', 'api/v1/document-routes(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx398_Api', 'action' => 'index']);

Route::set('prx398_worker_health', 'prx398_doc_router/worker/health')
    ->defaults(['controller' => 'Prx398_Worker', 'action' => 'health']);
Route::set('prx398_worker', 'prx398_doc_router/worker/<id>/<action>', [
    'id' => '[1-9][0-9]{0,17}', 'action' => 'file|result',
])->defaults(['controller' => 'Prx398_Worker']);

Route::set('prx398_doc_router', 'prx398_doc_router(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx398_Ui', 'action' => 'index']);
