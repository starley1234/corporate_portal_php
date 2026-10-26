<?php defined('SYSPATH') or die('No direct script access.');
return [
	'id' => 669, 'slug' => 'solid3d', 'title' => '3D-детали',
	'short' => 'Одна параметрическая деталь по описанию или фото: просмотр в браузере, STL для печати, история версий и ползунки размеров.',
	'icon' => 'fas fa-cube', 'href' => '/solid3d', 'group' => 'Конструктор', 'sort' => 669,
	'auth' => FALSE, 'keywords' => '3d деталь stl glb фото параметрическая модель печать конструктор',
	'admin' => ['title' => '3D-детали · модерация', 'href' => '/solid3d/moderate'],
	'worker' => ['channels' => ['nj669.generate' => [
		'label' => '3D-детали · сборка модели', 'url' => '/solid3d/projects',
		'reconcile' => "SELECT 1 FROM nj_669_solid3d_revisions r WHERE r.id = CAST(j.payload->>'revision_id' AS bigint) AND r.status = 'done'",
	],
	'nj669.verify' => ['label' => '3D-детали · проверка ракурсов ИИ (по запросу администратора)', 'url' => '/solid3d/moderate',
		'reconcile' => "SELECT 1 FROM nj_669_solid3d_revisions r WHERE r.id = CAST(j.payload->>'revision_id' AS bigint) AND r.vlm_job_id = j.id AND r.vlm_status IN ('done','error')"],
	'nj669.export' => ['label' => '3D-детали · STL под свои размеры', 'url' => '/solid3d/projects',
		'reconcile' => "SELECT 1 FROM nj_669_solid3d_exports x WHERE x.id = CAST(j.payload->>'export_id' AS bigint) AND x.status IN ('done','error')"],
	'nj669.ideas' => ['label' => '3D-детали · идеи для создания', 'url' => '/solid3d/ideas',
		'reconcile' => "SELECT 1 FROM nj_669_solid3d_tasks t WHERE t.id=CAST(j.payload->>'task_id' AS bigint) AND t.status='done'"],
	'nj669.caption' => ['label' => '3D-детали · название, описание, теги', 'url' => '/solid3d/projects',
		'reconcile' => "SELECT 1 FROM nj_669_solid3d_tasks t WHERE t.id=CAST(j.payload->>'task_id' AS bigint) AND t.status='done'"],
	]],
	'notify' => [
		'events' => [
			'nj669.build.done' => ['title' => '3D-деталь готова', 'description' => 'GLB/STL собраны; посмотрите результат проверки ИИ.', 'category' => 'content', 'icon' => 'fas fa-cube', 'channels' => 'in_app,email', 'roles' => 'login', 'digest' => TRUE, 'placeholders' => 'title,href'],
			'nj669.build.error' => ['title' => '3D-деталь не собрана', 'description' => 'Сборка не удалась; прежняя удачная версия сохранена.', 'category' => 'content', 'icon' => 'fas fa-triangle-exclamation', 'channels' => 'in_app,email', 'roles' => 'login', 'digest' => FALSE, 'placeholders' => 'title,error,href'],
		],
		'templates' => [
			'nj669.build.done' => ['email' => ['subject' => 'Деталь готова: {{title}}', 'format' => 'text', 'body' => 'Готовы GLB и STL. Проверьте деталь перед печатью. Открыть версию: {{site_url}}{{href}}']],
			'nj669.build.error' => ['email' => ['subject' => 'Деталь не собрана: {{title}}', 'format' => 'text', 'body' => 'Причина: {{error}}. Последняя удачная версия сохранена. Открыть проект: {{site_url}}{{href}}']],
		],
	],

	'cleanup' => [
		'nj_669_solid3d_projects' => [
			'label' => 'nj669 · удалённые проекты', 'note' => 'Только удалённые более 180 дней назад; версии и журнал каскадно. Файлы удаляются при удалении проекта.', 'days' => 180,
			'count_sql' => 'SELECT count(*) FROM nj_669_solid3d_projects WHERE deleted_at IS NOT NULL AND deleted_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_669_solid3d_projects WHERE id IN (SELECT id FROM nj_669_solid3d_projects WHERE deleted_at IS NOT NULL AND deleted_at < :old LIMIT :limit)',
		],
		'nj_669_solid3d_tasks' => [
			'label' => 'nj669 · завершённые помощники', 'note' => 'Идеи сохраняются отдельно. История генераций деталей не удаляется.', 'days' => 180,
			'count_sql' => "SELECT count(*) FROM nj_669_solid3d_tasks WHERE status IN ('done','error') AND created_at < now()-:old",
			'delete_sql' => "DELETE FROM nj_669_solid3d_tasks WHERE id IN (SELECT id FROM nj_669_solid3d_tasks WHERE status IN ('done','error') AND created_at < :old LIMIT :limit)",
		],
		'nj_669_solid3d_events' => [
			'label' => 'nj669 · журнал модерации', 'note' => 'События старше 180 дней; модели и версии сохраняются.', 'days' => 180,
			'count_sql' => 'SELECT count(*) FROM nj_669_solid3d_events WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_669_solid3d_events WHERE id IN (SELECT id FROM nj_669_solid3d_events WHERE created_at < :old LIMIT :limit)',
		],
	],
];
