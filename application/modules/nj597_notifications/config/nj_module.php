<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Уведомления» (nj597_notifications) для nj110_catalog.
 *
 * Пятый контракт: модуль описывает себя сам — чем чаще дёргать очередь,
 * что чистить, где смотреть на ошибки доставки и какие события он сам
 * публикует (секция notify.events — её читает реестр типов событий).
 */
return [
	'id'       => 597,
	'slug'     => 'nj597_notifications',
	'title'    => 'Уведомления',
	'short'    => 'Центр уведомлений: колокольчик на сайте, письма, Telegram и MAX — с шаблонами, настройками и защитой от спама',
	'icon'     => 'fas fa-bell',
	'href'     => '/nj597',
	'group'    => 'Аккаунт',
	'sort'     => 596,
	'hidden'   => TRUE, // аккаунтная страница: из левого меню убрана (доступ по прямой ссылке)
	'keywords' => 'уведомления колокольчик оповещения письма телеграм max шаблоны подписки email notification',
	'api'      => ['/nj597/api/unread', '/nj597/api/outbox'],
	// раздел управления модуля: чип в шапке (только администратору)
	'admin'    => ['title' => 'Управление', 'href' => '/nj597/admin'],
	'auth'     => TRUE,

	// --- Пятый контракт: расписание пинков --------------------------------
	// Как часто дёргать собственный эндпоинт, решает владелец, а не планировщик.
	'schedule' => [
		'notifications' => [
			'label'   => 'Центр уведомлений (nj597): очередь доставки, сводки, опрос ботов',
			'every'   => '5 minutes',
			'enabled' => TRUE,
			'url'     => '/nj597/api/outbox',
			'payload' => [],
		],
	],

	// --- Пятый контракт: чистка своих таблиц (/nj100/cleanup) --------------
	'cleanup' => [
		'nj597_inbox' => [
			'label'      => 'nj597 · лента уведомлений (колокольчик)',
			'note'       => 'Прочитанные и архивные уведомления старше срока. Непрочитанные не трогаем.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_597_notifications WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_597_notifications WHERE id IN (
			                    SELECT id FROM nj_597_notifications WHERE created_at < now() - :old LIMIT :limit)',
		],
		'nj597_outbox' => [
			'label'      => 'nj597 · очередь доставки',
			'note'       => 'Отправленные, пропущенные и «мёртвые» сообщения старше срока. Ожидающие отправки не трогаем.',
			'days'       => 60,
			'count_sql'  => "SELECT count(*) FROM nj_597_outbox WHERE status IN ('sent','skipped','dead') AND created_at < now() - :old",
			'delete_sql' => "DELETE FROM nj_597_outbox WHERE id IN (
			                    SELECT id FROM nj_597_outbox WHERE status IN ('sent','skipped','dead')
			                    AND created_at < now() - :old LIMIT :limit)",
		],
		'nj597_log' => [
			'label'      => 'nj597 · журнал доставки',
			'note'       => 'Записи о попытках отправки старше срока.',
			'days'       => 60,
			'count_sql'  => 'SELECT count(*) FROM nj_597_log WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_597_log WHERE id IN (
			                    SELECT id FROM nj_597_log WHERE created_at < now() - :old LIMIT :limit)',
		],
	],

	// --- Пятый контракт: источник «хвоста ошибок» (/nj100/dashboard) -------
	'health' => [
		'nj_597_outbox' => [
			'label' => 'nj597 · сообщение не дошло',
			'code'  => '"event_alias"',
			'href'  => '/nj597/admin?tab=journal',
		],
	],

	// --- Реестр типов событий: что публикует сам модуль ---------------------
	// То же объявление делают остальные модули в своих config/nj_module.php.
	// Формат: '<алиас>' => ['title', 'description', 'category', 'icon',
	//                       'channels', 'roles', 'digest', 'placeholders'].
	'notify' => [
		'events' => [
			'nj120.job.done' => [
				'title'        => 'Задача выполнена',
				'description'  => 'Задача из очереди nj120 завершилась успешно: отчёт, документ, расчёт готовы.',
				'category'     => 'jobs',
				'icon'         => 'fas fa-circle-check',
				'channels'     => 'in_app,email,telegram,max',
				'roles'        => 'login',
				'digest'       => TRUE,
				'placeholders' => 'title,code,href',
			],
			'nj120.job.failed' => [
				'title'        => 'Задача завершилась с ошибкой',
				'description'  => 'Очередь nj120 не смогла выполнить задачу — стоит посмотреть и повторить.',
				'category'     => 'jobs',
				'icon'         => 'fas fa-triangle-exclamation',
				'channels'     => 'in_app,email,telegram,max',
				'roles'        => 'login',
				'digest'       => FALSE,
				'placeholders' => 'title,error,href',
			],
			'system.message' => [
				'title'        => 'Системное сообщение',
				'description'  => 'Сообщение платформы: обслуживание, изменения, важные объявления.',
				'category'     => 'system',
				'icon'         => 'fas fa-bell',
				'channels'     => 'in_app',
				'roles'        => 'login',
				'digest'       => TRUE,
				'placeholders' => 'title,body,href',
			],
			'system.test' => [
				'title'        => 'Проверка канала',
				'description'  => 'Пробное уведомление из управления (кнопка «отправить пробное»).',
				'category'     => 'system',
				'icon'         => 'fas fa-vial',
				'channels'     => 'in_app',
				'roles'        => 'login',
				'digest'       => FALSE,
				'placeholders' => 'title,body',
			],
		],
	],
];
