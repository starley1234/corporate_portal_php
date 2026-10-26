<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты NJ-658 «Города и посёлки».
 *
 * Порядок важен: адреса админки и API регистрируются ДО универсального
 * «geo(/<slug>)», иначе «geo/admin» и «geo/api/suggest» matчатся как slug.
 */

Route::set('nj658_admin_action', 'geo/admin(/<action>(/<id>))', [
	'action' => 'city|save|delete|export',
	'id'     => '\d+',
])
	->defaults([
		'controller' => 'Nj658_Admin',
		'action'     => 'index',
	]);

Route::set('nj658_admin', 'geo/admin')
	->defaults([
		'controller' => 'Nj658_Admin',
		'action'     => 'index',
	]);

Route::set('nj658_api', 'geo/api(/<action>)', [
	'action' => 'suggest|regions',
])
	->defaults([
		'controller' => 'Nj658_Api',
		'action'     => 'suggest',
	]);

// Публичный справочник: список/поиск и страница населённого пункта /geo/<slug>.
Route::set('nj658_geo', 'geo(/<slug>)', ['slug' => '[a-z0-9-]{2,60}'])
	->defaults([
		'controller' => 'Nj658_Geo',
		'action'     => 'index',
	]);
