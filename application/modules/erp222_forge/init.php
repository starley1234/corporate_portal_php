<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты ERP-222 «Кузница систем» (Aeterna Forge).
 *
 * Порядок важен: статические сегменты объявляются ВЫШЕ ловящих (тот же урок,
 * что у трендов и ИИ-радара: /trends/catalog не должен съедаться как код обзора,
 * /airadar/report/<id>/publish — как <id>).
 *
 *   /forge                       — дашборд: мир, живой ход цикла, свежие артефакты;
 *   /forge/worlds                — миры: список и создание;
 *   /forge/world/<code>          — мир: протокол, роли, циклы, артефакты;
 *   /forge/world/<code>/status   — пауза/продолжить (POST, CSRF);
 *   /forge/artifacts             — ПУБЛИЧНЫЙ каталог артефактов (поиск, пагинация);
 *   /forge/artifact/<id>         — страница артефакта (публичная, если опубликован);
 *   /forge/artifact/<id>/publish — публикация/снятие (POST, CSRF, владелец);
 *   /forge/progress              — JSON хода цикла (поллинг дашборда);
 *   /forge/step                  — «шаг» или «цикл из N шагов» (POST, CSRF);
 *   /forge/api/<action>          — приёмная воркера (токен воркера, как nj661).
 */

// Список миров — статический путь, поэтому ВЫШЕ маршрута мира с <code>.
Route::set('erp222_worlds', 'forge/worlds')
	->defaults([
		'controller' => 'Erp222_Worlds',
		'action'     => 'index',
	]);

// Страница мира и действия над ним.
Route::set('erp222_world', 'forge/world/<code>(/<action>)', ['code' => '[A-Za-z0-9_\-]+'])
	->defaults([
		'controller' => 'Erp222_Worlds',
		'action'     => 'view',
	]);

// Каталог артефактов и страница артефакта — ВЫШЕ ловящих маршрутов.
Route::set('erp222_artifacts', 'forge/artifacts')
	->defaults([
		'controller' => 'Erp222_Artifacts',
		'action'     => 'index',
	]);

// publish — ВЫШЕ artifact/<id>, иначе «publish» съедается как <id>.
Route::set('erp222_artifact_publish', 'forge/artifact/<id>/publish', ['id' => '\d+'])
	->defaults([
		'controller' => 'Erp222_Artifacts',
		'action'     => 'publish',
	]);

// md — скачать документ в markdown (ВЫШЕ artifact/<id>, иначе «md» съедается
// как <id>). Страница показывает документ HTML-ом, исходник нужен для работы.
Route::set('erp222_artifact_md', 'forge/artifact/<id>/md', ['id' => '\d+'])
	->defaults([
		'controller' => 'Erp222_Artifacts',
		'action'     => 'md',
	]);

Route::set('erp222_artifact', 'forge/artifact/<id>', ['id' => '\d+'])
	->defaults([
		'controller' => 'Erp222_Artifacts',
		'action'     => 'view',
	]);

// Приёмная воркера.
Route::set('erp222_api', 'forge/api(/<action>)')
	->defaults([
		'controller' => 'Erp222_Api',
		'action'     => 'tick',
	]);

// Дашборд и действия владельца.
Route::set('erp222_page', 'forge(/<action>)', ['action' => 'index|progress|step'])
	->defaults([
		'controller' => 'Erp222_Index',
		'action'     => 'index',
	]);
