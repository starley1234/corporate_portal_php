<?php defined('SYSPATH') or die('No direct script access.');

// Predictable entry for every connected numbered module with a UI manifest.
Route::set('module_home_alias', 'prx<module_id>_index', ['module_id' => '[0-9]{3}'])
    ->defaults(['controller' => 'ModuleHome', 'action' => 'index']);





	// Статические страницы
	Route::set('static_pages', '<action>', array('action' => 'terms|privacy|about'))
	->defaults(array(
		'controller' => 'Pages',
	));


	Route::set('portal_plan', 'portal-plan')
		->defaults(['controller' => 'Welcome', 'action' => 'portal_plan']);

	/**
	 * Set the routes. Each route must have a minimum of a name, a URI and a set of
	 * defaults for the URI.
	 */
	Route::set('default', '(<controller>(/<action>(/<id>)))')
		->defaults([
			'controller' => 'welcome',
			'action'     => 'index',
		]);
		
	
	// Универсальный маршрут
    Route::set('universal_param', '<controller>(/<action>(/<id>(/<param>)))')
		->defaults(array(
			'controller' => 'Welcome',
			'action'     => 'index',
		));