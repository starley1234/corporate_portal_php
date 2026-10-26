<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфигурация NJ-659 «Вход через Яндекс» (OAuth 2.0, authorization code).
 *
 * Приложение создаётся на oauth.yandex.ru; там же указывается redirect URI:
 *   https://<домен>/auth/yandex/callback
 *
 * Секреты — только через .env (YANDEX_OAUTH_*), никогда не в конфиге.
 * Пока client_id/secret не заданы, кнопка на странице входа не показывается,
 * а /auth/yandex отвечает служебной заглушкой.
 */
return [

	'client_id'     => (string) (getenv('YANDEX_OAUTH_CLIENT_ID') ?: ''),
	'client_secret' => (string) (getenv('YANDEX_OAUTH_CLIENT_SECRET') ?: ''),
	'redirect_uri'  => (string) (getenv('YANDEX_OAUTH_REDIRECT_URI') ?: ''),

	// Эндпоинты Яндекса (меняются редко; вынесены для тестируемости).
	'authorize_url' => 'https://oauth.yandex.ru/authorize',
	'token_url'     => 'https://oauth.yandex.ru/token',
	'info_url'      => 'https://login.yandex.ru/info',

	// Таймаут внешних запросов, сек (вход не должен подвешивать страницу).
	'timeout' => 10,

	// Создавать ли аккаунт при первом входе (FALSE — только связывать
	// существующие по подтверждённому email).
	'autocreate' => TRUE,
];
