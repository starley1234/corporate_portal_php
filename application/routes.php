<?php defined('SYSPATH') or die('No direct script access.');





	// Статические страницы
	Route::set('static_pages', '<action>', array('action' => 'terms|privacy|about'))
	->defaults(array(
		'controller' => 'Pages',
	));


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