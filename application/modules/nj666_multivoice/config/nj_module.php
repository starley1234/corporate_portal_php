<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест сервиса «Многоголосая озвучка» (nj666_multivoice) для nj110_catalog.
 *
 * Страница /multivoice: большой текст → книга, где у каждого персонажа свой
 * голос. Модель разбирает книгу по ходу и держит список персонажей, чтобы не
 * плодить двойников; владелец правит голоса и ставит замочек; блоки режутся
 * по заданному диапазону минут с логическим завершением; готовое скачивается
 * одним архивом (project/ и production/).
 */
return [
	'id'        => 666,
	'slug'      => 'multivoice',
	'title'     => 'Многоголосая озвучка',
	'short'     => 'Большой текст озвучивают разные голоса: модель находит персонажей, не путает их по ходу книги, режет текст на блоки по заданной длине и собирает архив',
	'icon'      => 'fas fa-users-between-lines',
	'href'      => '/multivoice',
	'group'     => 'Текст',
	'sort'      => 666,
	'pin'       => TRUE,
	'auth'      => FALSE,
	'keywords'  => 'озвучка книги многоголосая аудиокнига персонажи голоса text to speech большая книга главами',
	'api'       => ['/multivoice/api/voices', '/multivoice/api/create', '/multivoice/api/plan'],
	'card_view' => 'nj666/card',

	// --- Пятый контракт: каналы воркера (/nj120/monitor) -----------------------
	// Условие дожима отвечает на вопрос «задача真的 сделала своё дело?»: книга
	// вышла из состояния «разбирается» / «озвучивается» — значит повторять нечего,
	// даже если сам воркер не успел отчитаться.
	'worker' => [
		'channels' => [
			'nj666.plan' => [
				'label'     => 'Многоголосая озвучка · разбор книги (nj666)',
				'url'       => '/multivoice',
				'reconcile' => "SELECT 1 FROM nj_666_books b
					WHERE b.id = CAST(j.payload->>'book_id' AS bigint)
					  AND b.status <> 'planning'",
			],
			'nj666.voice' => [
				'label'     => 'Многоголосая озвучка · озвучка блоков (nj666)',
				'url'       => '/multivoice',
				'reconcile' => "SELECT 1 FROM nj_666_books b
					WHERE b.id = CAST(j.payload->>'book_id' AS bigint)
					  AND b.status <> 'voicing'",
			],
		],
	],

	// --- Пятый контракт: чистка данных (/nj100/cleanup) -----------------------
	// Книги старше 30 дней. Файлы (storage/nj666/<код>) убирает
	// Service_Nj666_Multivoice::sweep() — он же стирает каталоги удалённых книг.
	'cleanup' => [
		'nj_666_books' => [
			'label'      => 'nj666 · книги на озвучку',
			'note'       => 'Записи старше 30 дней. Аудиофайлы и текст книги выметает sweep() модуля.',
			'days'       => 30,
			'count_sql'  => 'SELECT count(*) FROM nj_666_books WHERE updated_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_666_books WHERE id IN (
								SELECT id FROM nj_666_books WHERE updated_at < :old LIMIT :limit)',
		],
	],
];
