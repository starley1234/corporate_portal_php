<?php defined('SYSPATH') or die('No direct script access.');

Route::set('prl392_analysis_api_input', 'prl392/api/input/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl392_Api', 'action' => 'input']);
Route::set('prl392_analysis_api_result', 'prl392/api/result/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl392_Api', 'action' => 'result']);
Route::set('prl392_analysis_api', 'prl392/api(/<action>)', ['action' => '[a-z_]+'])
    ->defaults(['controller' => 'Prl392_Api', 'action' => 'health']);

Route::set('prl392_analysis_run', 'analysis/run/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl392_Analysis', 'action' => 'run']);
Route::set('prl392_analysis_accept', 'analysis/accept/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl392_Analysis', 'action' => 'accept']);
Route::set('prl392_analysis_reject', 'analysis/reject/<id>', ['id' => '\d+'])
    ->defaults(['controller' => 'Prl392_Analysis', 'action' => 'reject']);
Route::set('prl392_analysis', 'analysis')
    ->defaults(['controller' => 'Prl392_Analysis', 'action' => 'index']);
