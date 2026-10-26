<?php defined('SYSPATH') or die('No direct script access.');

Route::set('nj669_solid3d_worker', 'solid3d/w/<action>', ['action' => 'context|upload|result|fail|verify_context|verify_result|verify_fail|assist_context|assist_result|assist_fail|export_context|export_upload|export_result|export_fail'])
	->defaults(['directory' => 'Nj669', 'controller' => 'Solid3dWorker']);
Route::set('nj669_solid3d_file', 'solid3d/f/<code>/<revision>/<kind>', ['code' => '[a-f0-9]{20}', 'revision' => '[0-9]+', 'kind' => 'glb|stl|scad|png|png2|png3'])
	->defaults(['directory' => 'Nj669', 'controller' => 'Solid3d', 'action' => 'file']);
Route::set('nj669_solid3d_export', 'solid3d/x/<code>/<export>', ['code' => '[a-f0-9]{20}', 'export' => '[0-9]+'])
	->defaults(['directory' => 'Nj669', 'controller' => 'Solid3d', 'action' => 'export_file']);
Route::set('nj669_solid3d_project', 'solid3d/<mode>/<code>', ['mode' => 'p|c', 'code' => '[a-f0-9]{20}'])
	->defaults(['directory' => 'Nj669', 'controller' => 'Solid3d', 'action' => 'project']);
Route::set('nj669_solid3d', 'solid3d(/<action>)', ['action' => 'create|projects|catalog|moderate|settings|limits|usage|ideas'])
	->defaults(['directory' => 'Nj669', 'controller' => 'Solid3d', 'action' => 'index']);
