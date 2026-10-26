<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj663_favicon («Генератор favicon»).
 *
 *   GET  /favicon-gen                 страница генератора;
 *   GET  /favicon-gen/p/<code>        готовый набор (файлы, превью, HTML-код);
 *   POST /favicon-gen/api/generate    собрать комплект (PNG/ICO/zip + HTML);
 *   GET  /favicon-gen/api/pack/<code> состав набора (JSON);
 *   GET  /favicon-gen/api/sizes       справочник размеров и умолчаний;
 *   GET  /favicon-gen/f/<code>/<name> сам файл.
 *
 * Почему адрес не /favicon: в public_html/favicon/ лежат иконки самого сайта,
 * а /favicon.ico отдаёт nginx. Префикс /favicon-gen не пересекается ни с тем,
 * ни с другим.
 *
 * Внимание к шаблону <name>: в Kohana сегмент по умолчанию — [^/.,;?\n]++,
 * то есть точка в имя файла не входит. Без своего regex маршрут файла просто
 * не матчился бы на «favicon-32x32.png».
 */

Route::set('nj663_page', 'favicon-gen')
	->defaults([
		'controller' => 'Nj663_Favicon',
		'action'     => 'index',
	]);

Route::set('nj663_pack_page', 'favicon-gen/p/<code>', ['code' => '[23456789a-z]{6,24}'])
	->defaults([
		'controller' => 'Nj663_Favicon',
		'action'     => 'pack',
	]);

Route::set('nj663_api_generate', 'favicon-gen/api/generate')
	->defaults([
		'controller' => 'Nj663_Api',
		'action'     => 'generate',
	]);

Route::set('nj663_api_sizes', 'favicon-gen/api/sizes')
	->defaults([
		'controller' => 'Nj663_Api',
		'action'     => 'sizes',
	]);

Route::set('nj663_api_pack', 'favicon-gen/api/pack/<code>', ['code' => '[23456789a-z]{6,24}'])
	->defaults([
		'controller' => 'Nj663_Api',
		'action'     => 'pack',
	]);

Route::set('nj663_file', 'favicon-gen/f/<code>/<name>', [
	'code' => '[23456789a-z]{6,24}',
	'name' => '[A-Za-z0-9._-]{4,40}',
])
	->defaults([
		'controller' => 'Nj663_Api',
		'action'     => 'file',
	]);

// --- Зависимости --------------------------------------------------------------
// erp100_base (шаблон страницы), nj100_platform (rate limit, Visitor),
// nj110_catalog (манифест), nj111_migrations. Очередь и воркеры не нужны:
// комплект считает локальный GD за доли секунды.
