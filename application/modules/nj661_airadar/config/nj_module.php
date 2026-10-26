<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест NJ-661 «ИИ-радар» для каталога nj110.
 *
 * Личный инструмент владельца (audience admin): конвейер следит за прогрессом
 * ИИ — открытые модели, фреймворки, окна возможностей (бесплатные токены и
 * т.п.) — копит базу знаний из фактов и присылает письма с отчётом и идеями,
 * что реализовать на n2j. Конвейер — python-воркер nj661.airadar (как nj605),
 * страницы и письмо — PHP.
 */
return [
	'id'        => 661,
	'slug'      => 'airadar',
	'title'     => 'ИИ-радар',
	'short'     => 'Следит за прогрессом ИИ: открытые модели и фреймворки, окна возможностей, прорывы. Копит базу знаний из фактов, присылает письма с отчётом и идеями, что применить на n2j',
	'icon'      => 'fas fa-satellite-dish',
	'href'      => '/airadar',
	'card_view' => 'nj661/card',
	'group'     => 'Разработчику',
	'sort'      => 661,
	'pin'       => TRUE,
	'badge'     => 'новое',
	'audience'  => 'admin',
	'keywords'  => 'ии радар прогресс искусственный интеллект новости открытые модели llm open source фреймворк бесплатные токены окно возможностей база знаний дайджест отчет',
	'api'       => ['/airadar/api/tick'],

	// чистка: прогоны и материалы стареют, база знаний (факты/идеи) — навсегда
	'cleanup'   => [
		'nj_661_runs' => [
			'label'      => 'nj661 · прогоны конвейера ИИ-радара',
			'note'       => 'Завершённые прогоны старше 180 дней (отчёты остаются).',
			'days'       => 180,
			'count_sql'  => "SELECT count(*) FROM nj_661_runs WHERE status IN ('done','fail') AND created_at < now() - :old",
			'delete_sql' => 'DELETE FROM nj_661_runs WHERE id IN (
						SELECT id FROM nj_661_runs WHERE status IN (\'done\',\'fail\') AND created_at < now() - :old LIMIT :limit)',
		],
		'nj_661_items' => [
			'label'      => 'nj661 · материалы радара (не избранные)',
			'note'       => 'Проанализированные материалы старше 365 дней; избранные и база знаний остаются.',
			'days'       => 365,
			'count_sql'  => 'SELECT count(*) FROM nj_661_items WHERE starred = FALSE AND created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_661_items WHERE id IN (
						SELECT id FROM nj_661_items WHERE starred = FALSE AND created_at < now() - :old LIMIT :limit)',
		],
	],
];
