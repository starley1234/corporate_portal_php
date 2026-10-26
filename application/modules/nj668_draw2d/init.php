<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj668_draw2d («Плоские иллюстрации»).
 *
 *   GET      /draw2d                 витрина: описание и лучшие published;
 *   GET|POST /draw2d/create          новая иллюстрация (текст и/или фото);
 *   GET      /draw2d/projects        мои работы;
 *   GET      /draw2d/catalog         публичный каталог;
 *   GET|POST /draw2d/ideas           пресеты идей (nj668.moderate);
 *   GET|POST /draw2d/moderate        панель модератора (nj668.moderate);
 *   GET      /draw2d/p/<code>        рабочее место: SVG-холст, ревизии;
 *   GET      /draw2d/c/<code>        страница просмотра;
 *   POST     /draw2d/api/*           JSON API (аккаунт + CSRF);
 *   GET      /draw2d/api/preview     SVG превью сцены;
 *   GET      /draw2d/api/ideas       три случайных пресета;
 *   POST     /draw2d/w/*             приёмная python-воркера (токен).
 *
 * Сборка сцены идёт в воркере (очередь nj120, канал nj668.generate).
 * Код проекта — строчные буквы и цифры без 0/1.
 */

Route::set('nj668_page', 'draw2d')
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'index',
	]);

Route::set('nj668_create', 'draw2d/create')
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'create',
	]);

Route::set('nj668_projects', 'draw2d/projects')
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'projects',
	]);

Route::set('nj668_catalog', 'draw2d/catalog')
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'catalog',
	]);

Route::set('nj668_ideas', 'draw2d/ideas')
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'ideas',
	]);

Route::set('nj668_moderate', 'draw2d/moderate')
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'moderate',
	]);

Route::set('nj668_project', 'draw2d/p/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'project',
	]);

Route::set('nj668_public', 'draw2d/c/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj668_Draw2d',
		'action'     => 'view',
	]);

Route::set('nj668_api_create', 'draw2d/api/create')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'create']);

Route::set('nj668_api_generate', 'draw2d/api/generate')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'generate']);

Route::set('nj668_api_save', 'draw2d/api/save')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'save']);

Route::set('nj668_api_publish', 'draw2d/api/publish')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'publish']);

Route::set('nj668_api_delete', 'draw2d/api/delete')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'delete']);

Route::set('nj668_api_status', 'draw2d/api/status')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'status']);

Route::set('nj668_api_preview', 'draw2d/api/preview')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'preview']);

Route::set('nj668_api_ideas', 'draw2d/api/ideas')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'ideas']);

Route::set('nj668_api_moderate', 'draw2d/api/moderate')
	->defaults(['controller' => 'Nj668_Api', 'action' => 'moderate']);

Route::set('nj668_w_context', 'draw2d/w/context')
	->defaults(['controller' => 'Nj668_Worker', 'action' => 'context']);

Route::set('nj668_w_scene', 'draw2d/w/scene')
	->defaults(['controller' => 'Nj668_Worker', 'action' => 'scene']);

Route::set('nj668_w_fail', 'draw2d/w/fail')
	->defaults(['controller' => 'Nj668_Worker', 'action' => 'fail']);

Route::set('nj668_w_ideas', 'draw2d/w/ideas')
	->defaults(['controller' => 'Nj668_Worker', 'action' => 'ideas']);
