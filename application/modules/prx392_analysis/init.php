<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prx392_analysis_api_input', 'prx392/api/input/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx392_Api', 'action' => 'input']);
Route::set('prx392_analysis_api_result', 'prx392/api/result/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx392_Api', 'action' => 'result']);
Route::set('prx392_analysis_api', 'prx392/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prx392_Api', 'action' => 'health']);

Route::set('prx392_analysis_run', 'analysis/run/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx392_Analysis', 'action' => 'run']);
Route::set('prx392_analysis_accept', 'analysis/accept/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx392_Analysis', 'action' => 'accept']);
Route::set('prx392_analysis_reject', 'analysis/reject/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prx392_Analysis', 'action' => 'reject']);
Route::set('prx392_analysis', 'analysis')
    ->defaults(['controller' => 'Prx392_Analysis', 'action' => 'index']);
