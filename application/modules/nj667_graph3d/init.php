<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инициализация модуля nj667_graph3d («Графовый 3D-конструктор»).
 *
 *   GET      /graph3d                  витрина: описание и лучшие published;
 *   GET|POST /graph3d/create           новая сборка;
 *   GET|POST /graph3d/projects         мои проекты;
 *   GET|POST /graph3d/catalog          публичный каталог;
 *   GET      /graph3d/p/<code>         рабочее место: 3D-холст, правка, история;
 *   GET      /graph3d/c/<code>         публичная страница графа (каталог);
 *   GET|POST /graph3d/d/<code>         чертёж с размерами (единица, масштаб, PDF);
 *   GET|POST /graph3d/b/<code>         сборка: STL, узлы, спецификация;
 *   GET|POST /graph3d/settings         модель генерации (право nj667.moderate);
 *   GET|POST /graph3d/ideas            пресеты идей для «Создать» (nj667.moderate);
 *   GET      /graph3d/moderate         панель модератора (право nj667.moderate);
 *   GET      /graph3d/usage            генерации: превью, промпт, токены (nj667.moderate);
 *   GET      /graph3d/api/ideas        три случайных пресета для «Создать»;
 *   POST     /graph3d/api/*            JSON API страницы (аккаунт + CSRF);
 *   POST     /graph3d/w/*              приёмная python-воркера (токен).
 *
 * Сборка графа, саммари, вектор и пре-модерация идут в воркере (очередь
 * nj120): вызов модели — это минуты, веб-процесс столько ждать не может.
 * STL тоже воркер: OpenSCAD считает CSG на минуты, и он живёт рядом с
 * воркером, а не с вебом (worker: nj667.build).
 * Сайт держит граф, права и приёмку; браузер — холст и историю правок.
 *
 * Код проекта — только строчные буквы и цифры без 0/1 (алфавит code_new()).
 */
Route::set('nj667_page', 'graph3d')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'index',
	]);

Route::set('nj667_create', 'graph3d/create')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'create',
	]);

Route::set('nj667_projects', 'graph3d/projects')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'projects',
	]);

Route::set('nj667_catalog', 'graph3d/catalog')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'catalog',
	]);

Route::set('nj667_project', 'graph3d/p/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'project',
	]);

Route::set('nj667_public', 'graph3d/c/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'view',
	]);

Route::set('nj667_drawing', 'graph3d/d/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'drawing',
	]);

// Буква «b» — как «d» для чертежа: коротко и не занято (урок /graph3d/d/).
Route::set('nj667_build', 'graph3d/b/<code>', ['code' => '[23456789a-z]{8,24}'])
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'build',
	]);

Route::set('nj667_limits', 'graph3d/limits')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'limits',
	]);

Route::set('nj667_settings', 'graph3d/settings')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'settings',
	]);

Route::set('nj667_ideas', 'graph3d/ideas')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'ideas',
	]);

Route::set('nj667_moderate', 'graph3d/moderate')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'moderate',
	]);

Route::set('nj667_usage', 'graph3d/usage')
	->defaults([
		'controller' => 'Nj667_Graph3d',
		'action'     => 'usage',
	]);

// --- JSON API страницы ---------------------------------------------------------
Route::set('nj667_api_create', 'graph3d/api/create')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'create']);

Route::set('nj667_api_generate', 'graph3d/api/generate')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'generate']);

Route::set('nj667_api_inpaint', 'graph3d/api/inpaint')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'inpaint']);

Route::set('nj667_api_save', 'graph3d/api/save')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'save']);

Route::set('nj667_api_history', 'graph3d/api/history')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'history']);

Route::set('nj667_api_revision', 'graph3d/api/revision')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'revision']);

Route::set('nj667_api_publish', 'graph3d/api/publish')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'publish']);

Route::set('nj667_api_moderate', 'graph3d/api/moderate')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'moderate']);

Route::set('nj667_api_caption', 'graph3d/api/caption')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'caption']);

Route::set('nj667_api_remix', 'graph3d/api/remix')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'remix']);

Route::set('nj667_api_delete', 'graph3d/api/delete')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'delete']);

Route::set('nj667_api_status', 'graph3d/api/status')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'status']);

Route::set('nj667_api_search', 'graph3d/api/search')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'search']);

Route::set('nj667_api_catalog', 'graph3d/api/catalog')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'catalog']);

Route::set('nj667_api_ideas', 'graph3d/api/ideas')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'ideas']);

// Превью графа картинкой (SVG считается на месте по узлам — без модели).
Route::set('nj667_api_preview', 'graph3d/api/preview')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'preview']);

// Сборка: воркер забирает .scad, возвращает STL (multipart) и итог.
Route::set('nj667_api_build', 'graph3d/api/build')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'build']);

Route::set('nj667_api_build_status', 'graph3d/api/build_status')
	->defaults(['controller' => 'Nj667_Api', 'action' => 'build_status']);

// --- Приёмная воркера (токен X-Worker-Token) ------------------------------------
Route::set('nj667_w_context', 'graph3d/w/context')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'context']);

Route::set('nj667_w_graph', 'graph3d/w/graph')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'graph']);

Route::set('nj667_w_summary', 'graph3d/w/summary')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'summary']);

Route::set('nj667_w_embed', 'graph3d/w/embed')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'embed']);

Route::set('nj667_w_moderation', 'graph3d/w/moderation')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'moderation']);

Route::set('nj667_w_ideas', 'graph3d/w/ideas')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'ideas']);

Route::set('nj667_w_caption', 'graph3d/w/caption')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'caption']);

Route::set('nj667_w_build', 'graph3d/w/build')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'build']);

Route::set('nj667_w_build_file', 'graph3d/w/build_file')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'build_file']);

Route::set('nj667_w_build_result', 'graph3d/w/build_result')
	->defaults(['controller' => 'Nj667_Worker', 'action' => 'build_result']);

// --- Зависимости -----------------------------------------------------------------
// erp100_base (шаблон страницы, Gate), nj120_jobs (очередь и приёмная воркера),
// nj100_platform (Visitor, limiter, чистка), nj110_catalog (манифест),
// nj111_migrations. Своих таблиц четыре: проекты, ревизии, журнал, настройки.
