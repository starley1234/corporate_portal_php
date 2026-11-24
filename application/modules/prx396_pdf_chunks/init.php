<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx396_pdf_chunks_api', 'api/v1/pdf-chunks(/<id>(/<action>))', [
    'id' => '[1-9][0-9]{0,17}',
    'action' => 'result',
])->defaults(['controller' => 'Prx396_Api', 'action' => 'index']);

Route::set('prx396_worker_health', 'prx396/worker/health')
    ->defaults(['controller' => 'Prx396_Worker', 'action' => 'health']);

Route::set('prx396_worker', 'prx396/worker/<id>/<action>', [
    'id' => '[1-9][0-9]{0,17}',
    'action' => 'file|plan|part|complete',
])->defaults(['controller' => 'Prx396_Worker']);

Route::set('prx396_pdf_chunks', 'prx396_pdf_chunks(/<id>(/<op>))', ['id' => '[1-9][0-9]{0,17}', 'op' => 'download'])
    ->defaults(['controller' => 'Prx396_Ui', 'action' => 'index']);
