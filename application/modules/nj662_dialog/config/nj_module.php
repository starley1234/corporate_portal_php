<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест NJ-662 «Диалог двух ИИ» для каталога nj110.
 *
 * Наработки владельца source_data/100_ai_symposium (Koseven + MySQL, «Симпозиум
 * ИИ») перенесены в платформу n2j и переосмыслены:
 *   · сущность «симпозиум» убрана — единица работы это ДИАЛОГ двух агентов;
 *   · ход диалога ведёт серверная очередь (nj120 → канал nj662.dialog), а не
 *     jQuery-очередь в браузере, как было в наработках;
 *   · агенты умеют смотреть в интернет: поиск DuckDuckGo с бюджетом 0…N на
 *     диалог, чтение найденных страниц и цитаты в репликах;
 *   · материалы диалога индексируются общей семантикой платформы (nj115), как
 *     в «Лаборатории векторов» (/veclab).
 *
 * Доступ (решение владельца 09.10.2026):
 *   · читать опубликованные разговоры (каталог, страница, читательский вид
 *     /dialog/read/<id>) может кто угодно — адреса объявлены публично в карте
 *     сайта (nj602_content), как у /trends/catalog и /forge/artifacts;
 *   · ЗАПУСКАТЬ разговор (создание, продолжение, быстрый старт) — только по
 *     праву nj662.start: роль vip или админ. Обычный аккаунт видит рабочий
 *     стол своих прежних диалогов, но не тратит очередь (audience auth — как
 *     и было: без аккаунта на рабочий стол не пускает Gate).
 *   · сводка всех диалогов сайта (/dialog/all) — по праву nj662.manage (админ).
 */
return [
	'id'        => 662,
	'slug'      => 'dialog',
	'title'     => 'Диалог двух ИИ',
	'short'     => 'Два ИИ-агента обсуждают тему по делу: сервер ведёт обмен репликами, агенты при необходимости ищут в интернете, а диалог можно опубликовать как готовый разбор',
	'icon'      => 'fas fa-comments',
	'href'      => '/dialog',
	'card_view' => '662/card',
	'group'     => 'Разработчику',
	'sort'      => 662,
	'pin'       => TRUE,
	'badge'     => 'новое',
	'audience'  => 'auth',
	'keywords'  => 'диалог двух ии агенты разговор двух моделей симпозиум дебаты обсуждение llm очередь воркер поиск в интернете duckduckgo семантический индекс',
	'api'       => ['/dialog/api/tick'],

	/**
	 * Каналы очереди объявляет владелец (образец — nj605_radar): монитор задач
	 * nj120 показывает подпись, ссылку и SQL «результат уже виден на сайте».
	 * Признак доставки обмена: реплики этого хода лежат в базе. Без него задача,
	 * обработанная воркером, но не закрытая, уезжает в ретрай.
	 */
	'worker'    => [
		'channels' => [
			'nj662.dialog' => [
				'label'     => 'Диалог двух ИИ · обмен репликами (nj662)',
				'url'       => '/dialog',
				'reconcile' => "SELECT 1 FROM nj_662_messages m
             WHERE m.dialog_id = CAST(j.payload->>'dialog_id' AS bigint)
               AND m.turn = CAST(j.payload->>'turn' AS integer)",
			],
			'nj662.quick' => [
				'label'     => 'Диалог двух ИИ · подбор агентов (nj662)',
				'url'       => '/dialog',
				'reconcile' => "SELECT 1 FROM nj_662_dialogs d
             WHERE d.id = CAST(j.payload->>'dialog_id' AS bigint)
               AND d.status <> 'quick'",
			],
		],
	],

	/**
	 * Чистка: диалоги и реплики — это результат работы (как артефакты Кузницы),
	 * поэтому живут долго; прогоны поисков и лента событий стареют.
	 */
	'cleanup'   => [
		'nj_662_searches' => [
			'label'      => 'nj662 · поиски агентов в интернете',
			'note'       => 'Журнал поисков старше 180 дней (реплики со ссылками остаются).',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_662_searches WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_662_searches WHERE id IN (
                                SELECT id FROM nj_662_searches WHERE created_at < now() - :old LIMIT :limit)',
		],
		'nj_662_events' => [
			'label'      => 'nj662 · лента событий диалогов',
			'note'       => 'Лента старше 180 дней (сами диалоги и реплики остаются).',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM nj_662_events WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM nj_662_events WHERE id IN (
                                SELECT id FROM nj_662_events WHERE created_at < now() - :old LIMIT :limit)',
		],
	],
];
