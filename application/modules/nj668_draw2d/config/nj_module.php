<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Плоские иллюстрации» (nj668_draw2d) для nj110_catalog.
 *
 * /draw2d: описание темы словами → модель собирает векторную сцену
 * (прямые, кривые Безье, цветные заливки) → SVG-холст даёт править руками
 * и публиковать в каталог.
 */
return [
	'id'        => 668,
	'slug'      => 'draw2d',
	'title'     => 'Плоские иллюстрации',
	'short'     => 'Описание темы словами превращается в векторную картинку: прямые, кривые Безье и цветные заливки, которые можно править на холсте и публиковать в каталог',
	'icon'      => 'fas fa-bezier-curve',
	'href'      => '/draw2d',
	'group'     => 'Конструктор',
	'sort'      => 668,
	'pin'       => TRUE,
	'auth'      => FALSE,
	'keywords'  => 'иллюстрация вектор svg безье прямые заливка 2d рисунок конструктор холст каталог',
	'api'       => ['/draw2d/api/preview'],
	'card_view' => 'nj668/card',

	'worker' => [
		'channels' => [
			'nj668.generate' => [
				'label'     => 'Плоские иллюстрации · сборка сцены по описанию (nj668)',
				'url'       => '/draw2d',
				'reconcile' => "SELECT 1 FROM nj_668_projects p
					WHERE p.id = CAST(j.payload->>'project_id' AS bigint)
					  AND p.job_id = 0
					  AND jsonb_array_length(COALESCE(p.scene_json->'shapes', '[]'::jsonb)) > 0",
			],
			'nj668.ideas' => [
				'label'     => 'Плоские иллюстрации · идеи для сборки (nj668)',
				'url'       => '/draw2d/ideas',
				'reconcile' => "SELECT 1 FROM nj_668_idea_presets p
					WHERE p.job_id = j.id",
			],
		],
	],

	// --- Контракт «последнее»/объекты владельца (дашборд nj100, центр контроля nj600)
	'recent' => [
		'nj_668_projects' => [
			'title'  => "COALESCE(NULLIF(title, ''), code)",   // SQL-выражение заголовка
			'status' => 'status',
			'base'   => '/draw2d/p/',
			'icon'   => 'fas fa-bezier-curve',
			'where'  => 'deleted_at IS NULL',
		],
	],

	'cleanup' => [
		'nj_668_projects' => [
			'label'      => 'nj668 · иллюстрации',
			'note'       => 'Записи старше 180 дней. SVG считается на лету; кадры — storage/nj668.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_668_projects WHERE updated_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_668_projects WHERE id IN (
								SELECT id FROM nj_668_projects WHERE updated_at < :old LIMIT :limit)',
		],
		'nj_668_revisions' => [
			'label'      => 'nj668 · ревизии иллюстраций',
			'note'       => 'История правок старше 180 дней.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_668_revisions WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_668_revisions WHERE id IN (
								SELECT id FROM nj_668_revisions WHERE created_at < :old LIMIT :limit)',
		],
	],
];
