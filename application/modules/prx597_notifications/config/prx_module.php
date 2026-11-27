<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Уведомления» (prx597_notifications) для prx110_catalog.
 *
 * Пятый контракт: модуль описывает себя сам — чем чаще дёргать очередь,
 * что чистить, где смотреть на ошибки доставки и какие события он сам
 * публикует (секция notify.events — её читает реестр типов событий).
 */
return [
    'audience' => 'auth',
    'acl' => 'prx597.view',
    'status' => 'stable',
	'id'       => 597,
	'slug'     => 'prx597_notifications',
	'title'    => 'Уведомления',
	'short'    => 'Центр уведомлений: колокольчик на сайте, письма, Telegram и MAX — с шаблонами, настройками и защитой от спама',
	'icon'     => 'fas fa-bell',
	'href'     => '/prx597_notifications',
	'group'    => 'Аккаунт',
	'sort'     => 596,
	'hidden'   => TRUE, // аккаунтная страница: из левого меню убрана (доступ по прямой ссылке)
	'keywords' => 'уведомления колокольчик оповещения письма телеграм max шаблоны подписки email notification',
	'api'      => ['/prx597/api/unread', '/prx597/api/outbox'],
	// раздел управления модуля: чип в шапке (только администратору)
	'admin'    => ['title' => 'Управление', 'href' => '/prx597/admin'],
	'auth'     => TRUE,

	// --- Пятый контракт: расписание пинков --------------------------------
	// Как часто дёргать собственный эндпоинт, решает владелец, а не планировщик.
	'schedule' => [
		'notifications' => [
			'label'   => 'Центр уведомлений (prx597): очередь доставки, сводки, опрос ботов',
			'every'   => '1 minute', // как прежний portal:maintain из cron
			'enabled' => TRUE,
			'url'     => '/prx597/api/outbox',
			// Исполняет bin/console schedule:run (prx103); url — для воркера с токеном.
			'command' => 'bin/console prx597:outbox',
			'payload' => [],
		],
	],

	// --- Пятый контракт: чистка своих таблиц (/prx100/cleanup) --------------
	'cleanup' => [
		'prx597_inbox' => [
			'label'      => 'prx597 · лента уведомлений (колокольчик)',
			'note'       => 'Прочитанные и архивные уведомления старше срока. Непрочитанные не трогаем.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM prx_597_notifications WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM prx_597_notifications WHERE id IN (
			                    SELECT id FROM prx_597_notifications WHERE created_at < now() - :old LIMIT :limit)',
		],
		'prx597_outbox' => [
			'label'      => 'prx597 · очередь доставки',
			'note'       => 'Отправленные, пропущенные и «мёртвые» сообщения старше срока. Ожидающие отправки не трогаем.',
			'days'       => 60,
			'count_sql'  => "SELECT count(*) FROM prx_597_outbox WHERE status IN ('sent','skipped','dead') AND created_at < now() - :old",
			'delete_sql' => "DELETE FROM prx_597_outbox WHERE id IN (
			                    SELECT id FROM prx_597_outbox WHERE status IN ('sent','skipped','dead')
			                    AND created_at < now() - :old LIMIT :limit)",
		],
		'prx597_log' => [
			'label'      => 'prx597 · журнал доставки',
			'note'       => 'Записи о попытках отправки старше срока.',
			'days'       => 60,
			'count_sql'  => 'SELECT count(*) FROM prx_597_log WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM prx_597_log WHERE id IN (
			                    SELECT id FROM prx_597_log WHERE created_at < now() - :old LIMIT :limit)',
		],
	],

	// --- Пятый контракт: источник «хвоста ошибок» (/prx100/dashboard) -------
	'health' => [
		'prx_597_outbox' => [
			'label' => 'prx597 · сообщение не дошло',
			'code'  => '"event_alias"',
			'href'  => '/prx597/admin?tab=journal',
		],
	],

	// --- Реестр типов событий: что публикует сам модуль ---------------------
	// То же объявление делают остальные модули в своих config/prx_module.php.
	// Формат: '<алиас>' => ['title', 'description', 'category', 'icon',
	//                       'channels', 'roles', 'digest', 'placeholders'].
	'notify' => [
		'events' => [
			'prx120.job.done' => [
				'title'        => 'Задача выполнена',
				'description'  => 'Задача из очереди prx120 завершилась успешно: отчёт, документ, расчёт готовы.',
				'category'     => 'jobs',
				'icon'         => 'fas fa-circle-check',
				'channels'     => 'in_app,email,telegram,max',
				'roles'        => 'login',
				'digest'       => TRUE,
				'placeholders' => 'title,code,href',
			],
			'prx120.job.failed' => [
				'title'        => 'Задача завершилась с ошибкой',
				'description'  => 'Очередь prx120 не смогла выполнить задачу — стоит посмотреть и повторить.',
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
