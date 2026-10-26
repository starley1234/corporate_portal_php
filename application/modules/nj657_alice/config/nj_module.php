<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Навыки Алисы» (nj657_alice) для nj110_catalog.
 *
 * Публичная страница /alice — что это и как подключить свой диалог.
 * Управление навыками (промпты, модель, память, журнал, тестовая консоль) —
 * /alice/admin, доступ только администратору (ключ nj657.admin в acl.php).
 */
return [
	'id'        => 657,
	'slug'      => 'alice',
	'title'     => 'Навыки Алисы',
	'short'     => 'Голосовые навыки для Яндекс Алисы с ответами ИИ: вебхук, роли-пресеты, память диалога и журнал разговоров',
	'icon'      => 'fas fa-microphone-lines',
	'href'      => '/alice',
	'group'     => 'Разработчику',
	'sort'      => 657,
	'pin'       => TRUE,
	'auth'      => FALSE,
	'keywords'  => 'алиса яндекс диалоги навык голосовой помощник ассистент вебхук чат бот ии llm gpt умный дом mqtt',
	'api'       => ['/alice/webhook'],
	'card_view' => 'nj657/card',

	// Чип «Админка» в шапке раздела (виден только администратору).
	'admin' => ['title' => 'Навыки Алисы · управление', 'href' => '/alice/admin'],

	// --- Пятый контракт: чистка данных (/nj100/cleanup) -----------------------
	// Журнал разговоров растёт с каждой репликой; настройки навыков
	// (nj_657_skills) чистка не затрагивает.
	'cleanup' => [
		'nj_657_dialogs' => [
			'label'      => 'nj657 · журнал диалогов с Алисой',
			'note'       => 'Вопросы и ответы навыков старше 180 дней. Настройки навыков не затрагиваются.',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_657_dialogs WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_657_dialogs WHERE id IN (
								SELECT id FROM nj_657_dialogs WHERE created_at < :old LIMIT :limit)',
		],
	],
];
