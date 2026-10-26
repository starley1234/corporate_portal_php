<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-659 «Вход через Яндекс» (OAuth 2.0, authorization code flow).
 *
 * Роуты (порядок важен):
 *   auth/yandex          — старт: redirect на oauth.yandex.ru/authorize
 *                          (гость — вход, залогиненный — привязка аккаунта);
 *   auth/yandex/callback — обмен code на token, профиль, связка/логин;
 *   auth/yandex/unlink   — отвязка (POST + CSRF; владелец или админ);
 *   nj659/admin          — диагностика связок (право nj659.yandexauth).
 *
 * Кнопку на /auth/login рисует Service_Nj659_Yandexauth::login_button()
 * (вызывается прямо из вьюхи auth/login.php — модуль может быть выключен,
 * поэтому вызов через class_exists).
 */
Route::set('nj659_yandex_start', 'auth/yandex')
	->defaults([
		'controller' => 'Nj659_Yandexauth',
		'action'     => 'start',
	]);

Route::set('nj659_yandex_callback', 'auth/yandex/callback')
	->defaults([
		'controller' => 'Nj659_Yandexauth',
		'action'     => 'callback',
	]);

Route::set('nj659_yandex_unlink', 'auth/yandex/unlink')
	->defaults([
		'controller' => 'Nj659_Yandexauth',
		'action'     => 'unlink',
	]);

Route::set('nj659_admin', 'nj659/admin')
	->defaults([
		'controller' => 'Nj659_Yandexauth',
		'action'     => 'admin',
	]);
