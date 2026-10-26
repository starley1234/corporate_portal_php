<?php defined('SYSPATH') OR die('No direct access allowed.');

/**
 * Доверенные хосты для URL::base() (защита от Host-header инъекций).
 *
 * Прод: только боевые домены. В development добавляем локальные адреса и
 * домены превью-песочниц (*.e2b.app), иначе dev-сервер на 127.0.0.1:8080
 * отвечает «Untrusted host» на любую страницу с canonical/OG-ссылкой.
 */
$hosts = [
	'.*\.local',
	'n2j.ru',
	'www.n2j.ru',
];

if (Kohana::$environment === Kohana::DEVELOPMENT)
{
	$hosts = array_merge($hosts, [
		'localhost(:\d+)?',
		'127\.0\.0\.1(:\d+)?',
		'\[::1\](:\d+)?',
		'.*\.e2b\.app(:\d+)?',
	]);
}

return array(
	'trusted_hosts' => $hosts,
);
