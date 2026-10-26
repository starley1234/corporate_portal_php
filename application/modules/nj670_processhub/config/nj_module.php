<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест ProcessHub (nj670_processhub) для nj110_catalog и реестров
 * платформы.
 *
 * Пять каналов очереди: сборка плана, разбор журнала (факт), AI-аудит и
 * выпуск регламента. reconcile — «результат виден на сайте», его читает
 * монитор /tasks: пока FALSE, задача считается работающей.
 */
return [
	'id'       => 670,
	'slug'     => 'process',
	'title'    => 'ProcessHub',
	'short'    => 'Живая онтологическая карта процессов: текст регламента превращается в схему «План», журнал событий — в «Факт», AI-аудит показывает разрывы, потери и выпускает регламент DOCX/PDF.',
	'icon'     => 'fas fa-diagram-project',
	'href'     => '/process',
	'group'    => 'Бизнес',
	'sort'     => 670,
	'created_at' => '2026-10-19',
	'updated_at' => '2026-10-21',
	'auth'     => FALSE,
	'keywords' => 'bpmn процесс регламент онтология схема план факт process mining аудит разрыв узкое место raci документ',
	'api'      => ['/process/api'],
	'admin'    => ['title' => 'ProcessHub · админ', 'href' => '/process/admin'],

	'worker' => [
		'channels' => [
			'nj670.synthesize' => [
				'label'     => 'ProcessHub · схема по тексту (план / AI-достройка)',
				'url'       => '/process',
				'reconcile' => "SELECT 1 FROM nj_670_versions v
					WHERE v.id = CAST(j.payload->>'version_id' AS bigint)
					  AND v.job_id = j.id
					  AND v.status IN ('in_review', 'active', 'error')",
			],
			'nj670.mine' => [
				'label'     => 'ProcessHub · разбор журнала событий (факт)',
				'url'       => '/process',
				'reconcile' => "SELECT 1 FROM nj_670_versions v
					WHERE v.id = CAST(j.payload->>'version_id' AS bigint)
					  AND v.job_id = j.id
					  AND v.status IN ('active', 'in_review', 'error')",
			],
			'nj670.diff' => [
				'label'     => 'ProcessHub · AI-аудит расхождений плана и факта',
				'url'       => '/process',
				'reconcile' => "SELECT 1 FROM nj_670_audits a
					WHERE a.id = CAST(j.payload->>'audit_id' AS bigint)
					  AND a.job_id = j.id
					  AND a.status IN ('done', 'error')",
			],
			'nj670.export' => [
				'label'     => 'ProcessHub · выпуск регламента DOCX/PDF',
				'url'       => '/process',
				'reconcile' => "SELECT 1 FROM nj_670_reports r
					WHERE r.id = CAST(j.payload->>'report_id' AS bigint)
					  AND r.job_id = j.id
					  AND r.status IN ('done', 'error')",
			],
		],
	],

	'notify' => [
		'events' => [
			'nj670.audit.done' => [
				'title'       => 'AI-аудит процесса готов',
				'description' => 'Найдены расхождения плана и факта; открывайте отчёт и регламент.',
				'category'    => 'content',
				'icon'        => 'fas fa-scale-balanced',
				'channels'    => 'in_app,email',
				'roles'       => 'login',
				'digest'      => TRUE,
				'placeholders'=> 'title,summary,href',
			],
			'nj670.report.done' => [
				'title'       => 'Регламент процесса сформирован',
				'description' => 'DOCX и PDF лежат на странице процесса.',
				'category'    => 'content',
				'icon'        => 'fas fa-file-word',
				'channels'    => 'in_app,email',
				'roles'       => 'login',
				'digest'      => TRUE,
				'placeholders'=> 'title,href',
			],
		],
		'templates' => [
			'nj670.audit.done' => [
				'email' => [
					'subject' => 'ProcessHub: аудит «{{title}}» готов',
					'format'  => 'text',
					'body'    => "Аудит процесса завершён.\n\n{{summary}}\n\nОткрыть: {{site_url}}{{href}}",
				],
			],
			'nj670.report.done' => [
				'email' => [
					'subject' => 'ProcessHub: регламент «{{title}}» готов',
					'format'  => 'text',
					'body'    => "Регламент сформирован в DOCX и PDF.\n\nСкачать: {{site_url}}{{href}}",
				],
			],
		],
	],

	'cleanup' => [
		'nj_670_imports' => [
			'label'      => 'ProcessHub · импорты журналов',
			'note'       => 'Записи старше 90 дней; графы «Факт» остаются, файл источника удаляется вместе со строкой.',
			'days'       => 90,
			'count_sql'  => 'SELECT count(*) FROM nj_670_imports WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_670_imports WHERE id IN (
				SELECT id FROM nj_670_imports WHERE created_at < :old LIMIT :limit)',
		],
		'nj_670_ontology_jobs' => [
			'label'      => 'ProcessHub · разборы документов',
			'note'       => 'Предложения онтологии старше 90 дней. Принятые сущности и процессы остаются — удаляется только текст источника.',
			'days'       => 90,
			'count_sql'  => 'SELECT count(*) FROM nj_670_ontology_jobs WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_670_ontology_jobs WHERE id IN (
				SELECT id FROM nj_670_ontology_jobs WHERE created_at < :old LIMIT :limit)',
		],
		'nj_670_events' => [
			'label'      => 'ProcessHub · журнал действий',
			'note'       => 'События модуля старше 180 дней; процессы и версии не трогаем.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_670_events WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_670_events WHERE id IN (
				SELECT id FROM nj_670_events WHERE created_at < :old LIMIT :limit)',
		],
		'nj_670_reports' => [
			'label'      => 'ProcessHub · старые регламенты',
			'note'       => 'Сформированные файлы старше 60 дней. Сначала удалите их со страницы процесса: здесь снимается только строка.',
			'days'       => 60,
			'count_sql'  => 'SELECT count(*) FROM nj_670_reports WHERE created_at < now() - :old AND status = \'done\'',
			'delete_sql' => 'DELETE FROM nj_670_reports WHERE id IN (
				SELECT id FROM nj_670_reports WHERE created_at < :old AND status = \'done\' LIMIT :limit)',
		],
	],
];
