<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Графовый 3D-конструктор» (nj667_graph3d) для nj110_catalog.
 *
 * /graph3d: описание изделия словами → модель строит пространственный граф
 * (узлы-детали, рёбра-связи) → браузер показывает его на WebGL и даёт править
 * руками (тягать узлы, достраивать выделенное промптом, откатываться по
 * истории RFC 6902, ставить контрольные точки) → публикация в каталог с
 * пре-модерацией и «Remix как шаблон».
 *
 * Источник правды — топология (nodes/edges в jsonb), а НЕ картинка: картинку
 * всегда можно перерисовать, а пересчитать геометрию из SVG нельзя.
 */
return [
	'id'        => 667,
	'slug'      => 'graph3d',
	'title'     => 'Графовый 3D-конструктор',
	'short'     => 'Описание изделия словами превращается в пространственный граф: узлы-детали и рёбра-связи можно тягать на 3D-холсте, достраивать выделенное промптом, откатывать по истории и публиковать в каталог с пре-модерацией',
	'icon'      => 'fas fa-cube',
	'href'      => '/graph3d',
	'group'     => 'Конструктор',
	'sort'      => 667,
	'pin'       => TRUE,
	'auth'      => FALSE,
	'keywords'  => '3d граф конструктор пространственный узел связь трехмерная модель figma git rag json patch каталог модерация remix велосипед каркас',
	'api'       => ['/graph3d/api/search', '/graph3d/api/catalog'],
	'card_view' => 'nj667/card',
	// Карточка выводится на главной раньше модулей с меньшим sort (см. Catalog::home_cards).
	'home'      => TRUE,

	// --- Пятый контракт: каналы воркера (/nj120/monitor) -----------------------
	// Условие дожима отвечает на вопрос «задача правда сделала своё дело?»: у
	// проекта снят job_id — значит воркер отчитался (граф принят, саммари
	// записано, вектор посчитан), и повторять нечего.
	'worker' => [
		'channels' => [
			'nj667.generate' => [
				'label'     => 'Графовый 3D-конструктор · сборка графа по описанию (nj667)',
				'url'       => '/graph3d',
				'reconcile' => "SELECT 1 FROM nj_667_projects p
					WHERE p.id = CAST(j.payload->>'project_id' AS bigint)
					  AND p.job_id = 0
					  AND jsonb_array_length(COALESCE(p.graph_json->'nodes', '[]'::jsonb)) > 0",
			],
			'nj667.inpaint' => [
				'label'     => 'Графовый 3D-конструктор · достройка выделенного (nj667)',
				'url'       => '/graph3d',
				'reconcile' => "SELECT 1 FROM nj_667_projects p
					WHERE p.id = CAST(j.payload->>'project_id' AS bigint)
					  AND p.job_id = 0",
			],
			'nj667.summary' => [
				'label'     => 'Графовый 3D-конструктор · саммари графа (nj667)',
				'url'       => '/graph3d',
				'reconcile' => "SELECT 1 FROM nj_667_projects p
					WHERE p.id = CAST(j.payload->>'project_id' AS bigint)
					  AND p.summary <> ''",
			],
			'nj667.embed' => [
				'label'     => 'Графовый 3D-конструктор · вектор саммари (nj667)',
				'url'       => '/graph3d',
				'reconcile' => "SELECT 1 FROM nj_667_projects p
					WHERE p.id = CAST(j.payload->>'project_id' AS bigint)
					  AND p.embedding IS NOT NULL",
			],
			'nj667.moderate' => [
				'label'     => 'Графовый 3D-конструктор · пре-модерация (nj667)',
				'url'       => '/graph3d/moderate',
				'reconcile' => "SELECT 1 FROM nj_667_events e
					WHERE e.project_id = CAST(j.payload->>'project_id' AS bigint)
					  AND e.kind = 'guard'",
			],
			'nj667.ideas' => [
				'label'     => 'Графовый 3D-конструктор · идеи для сборки (nj667)',
				'url'       => '/graph3d/ideas',
				'reconcile' => "SELECT 1 FROM nj_667_idea_presets p
					WHERE p.job_id = j.id",
			],
			'nj667.caption' => [
				'label'     => 'Графовый 3D-конструктор · название и описание (nj667)',
				'url'       => '/graph3d/moderate',
				'reconcile' => "SELECT 1 FROM nj_667_projects p
					WHERE p.id = CAST(j.payload->>'project_id' AS bigint)
					  AND p.summary <> ''
					  AND p.updated_at > j.created_at",
			],
		],
	],

	// --- Пятый контракт: чистка данных (/nj100/cleanup) -----------------------
	// Проекты старше 180 дней без движения. Гостевые черновики убирает
	// Service_Nj667_Graph3d::sweep() (по ttl_days).
	// --- Контракт «последнее»/объекты владельца: лента дашборда nj100 и
	// центр контроля nj600 (/nj600/admin/owner) читают проекты отсюда ------
	'recent' => [
		'nj_667_projects' => [
			'title'  => "COALESCE(NULLIF(title, ''), code)",   // SQL-выражение заголовка
			'status' => 'status',
			'base'   => '/graph3d/p/',
			'icon'   => 'fas fa-cubes',
			'where'  => 'deleted_at IS NULL',
		],
	],

	'cleanup' => [
		'nj_667_projects' => [
			'label'      => 'nj667 · проекты графов',
			'note'       => 'Записи старше 180 дней. Превью (SVG) убирает sweep() модуля.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_667_projects WHERE updated_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_667_projects WHERE id IN (
								SELECT id FROM nj_667_projects WHERE updated_at < :old LIMIT :limit)',
		],
		'nj_667_revisions' => [
			'label'      => 'nj667 · ревизии графов',
			'note'       => 'История правок старше 180 дней. Контрольные точки моложе не трогаем.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_667_revisions WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_667_revisions WHERE id IN (
								SELECT id FROM nj_667_revisions
								WHERE created_at < :old AND kind = \'commit\' LIMIT :limit)',
		],
		'nj_667_events' => [
			'label'      => 'nj667 · журнал модерации',
			'note'       => 'Записи журнала старше 180 дней.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_667_events WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_667_events WHERE id IN (
								SELECT id FROM nj_667_events WHERE created_at < :old LIMIT :limit)',
		],
	],

	// --- Шестой контракт: уведомления из модуля ---------------------------
	// Владелец (15.10.2026): «подумай, что добавить в уведомления из модулей:
	// /trends и /graph3d добавь, настрой шаблоны». События читает реестр центра
	// уведомлений (nj597, sync_manifests), шаблоны заводятся один раз и дальше
	// правятся в /nj597/admin?tab=templates — правки администратора главнее.
	//
	// Что уведомляем: сборка графа в изделие идёт в фоне (OpenSCAD в воркере), и
	// человек ждёт её конца на другой странице. Итог сборки — готово или не
	// получилось — единственный момент, ради которого стоит звонить в колокольчик.
	// Задачи модуля (nj667.build/generate/…) в центре уже названы по-человечески,
	// см. Service_Nj597_Notify::channel_map().
	'notify' => [
		'events' => [
			'nj667.build.done' => [
				'title'        => 'Сборка изделия готова',
				'description'  => 'Модель графа собрана: STL (или GLB) отрендерен — можно смотреть и печатать.',
				'category'     => 'content',
				'icon'         => 'fas fa-cube',
				'channels'     => 'in_app,email,telegram,max',
				'roles'        => 'login',
				'digest'       => TRUE,
				'placeholders' => 'title,code,href',
			],
			'nj667.build.error' => [
				'title'        => 'Сборка изделия не удалась',
				'description'  => 'Воркер не смог отрендерить модель: причина в тексте уведомления, сборку можно повторить.',
				'category'     => 'content',
				'icon'         => 'fas fa-triangle-exclamation',
				'channels'     => 'in_app,email',
				'roles'        => 'login',
				'digest'       => FALSE,
				'placeholders' => 'title,error,href',
			],
		],

		// Тексты «из коробки»: ключ — событие, дальше канал.
		// Подстановки: {{title}}, {{error}}, {{href}}, {{site_url}}, {{site_name}},
		// {{user_name}}, {{settings_url}} (см. Service_Nj597_Render::with_globals).
		'templates' => [
			'nj667.build.done' => [
				'email' => [
					'subject' => 'Сборка готова: {{title}}',
					'format'  => 'text',
					'body'    => "Здравствуйте, {{user_name}}!\n\nИзделие собрано: {{title}}.\nМодель отрендерена — можно смотреть в браузере и забирать файл для печати.\n\nОткрыть проект: {{site_url}}{{href}}\n\nЕсли уведомления больше не нужны — отключите их здесь: {{settings_url}}",
				],
				'telegram' => [
					'subject' => '',
					'format'  => 'text',
					'body'    => "✅ Сборка готова: {{title}}\n{{site_url}}{{href}}",
				],
				'max' => [
					'subject' => '',
					'format'  => 'text',
					'body'    => "Сборка готова: {{title}}. {{site_url}}{{href}}",
				],
			],
			'nj667.build.error' => [
				'email' => [
					'subject' => 'Сборка не удалась: {{title}}',
					'format'  => 'text',
					'body'    => "Здравствуйте, {{user_name}}!\n\nИзделие «{{title}}» не собралось.\nПричина: {{error}}\n\nПроект на месте — можно поправить граф и запустить сборку заново: {{site_url}}{{href}}\n\nНастройки уведомлений: {{settings_url}}",
				],
			],
		],
	],
];
