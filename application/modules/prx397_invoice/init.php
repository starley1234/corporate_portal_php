<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx397_invoice_api', 'api/v1/invoice-extractions(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx397_Api', 'action' => 'index']);

Route::set('prx397_worker_health', 'prx397_invoice/worker/health')
    ->defaults(['controller' => 'Prx397_Worker', 'action' => 'health']);
Route::set('prx397_worker', 'prx397_invoice/worker/<id>/<action>', [
    'id' => '[1-9][0-9]{0,17}', 'action' => 'file|result',
])->defaults(['controller' => 'Prx397_Worker']);

Route::set('prx397_invoice', 'prx397_invoice(/<id>)', ['id' => '[1-9][0-9]{0,17}'])
    ->defaults(['controller' => 'Prx397_Ui', 'action' => 'index']);
