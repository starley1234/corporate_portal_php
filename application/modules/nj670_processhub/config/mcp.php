<?php defined('SYSPATH') or die('No direct script access.');

/**
 * MCP-инструменты ProcessHub (nj670).
 *
 * Собираются хабом nj160_mcp и отдаются агентам по POST /mcp/api/rpc.
 * Ключ персональный (Bearer n2jmcp_…): владелец ключа становится хозяином
 * рабочего пространства, в которое пишет агент. Запись дополнительно
 * проверяет право nj670.start — тот же гейт, что у кнопок в интерфейсе.
 *
 * Сценарий «заполнить модуль агентом»:
 *   1. nj670_create       {text}                  — процесс + схема из текста
 *   2. nj670_process      {code}                  — прочитать, что собралось
 *   3. nj670_save_graph   {code, nodes, edges}    — перерисовать схему вручную
 *   4. nj670_ontology_upsert {kind, name, code}   — дополнить справочник
 *   5. nj670_status       {code}                  — дождаться воркера
 */

return [
	'tools' => [

		[
			'name'        => 'nj670_processes',
			'category'    => 'processhub',
			'description' => 'Список процессов ProcessHub в рабочем пространстве владельца ключа: код, название, подразделение, владелец, статусы плана и факта. Начни с него, чтобы узнать коды.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'q'     => ['type' => 'string', 'description' => 'поиск по названию, коду, подразделению или владельцу'],
					'state' => ['type' => 'string', 'enum' => ['', 'no_fact', 'no_owner', 'audited'], 'description' => 'фильтр состояния; пусто — все'],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'сколько вернуть, по умолчанию 50'],
				],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_processes',
		],

		[
			'name'        => 'nj670_process',
			'category'    => 'processhub',
			'description' => 'Процесс по коду: карточка, текущая версия плана и факта с полным графом (nodes/edges/lanes), метрики и последние версии. Вызывай перед правкой, чтобы не потерять шаги.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'code' => ['type' => 'string', 'description' => 'код процесса, напр. prc1a2b3c4d'],
				],
				'required' => ['code'],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_process',
		],

		[
			'name'        => 'nj670_create',
			'category'    => 'processhub',
			'description' => 'Создать процесс. Если передан text (регламент по шагам), схема соберётся сразу: живой воркер — моделью, без воркера — правилами. Название можно не передавать: оно возьмётся из первой осмысленной строки текста.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'title'           => ['type' => 'string', 'description' => 'название; пусто — из текста'],
					'text'            => ['type' => 'string', 'description' => 'текст регламента: одна строка = одно действие'],
					'description'     => ['type' => 'string', 'description' => 'короткое описание процесса'],
					'department'      => ['type' => 'string', 'description' => 'подразделение'],
					'owner_role_code' => ['type' => 'string', 'description' => 'код роли-владельца из онтологии, напр. lawyer'],
					'parent_code'     => ['type' => 'string', 'description' => 'код родительского процесса'],
				],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_create',
		],

		[
			'name'        => 'nj670_save_graph',
			'category'    => 'processhub',
			'description' => 'Сохранить схему новой версией (по умолчанию черновик). Принимает graph:{nodes,edges,lanes} или nodes/edges на верхнем уровне. Типы шагов: start, end, user_task, service_task, manual_task, gateway_xor, gateway_and, timer, subprocess. Роли/системы — кодами из онтологии. Координаты x/y необязательны: без них включится авто-раскладка.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'code' => ['type' => 'string', 'description' => 'код процесса'],
					'kind' => ['type' => 'string', 'enum' => ['plan', 'fact'], 'description' => 'plan (To-Be) или fact (As-Is); по умолчанию plan'],
					'graph' => ['type' => 'object', 'description' => 'объект {nodes, edges, lanes, meta}'],
					'nodes' => [
						'type' => 'array',
						'description' => 'шаги: [{id, type, title, role_code, system_code, lane, duration_minutes, wait_time_minutes, x, y}]',
						'items' => [
							'type' => 'object',
							'properties' => [
								'id'               => ['type' => 'string'],
								'step_id'          => ['type' => 'string'],
								'type'             => ['type' => 'string'],
								'title'            => ['type' => 'string'],
								'role_code'        => ['type' => 'string'],
								'system_code'      => ['type' => 'string'],
								'input_code'       => ['type' => 'string'],
								'output_code'      => ['type' => 'string'],
								'lane'             => ['type' => 'string'],
								'duration_minutes' => ['type' => 'integer'],
								'wait_time_minutes'=> ['type' => 'integer'],
								'x'                => ['type' => 'integer'],
								'y'                => ['type' => 'integer'],
							],
						],
					],
					'edges' => [
						'type' => 'array',
						'description' => 'связи: [{from, to, type: sequence|conditional|parallel|loop, condition, weight, avg_minutes}]',
						'items' => [
							'type' => 'object',
							'properties' => [
								'from'      => ['type' => 'string'],
								'to'        => ['type' => 'string'],
								'type'      => ['type' => 'string'],
								'condition' => ['type' => 'string'],
								'weight'    => ['type' => 'number'],
								'avg_minutes' => ['type' => 'integer'],
							],
						],
					],
					'lanes'       => ['type' => 'array', 'description' => 'дорожки [{id,title}]; пусто — по ролям'],
					'version_tag' => ['type' => 'string', 'description' => 'метка версии, напр. plan-v2'],
					'status'      => ['type' => 'string', 'enum' => ['draft', 'in_review'], 'description' => 'статус новой версии, по умолчанию draft'],
					'activate'    => ['type' => 'boolean', 'description' => 'сделать версию текущей для холста'],
				],
				'required' => ['code'],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_save_graph',
		],

		[
			'name'        => 'nj670_ontology',
			'category'    => 'processhub',
			'description' => 'Онтология компании: роли, системы, артефакты, события, метрики, правила, подразделения — с кодами, названиями и атрибутами. Коды отсюда нужны в role_code/system_code шагов.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'kind' => ['type' => 'string', 'enum' => ['role', 'system', 'artifact', 'event', 'metric', 'rule', 'department'], 'description' => 'только один раздел; пусто — все'],
				],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_ontology',
		],

		[
			'name'        => 'nj670_ontology_upsert',
			'category'    => 'processhub',
			'description' => 'Добавить или обновить сущность справочника. Если code уже занят в этом разделе — запись обновится (upsert), дубля не будет. Пример: {kind:"role", name:"Юрист", code:"lawyer", rate_per_hour:2500}.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'kind'       => ['type' => 'string', 'enum' => ['role', 'system', 'artifact', 'event', 'metric', 'rule', 'department']],
					'name'       => ['type' => 'string', 'description' => 'название на русском'],
					'code'       => ['type' => 'string', 'description' => 'латинский код; пусто — из названия'],
					'id'         => ['type' => 'integer', 'description' => 'id существующей записи (если известен)'],
					'description'=> ['type' => 'string'],
					'rate_per_hour' => ['type' => 'number', 'description' => 'для роли: ставка, ₽/час'],
					'sla_minutes'   => ['type' => 'integer', 'description' => 'для роли/системы: норматив, минут'],
					'criticality'   => ['type' => 'string'],
					'owner'         => ['type' => 'string'],
					'url'           => ['type' => 'string'],
					'unit'          => ['type' => 'string'],
					'target'        => ['type' => 'string'],
					'category'      => ['type' => 'string'],
					'note'          => ['type' => 'string'],
					'attributes'    => ['type' => 'object', 'description' => 'произвольные атрибуты; главнее одноимённых полей'],
				],
				'required' => ['kind', 'name'],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_ontology_upsert',
		],

		[
			'name'        => 'nj670_status',
			'category'    => 'processhub',
			'description' => 'Состояние процесса: версии, аудит, отчёты, задачи воркера (этап, секундомер, ошибки) и квоты. Вызывай после nj670_create/nj670_synthesize, чтобы узнать, готов ли граф.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'code' => ['type' => 'string'],
					'kind' => ['type' => 'string', 'enum' => ['plan', 'fact']],
				],
				'required' => ['code'],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_status',
		],

		[
			'name'        => 'nj670_synthesize',
			'category'    => 'processhub',
			'description' => 'Поставить в очередь сборку схемы из текста для существующего процесса. Нужен живой воркер; без него вернётся ошибка с подсказкой — тогда используй nj670_save_graph.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'code' => ['type' => 'string'],
					'text' => ['type' => 'string', 'description' => 'регламент по шагам'],
					'mode' => ['type' => 'string', 'enum' => ['full', 'copilot'], 'description' => 'full — вся схема; copilot — точечная правка'],
					'instruction' => ['type' => 'string', 'description' => 'для copilot: что изменить'],
					'node_id' => ['type' => 'string', 'description' => 'для copilot: шаг, который правим'],
				],
				'required' => ['code', 'text'],
			],
			'handler'     => 'Service_Nj670_Mcp::mcp_synthesize',
		],

	],
];
