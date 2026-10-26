<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Манифест ERP-222 «Кузница систем» для каталога nj110.
 *
 * Наработки владельца source_data/erp222_aeterna_forge (Koseven + pgvector,
 * «синтез информационных систем» группой LLM-агентов) перенесены в платформу
 * n2j и переосмыслены: агенты не «болтают о мире», а выпускают АРТЕФАКТЫ —
 * готовые к работе документы (спецификация, схема данных, контракт API,
 * алгоритм, промпт, план проверки, риски, метрики, оценка, оффер), у каждого
 * своя ценность 0–100 от ролей-Критиков.
 *
 * Дашборд и запуск циклов — владельцу (audience admin, как у ИИ-радара), а
 * каталог артефактов и страницы артефактов публичны: их читают люди и индексируют
 * поисковики (тот же приём, что у /trends/catalog и /airadar/catalog).
 */
return [
	'id'        => 222,
	'slug'      => 'forge',
	'title'     => 'Кузница систем',
	'short'     => 'Синтез информационных систем группой LLM-ролей: каждый цикл выпускает готовые артефакты — спецификацию, схему данных, контракт API, алгоритм, промпт, план проверки, риски, метрики, оценку и оффер, с оценкой ценности каждого',
	'icon'      => 'fas fa-hammer',
	'href'      => '/forge',
	'card_view' => 'erp222/card',
	'group'     => 'Разработчику',
	'sort'      => 222,
	'pin'       => TRUE,
	'badge'     => 'новое',
	'audience'  => 'admin',
	'keywords'  => 'синтез систем кузница артефакты спецификация схема данных api алгоритм промпт тестплан риски метрики оценка оффер llm агенты архитектор инженер критик',
	'api'       => ['/forge/api/tick'],

	// Канал очереди описывает владелец (nj605 — образец): монитор задач nj120
	// показывает подпись, ссылку и SQL «результат уже виден на сайте» — он нужен,
	// чтобы задача, обработанная воркером, но не закрытая, не уезжала в ретрай.
	// Здесь признак доставки: цикл закрыт воркером ИЛИ уже выпустил артефакт.
	'worker'    => [
		'channels' => [
			'erp222.forge' => [
				'label'     => 'Кузница систем (erp222)',
				'url'       => '/forge',
				'reconcile' => "SELECT 1 FROM erp_222_runs r
             WHERE r.id = CAST(j.payload->>'run_id' AS bigint)
               AND (r.finished_at IS NOT NULL
                    OR EXISTS (SELECT 1 FROM erp_222_artifacts a WHERE a.run_id = r.id))",
			],
		],
	],

	// Каталог артефактов — публичная страница (люди и поисковики). Остальные
	// адреса модуля закрыты: там прогоны, роли и настройки мира.
	'sitemap'   => ['/forge/artifacts'],

	// Чистка. Артефакты и миры — НАВСЕГДА (это и есть ценность сервиса);
	// прогоны и лента событий стареют.
	'cleanup'   => [
		'erp_222_runs' => [
			'label'      => 'erp222 · циклы синтеза',
			'note'       => 'Завершённые циклы старше 180 дней (артефакты остаются).',
			'days'       => 180,
			'count_sql'  => "SELECT count(*) FROM erp_222_runs WHERE status IN ('done','fail') AND created_at < now() - :old",
			'delete_sql' => 'DELETE FROM erp_222_runs WHERE id IN (
							SELECT id FROM erp_222_runs WHERE status IN (\'done\',\'fail\') AND created_at < now() - :old LIMIT :limit)',
		],
		'erp_222_events' => [
			'label'      => 'erp222 · лента событий циклов',
			'note'       => 'События шагов старше 180 дней (артефакты и критика остаются).',
			'days'       => 180,
			'count_sql'  => 'SELECT count(*) FROM erp_222_events WHERE created_at < now() - :old',
			'delete_sql' => 'DELETE FROM erp_222_events WHERE id IN (
							SELECT id FROM erp_222_events WHERE created_at < now() - :old LIMIT :limit)',
		],
	],
];
