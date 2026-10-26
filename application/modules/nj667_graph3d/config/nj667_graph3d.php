<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Конфиг NJ-667 «Графовый 3D-конструктор» (nj667_graph3d).
 *
 * Описание на естественном языке → модель строит топологию (узлы и ребра) →
 * браузер показывает её на WebGL (three.js, вендор в assets/vendor/three/mod)
 * и даёт править руками: тягать узлы, достраивать выделенное промптом,
 * откатываться по истории (RFC 6902) и публиковать в каталог.
 *
 * Секреты и адреса — только через .env (NJ667_* для воркера). Здесь то, что
 * читает сайт: лимиты, узлы/типы ребер, справочники и умолчания.
 */
return [

	// --- Геометрия холста (в тех же единицах, что ждём от модели) -------------
	// Модели проще считать «по клеткам», чем в метрах: центр сцены в (0, 0, 0),
	// типичный разлёт узлов — ±20. Масштаб для показа задаёт браузер.
	'canvas' => [
		'center'   => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
		'radius'   => (float) (getenv('NJ667_CANVAS_RADIUS') ?: 24.0),
		'snap'     => (float) (getenv('NJ667_SNAP') ?: 0.5),      // шаг магнитной сетки
		'snap_px'  => (int) (getenv('NJ667_SNAP_PX') ?: 8),       // радиус прилипания к осям
	],

	// --- Лимиты графа (AI-Guard проверяет их перед публикацией) ---------------
	'limits' => [
		'max_nodes'     => (int) (getenv('NJ667_MAX_NODES') ?: 500),
		'max_edges'     => (int) (getenv('NJ667_MAX_EDGES') ?: 1200),
		'max_label'     => 80,
		'max_summary'   => (int) (getenv('NJ667_MAX_SUMMARY') ?: 4000),
		'max_prompt'    => (int) (getenv('NJ667_MAX_PROMPT') ?: 1000),
		'max_isolated'  => 0,      // сколько «висячих» узлов допускаем
	],

	// --- Квоты пользователей (без оплаты/тарифной интеграции) ------------------
	// Все лимиты разрешаются через Service_Nj667_Graph3d::quota_limits().
	// Для будущих тарифов достаточно добавить профиль и привязать user_id в
	// user_plans (либо заменить резолвер интеграцией подписок); значений
	// неописанных тарифов здесь намеренно нет.
	'quotas' => [
		'default_plan' => 'registered',
		'user_plans'   => [],
		'plans' => [
			'registered' => [
				'llm_operations_per_day' => 5,
				'active_projects'        => 5,
				'historical_revisions'   => 3,
				'build_parts_per_run'    => 30,
			],
		],
	],

	// --- Что показываем на узле (type → цвет и форма) -------------------------
	// Типы намеренно те же, что предлагаем модели: hub — центр тяжести
	// конструкции, connector — промежуточное звено, endpoint — край (ножка, ручка).
	'node_types' => [
		'hub'       => ['title' => 'Узел-центр',  'color' => '#6366f1', 'shape' => 'sphere'],
		'connector' => ['title' => 'Связь',       'color' => '#0ea5e9', 'shape' => 'sphere'],
		'endpoint'  => ['title' => 'Край',        'color' => '#10b981', 'shape' => 'sphere'],
		'panel'     => ['title' => 'Плоскость',   'color' => '#f59e0b', 'shape' => 'box'],
	],

	// --- Типы ребер: rigid — жёсткая связь, hinge — шарнир, soft — гибкая -----
	'edge_types' => [
		'rigid'      => ['title' => 'Жёсткая',   'color' => '#94a3b8', 'radius' => 0.18],
		'structural' => ['title' => 'Несущая',   'color' => '#6366f1', 'radius' => 0.26],
		'hinge'      => ['title' => 'Шарнир',    'color' => '#f59e0b', 'radius' => 0.16],
		'soft'       => ['title' => 'Гибкая',    'color' => '#10b981', 'radius' => 0.14],
	],

	// --- Умолчания нового проекта ---------------------------------------------
	'defaults' => [
		'mode'      => 'translate',   // translate | rotate | scale — гизмо по умолчанию
		'grid'      => 1,             // показывать сетку
		'snap'      => 1,             // магнитная сетка включена
		// Подписи узлов на холсте мешаются (владелец, 10.10.2026): не просим
		// их у модели и не рисуем в конструкторе.
		'labels'    => 0,
	],

	// --- Частотный limiter: генерация — вызов модели ---------------------------
	'rate' => [
		'per_minute' => (int) (getenv('NJ667_RATE_PER_MINUTE') ?: 6),
		'per_hour'   => (int) (getenv('NJ667_RATE_PER_HOUR') ?: 60),
	],

	// --- Задачи воркера, секунд ----------------------------------------------
	'timeout' => [
		'generate' => (int) (getenv('NJ667_GENERATE_TIMEOUT') ?: 600),
		'inpaint'  => (int) (getenv('NJ667_INPAINT_TIMEOUT') ?: 600),
		'summary'  => (int) (getenv('NJ667_SUMMARY_TIMEOUT') ?: 300),
		'embed'    => (int) (getenv('NJ667_EMBED_TIMEOUT') ?: 300),
		'moderate' => (int) (getenv('NJ667_MODERATE_TIMEOUT') ?: 300),
		'ideas'    => (int) (getenv('NJ667_IDEAS_TIMEOUT') ?: 180),
		'caption'  => (int) (getenv('NJ667_CAPTION_TIMEOUT') ?: 180),
		// CSG в OpenSCAD — минуты, и это не LLM: лимит свой, чтобы очередь не
		// убила рендер раньше, чем тот успеет досчитать купол.
		'build'    => (int) (getenv('NJ667_BUILD_TIMEOUT') ?: 900),
	],

	// --- RAG: сколько похожих графов брать в контекст модели -------------------
	// enabled — дефолт чекбокса на странице сборки; админка /graph3d/settings
	// перекрывает. На пустом проекте модель видит 1–2 похожих; на правке
	// текущего графа примеры не кладём (владелец, 10.10.2026).
	'rag' => [
		'enabled'     => getenv('NJ667_RAG') === '0' ? 0 : 1,
		'few_shot'    => (int) (getenv('NJ667_RAG_FEW_SHOT') ?: 2),
		'neighbors'   => (int) (getenv('NJ667_RAG_NEIGHBORS') ?: 6),
		'catalog_top' => (int) (getenv('NJ667_CATALOG_TOP') ?: 24),
		'min_score'   => (float) (getenv('NJ667_RAG_MIN_SCORE') ?: 0.25),
	],

	// --- Сборка в реальное изделие (просьба владельца, 12.10.2026) --------------
	// Тип узла — это СЕЧЕНИЕ палочки и посадка: геометрия лучей одна для всех,
	// файл сечения лежит в scad/node_types/<ключ>.scad. Новый тип = один файл
	// плюс запись здесь; править core и генераторы не нужно (контракт —
	// scad/README.md).
	//
	// Единицы: координаты графа безразмерные («клетки»), поэтому сборка живёт
	// вместе с чертежом: unit + unit_size (сколько мм в клетке) приходят из
	// settings_json того же проекта.
	'build' => [
		'types' => [
			'flat' => [
				'title'  => 'Плоские палочки (от мороженого)',
				'hint'   => 'Прямоугольное сечение: 10 × 2.2 мм — типовая палочка',
				'scad'   => 'node_types/flat.scad',
				'bb'     => ['w' => 'stick_width', 't' => 'stick_thickness'],
				'params' => [
					'stick_width'     => ['title' => 'Ширина палочки, мм',     'min' => 2.0,  'max' => 60.0,  'step' => 0.1,  'def' => 10.0],
					'stick_thickness' => ['title' => 'Толщина палочки, мм',    'min' => 0.5,  'max' => 20.0,  'step' => 0.1,  'def' => 2.2],
				],
			],
			'round' => [
				'title'  => 'Круглые палочки (шкант, пруток)',
				'hint'   => 'Круглое сечение: Ø6 мм — напечатанный узел на шкант',
				'scad'   => 'node_types/round.scad',
				'bb'     => ['w' => 'stick_diameter', 't' => 'stick_diameter'],
				'params' => [
					'stick_diameter'  => ['title' => 'Диаметр палочки, мм',    'min' => 1.0,  'max' => 60.0,  'step' => 0.1,  'def' => 6.0],
				],
			],
			'cross' => [
				'title'  => 'Крестовой вал',
				'hint'   => 'Крест 3.5 мм с ребром 1.3 мм — из наработок владельца',
				'scad'   => 'node_types/cross.scad',
				'bb'     => ['w' => 'cross_size', 't' => 'cross_size'],
				'params' => [
					'cross_size'      => ['title' => 'Габарит креста, мм',      'min' => 1.0,  'max' => 60.0,  'step' => 0.1,  'def' => 3.5],
					'cross_thick'     => ['title' => 'Толщина ребра, мм',       'min' => 0.4,  'max' => 20.0,  'step' => 0.1,  'def' => 1.3],
				],
			],
			'tube' => [
				'title'  => 'Профильная труба',
				'hint'   => 'Квадрат 20 × 20 мм: узел печатается, труба покупная',
				'scad'   => 'node_types/tube.scad',
				'bb'     => ['w' => 'tube_size', 't' => 'tube_size'],
				'params' => [
					'tube_size'       => ['title' => 'Сторона трубы, мм',       'min' => 4.0,  'max' => 120.0, 'step' => 0.5,  'def' => 20.0],
					'tube_wall'       => ['title' => 'Стенка трубы, мм',        'min' => 0.4,  'max' => 8.0,   'step' => 0.1,  'def' => 1.0],
				],
			],
		],

		// Посадка и стенки одинаковы для всех типов — их правит человек, и они
		// уезжают в .scad как есть (после клампов).
		'common' => [
			'stick_insert_depth' => ['title' => 'Глубина посадки, мм', 'min' => 1.0, 'max' => 60.0, 'step' => 0.5, 'def' => 14.0],
			'tolerance'          => ['title' => 'Допуск, мм',          'min' => 0.0, 'max' => 3.0,  'step' => 0.05,'def' => 0.25],
			'wall_thickness'     => ['title' => 'Стенка луча, мм',     'min' => 0.4, 'max' => 12.0, 'step' => 0.2, 'def' => 1.0],
			'edge_r'             => ['title' => 'Скругление, мм',       'min' => 0.0, 'max' => 6.0,  'step' => 0.1, 'def' => 1.0],
			'stop_thickness'     => ['title' => 'Бортик упора, мм',    'min' => 0.2, 'max' => 10.0, 'step' => 0.1, 'def' => 2.0],
		],

		// Переключатели вида. Значения по умолчанию — как в наработках
		// владельца: перфорация включена (на печати она держит клей), палочки
		// в сборке показаны, узлы сращиваются.
		'flags' => [
			'perforation' => (int) (getenv('NJ667_BUILD_PERFORATION') ?: 0),
			'show_sticks' => (int) (getenv('NJ667_BUILD_SHOW_STICKS') ?: 1),
			'merge'       => (int) (getenv('NJ667_BUILD_MERGE_NODES') ?: 1),
		],

		// $fn: из чего состоят дуги. «Черновик» смотрите в превью, печатайте
		// на «обычном»: на 40+ гранях купол из 100 узлов рендерится минуты.
		'quality' => [
			// По умолчанию — черновик (владелец, 14.10.2026): сборка быстрая, дуги грубые.
			'low'    => ['title' => 'черновик (быстро)', 'fn' => 12],
			'medium' => ['title' => 'обычное',           'fn' => 32],
			'high'   => ['title' => 'гладко (долго)',    'fn' => 64],
		],

		// Пороги проверки собираемости (те же числа, что в наработках).
		'checks' => [
			'min_angle'  => (float) (getenv('NJ667_BUILD_MIN_ANGLE') ?: 22.0),
			'min_span'   => (float) (getenv('NJ667_BUILD_MIN_SPAN') ?: 5.0),
			'merge_gap'  => 0.5,
		],

		// Лимиты: сборка дороже правки графа, поэтому свои.
		'limits' => [
			'max_nodes'   => (int) (getenv('NJ667_BUILD_MAX_NODES') ?: 400),
			'max_edges'   => (int) (getenv('NJ667_BUILD_MAX_EDGES') ?: 1200),
			'max_stl_mb'  => (int) (getenv('NJ667_MAX_STL_MB') ?: 24),
			// 280 КБ, а не «сколько попало»: scad.py воркера отбраковывает код
			// длиннее 300 000 символов, и человек получил бы «рендер не
			// удался» вместо честного «сборка слишком большая» на странице.
			'scad_max_kb' => (int) (getenv('NJ667_SCAD_MAX_KB') ?: 280),
			// На один запуск воркера — до 30 уникальных типов узлов; верхняя
			// граница дополнительно зажимается квотой профиля пользователя.
			'parts_max'   => (int) (getenv('NJ667_BUILD_PARTS_MAX') ?: 30),
			// Секунды на всю пачку. Воркер считает вслух и бросает на середине,
			// страница тогда предлагает «доделать узлы» — недобранное видно.
			'parts_budget_s' => (int) (getenv('NJ667_BUILD_PARTS_BUDGET') ?: 3600),
			// Групп цвета в GLB: на каждый `color()` из OpenSCAD — свой
			// примитив. Обычно это «типы узлов + палочки», т.е. не больше
			// восьми-девяти. Число ОБЯЗАНО совпадать с MAX_GROUPS воркера
			// (workers/n2j_workers/glb.py): приёмка и генератор говорят одно.
			'glb_prims_max' => (int) (getenv('NJ667_GLB_PRIMS_MAX') ?: 24),
		],
	],

	// --- Пре-модерация: по умолчанию выкл, каталог сразу (владелец, 11.10.2026)
	// 1/true/on — заявка к человеку и канал nj667.moderate. Иначе «Опубликовать»
	// ставит status=published без новой ревизии и без очереди.
	'premoderate' => in_array(strtolower(trim((string) getenv('NJ667_PREMODERATE'))), ['1', 'true', 'on', 'yes'], TRUE),

	// --- Хранение: сколько дней держать черновики без дела ---------------------
	'ttl_days'    => (int) (getenv('NJ667_TTL_DAYS') ?: 180),
	'sweep_limit' => (int) (getenv('NJ667_SWEEP_LIMIT') ?: 20),

	// --- Файлы на диске (storage/nj667): отчёт и очистка админом -----------------
	// stale_days — сколько дней ждать до устаревания сборки; batch — файлов за
	// одно нажатие; orphan_min_age — секунд, прежде чем файл без строки в базе
	// считается сиротой (свежие могут ещё писаться).
	'storage' => [
		'stale_days'     => (int) (getenv('NJ667_STORAGE_STALE_DAYS') ?: 30),
		'batch'          => (int) (getenv('NJ667_STORAGE_BATCH') ?: 500),
		'orphan_min_age' => (int) (getenv('NJ667_STORAGE_ORPHAN_MIN_AGE') ?: 3600),
	],

	// --- Модели генерации (админка /graph3d/settings) -------------------------
	// Строка «провайдер::имя» через «;», как в nj662. Выбор пишется в
	// nj_667_settings и уезжает в задачу воркера полем model. Если в воркере
	// задан NJ667_MODEL, он важнее этого выбора.
	'models' => (string) (getenv('NJ667_MODELS') ?:
		'local::qwen3-coder-30b-a3b-instruct; local::qwen/qwen3.5-9b; openrouter::google/gemini-2.5-flash-lite'),

	// --- Справочники для каталога ---------------------------------------------
	'statuses' => [
		'draft'     => 'Черновик',
		'review'    => 'На модерации',
		'published' => 'Опубликован',
		'rejected'  => 'Отклонён',
	],
];
