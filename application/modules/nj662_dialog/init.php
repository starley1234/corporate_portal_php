<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты NJ-662 «Диалог двух ИИ».
 *
 * Порядок важен: статические сегменты объявляются ВЫШЕ ловящих (тот же урок,
 * что у трендов, ИИ-радара и Кузницы: /dialog/list не должен съедаться как
 * <id>, а /dialog/api/... — как действие страницы).
 *
 *   /dialog                       — рабочий стол: мои диалоги и создание;
 *   /dialog/new                   — создать диалог (POST, CSRF);
 *   /dialog/list                  — ПУБЛИЧНЫЙ каталог опубликованных диалогов;
 *   /dialog/all                   — сводка ВСЕХ диалогов (админ, nj662.manage);
 *   /dialog/read/<id>             — страница ЧТЕНИЯ разговора (крупный шрифт);
 *   /dialog/status/<id>           — JSON хода диалога (поллинг страницы);
 *   /dialog/api/<action>          — приёмная воркера (токен воркера);
 *   /dialog/<id>                  — страница диалога (своя — полная, чужая
 *                                   опубликованная — для чтения);
 *   /dialog/<id>/<action>         — действия над диалогом (POST, CSRF):
 *                                   start|pause|stop|say|quick|settings|
 *                                   publish|delete|knowledge_add|
 *                                   knowledge_delete|knowledge_reindex.
 */

// Каталог, сводка и приёмная — ВЫШЕ маршрутов с <id>: у них свои статические
// префиксы, иначе /dialog/all и /dialog/list читались бы как номер диалога.
Route::set('nj662_catalog', 'dialog/list')
	->defaults([
		'controller' => 'Nj662_Catalog',
		'action'     => 'index',
	]);

Route::set('nj662_all', 'dialog/all')
	->defaults([
		'controller' => 'Nj662_Admin',
		'action'     => 'index',
	]);

// Чтение — отдельная страница, а не «вид» страницы диалога: у неё свой адрес,
// его можно отправить человеку, и он не ведёт в настройки владельца.
Route::set('nj662_read', 'dialog/read/<id>', ['id' => '\d+'])
	->defaults([
		'controller' => 'Nj662_Dialog',
		'action'     => 'read',
	]);

Route::set('nj662_status', 'dialog/status/<id>', ['id' => '\d+'])
	->defaults([
		'controller' => 'Nj662_Dialog',
		'action'     => 'status',
	]);

Route::set('nj662_api', 'dialog/api(/<action>)')
	->defaults([
		'controller' => 'Nj662_Api',
		'action'     => 'tick',
	]);

// Создание диалога: свой путь, потому что POST на /dialog — это не действие
// страницы, а рабочая кнопка «начать».
Route::set('nj662_new', 'dialog/new')
	->defaults([
		'controller' => 'Nj662_Index',
		'action'     => 'create',
	]);

// Действия над диалогом: только известные имена (иначе <id>/<action> съедал бы
// опечатки и отвечал 500 вместо 404).
Route::set('nj662_dialog_action', 'dialog/<id>/<action>', [
		'id'     => '\d+',
		// stop — «стоп» (снять обмен с очереди сразу), say — своя реплика
		// владельца с продолжением (решение владельца 09.10.2026).
		'action' => 'start|pause|stop|say|quick|settings|publish|delete|knowledge_add|knowledge_delete|knowledge_reindex',
	])
	->defaults([
		'controller' => 'Nj662_Dialog',
		'action'     => 'view',
	]);

Route::set('nj662_dialog', 'dialog/<id>', ['id' => '\d+'])
	->defaults([
		'controller' => 'Nj662_Dialog',
		'action'     => 'view',
	]);

Route::set('nj662_desk', 'dialog(/<action>)', ['action' => 'index'])
	->defaults([
		'controller' => 'Nj662_Index',
		'action'     => 'index',
	]);
