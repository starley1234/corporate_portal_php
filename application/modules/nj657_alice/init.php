<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Маршруты NJ-657 «Навыки Алисы».
 *
 * Вебхук для консоли Яндекс.Диалогов: https://n2j.ru/alice/webhook
 * (или /alice/webhook/<токен>, если у навыка задан токен вебхука).
 *
 * Порядок важен: более длинные адреса идут раньше коротких, иначе
 * «alice/admin/history» поймался бы маршрутом страницы /alice.
 */

// Вебхук с персональным токеном навыка.
Route::set('nj657_webhook_token', 'alice/webhook/<token>', ['token' => '[A-Za-z0-9]{8,64}'])
	->defaults([
		'controller' => 'Nj657_Webhook',
		'action'     => 'index',
	]);

// Общий вебхук (навык подбирается по session.skill_id или навыку по умолчанию).
Route::set('nj657_webhook', 'alice/webhook')
	->defaults([
		'controller' => 'Nj657_Webhook',
		'action'     => 'index',
	]);

// Действия админки: форма навыка, сохранение, переключение, удаление,
// журнал, тестовая консоль.
Route::set('nj657_admin_action', 'alice/admin/<action>(/<code>)', [
	'action' => 'skill|save|toggle|delete|history|test',
	'code'   => '[A-Za-z0-9]{6,12}',
])
	->defaults([
		'controller' => 'Nj657_Admin',
		'action'     => 'index',
	]);

// Список навыков (дашборд модуля).
Route::set('nj657_admin', 'alice/admin')
	->defaults([
		'controller' => 'Nj657_Admin',
		'action'     => 'index',
	]);

// Публичная страница-описание.
Route::set('nj657_page', 'alice')
	->defaults([
		'controller' => 'Nj657_Alice',
		'action'     => 'index',
	]);
