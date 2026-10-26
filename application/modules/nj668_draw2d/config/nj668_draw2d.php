<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг NJ-668 «Плоские иллюстрации» (nj668_draw2d).
 *
 * Описание темы словами → модель собирает векторную сцену (прямые, Безье,
 * заливки) → браузер рисует SVG и даёт править руками. Секреты — NJ668_*
 * в .env. Здесь лимиты, пресеты и умолчания.
 */
return [

	'limits' => [
		'max_shapes'    => (int) (getenv('NJ668_MAX_SHAPES') ?: 120),
		'max_prompt'    => (int) (getenv('NJ668_MAX_PROMPT') ?: 800),
		'max_projects'  => (int) (getenv('NJ668_MAX_PROJECTS') ?: 200),
		'max_revisions' => (int) (getenv('NJ668_MAX_REVISIONS') ?: 80),
	],

	'rate' => [
		'per_minute' => (int) (getenv('NJ668_RATE_PER_MINUTE') ?: 6),
		'per_hour'   => (int) (getenv('NJ668_RATE_PER_HOUR') ?: 40),
	],

	'timeout' => [
		'generate' => (int) (getenv('NJ668_GENERATE_TIMEOUT') ?: 600),
		'ideas'    => (int) (getenv('NJ668_IDEAS_TIMEOUT') ?: 180),
	],

	'model' => (string) (getenv('NJ668_MODEL') ?: ''),

	'ttl_days'    => (int) (getenv('NJ668_TTL_DAYS') ?: 180),
	'sweep_limit' => (int) (getenv('NJ668_SWEEP_LIMIT') ?: 20),

	'statuses' => [
		'draft'     => 'Черновик',
		'published' => 'В каталоге',
		'rejected'  => 'Отклонена',
	],

	// Кнопки на «Создать»: готовые темы, не ответ модели.
	'presets' => [
		'Закат над тихим морем: лодка с парусом, волны Безье, тёплое солнце',
		'Домик с красной крышей в зелёном лесу, тропинка и круглое солнце',
		'Полосатый воздушный шар над холмами, облака и корзина',
		'Ночной город: окна прямоугольниками, луна кругом, река кривой',
		'Кот на подоконнике смотрит на рыбок — простые пятна и дуги',
		'Горный серпантин: зигзаг дороги, ёлки треугольниками, снежные шапки',
	],
];
