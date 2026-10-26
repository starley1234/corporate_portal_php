<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Генератор favicon» (nj663_favicon) для nj110_catalog.
 *
 * Публичная страница /favicon-gen: картинка, буква или эмодзи → полный
 * комплект иконок сайта (PNG 16–512, apple-touch-icon, favicon.ico,
 * site.webmanifest, zip и готовый HTML-код). Вход не нужен.
 */
return [
	'id'        => 663,
	'slug'      => 'favicon',
	'title'     => 'Генератор favicon',
	'short'     => 'Комплект иконок сайта из картинки, буквы или эмодзи: PNG всех размеров, favicon.ico, apple-touch-icon, webmanifest и готовый HTML-код',
	'icon'      => 'fas fa-icons',
	'href'      => '/favicon-gen',
	'group'     => 'Дизайн',
	'sort'      => 663,
	'pin'       => TRUE,
	'auth'      => FALSE,
	'keywords'  => 'favicon иконка сайта генератор ico png apple-touch-icon webmanifest эмодзи буква логотип 512 32x32',
	'api'       => ['/favicon-gen/api/generate', '/favicon-gen/api/sizes', '/favicon-gen/api/pack/abc'],
	'card_view' => 'nj663/card',

	// --- Пятый контракт: чистка данных (/nj100/cleanup) -----------------------
	// Строки журнала старше 30 дней. Файлы с диска убирает
	// Service_Nj663_Favicon::sweep() — он же чистит каталоги без строки
	// (набор, который не дожил до записи в журнал).
	'cleanup' => [
		'nj_663_packs' => [
			'label'      => 'nj663 · журнал комплектов favicon',
			'note'       => 'Записи старше 30 дней. Сами файлы (storage/nj663) выметает sweep() модуля.',
			'days'       => 30,
			'count_sql'  => 'SELECT count(*) FROM nj_663_packs WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_663_packs WHERE id IN (
								SELECT id FROM nj_663_packs WHERE created_at < :old LIMIT :limit)',
		],
	],
];
