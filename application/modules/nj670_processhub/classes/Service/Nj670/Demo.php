<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Демо-сценарий «Закупка ТМЦ и согласование договора».
 *
 * Один клик должен дать не пустую форму, а рабочий кейс: справочники,
 * схему «План» (5 шагов, 3 рабочих дня), схему «Факт» (реальность из журнала:
 * Telegram, петля юриста, стихийная СБ, ручное выбивание счёта) и готовый
 * AI-аудит с экономикой. Никаких вызовов модели: данные детерминированы,
 * поэтому демо работает и без LLM-ключей.
 *
 * Числа согласованы между собой:
 *   · план: 230 мин работы + 1210 мин ожидания = 1440 мин (3 рабочих дня);
 *   · факт: 740 мин работы, 8205 мин ожидания; срок 6720 мин задан как
 *     календарный (параллельные ветки), простой СБ 3600 мин = 53,6 % ≈ 54 %;
 *   · потери аудита: 7200 + 5400 + 3400 + 2400 = 18 400 ₽.
 */
class Service_Nj670_Demo extends Service_Nj670_Repo
{
	public static function seed($user_id, $scenario = 'procurement', array $opts = [])
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Нужен пользователь-владелец демо.'];
		}

		$scenario = $scenario === 'procurement' ? 'procurement' : 'procurement';
		$workspace = Service_Nj670_Process::workspace_for($user_id);

		if ( ! $workspace)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось открыть рабочее пространство.'];
		}

		$workspace_id = (int) $workspace['id'];

		$existing = self::_one('SELECT * FROM nj_670_processes
			WHERE workspace_id = '.$workspace_id.' AND is_demo = TRUE
			ORDER BY id LIMIT 1');

		if ($existing AND empty($opts['fresh']))
		{
			return [
				'ok'      => TRUE,
				'exists'  => TRUE,
				'process' => Service_Nj670_Process::process_by_id((int) $existing['id']),
			];
		}

		if ($existing)
		{
			Service_Nj670_Process::delete_process((int) $existing['id'], $user_id);
		}

		if (trim((string) $workspace['company_name']) === '')
		{
			Service_Nj670_Process::workspace_update($workspace_id, [
				'title'        => 'Демо-компания',
				'company_name' => 'ООО «Демо-Компания»',
			]);
		}

		$ontology = self::_seed_ontology($workspace_id);
		$owner_code = 'manager';

		$created = Service_Nj670_Process::create_process($user_id, [
			'title'       => 'Закупка ТМЦ и согласование договора',
			'description' => 'Сквозной процесс закупки товарно-материальных ценностей: от служебной записки инициатора до оплаты счёта. Демонстрационный кейс ProcessHub: идеальный регламент и реальная картина по журналу событий.',
			'department'  => 'Отдел закупок',
			'owner_role_code' => $owner_code,
			'is_demo'     => TRUE,
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return $created;
		}

		$process = $created['process'];

		// Код демо удобно диктовать; если занят — _unique_code добавил суффикс
		// ещё при создании (create_process генерирует свой, поэтому обновляем).
		$code = $process['code'];
		$try = 'procdemo23';

		if ((int) self::_value('SELECT count(*) FROM nj_670_processes WHERE code = '.self::_q($try)) === 0)
		{
			self::_exec('UPDATE nj_670_processes SET code = '.self::_q($try).'
				WHERE id = '.(int) $process['id']);
			$code = $try;
		}

		$plan_graph = self::_plan_graph($code);
		$fact_graph = self::_fact_graph($code, $user_id);

		$plan = Service_Nj670_Process::create_version((int) $process['id'], 'plan', [
			'version_tag' => 'plan-v1',
			'status'      => Service_Nj670_Process::S_ACTIVE,
			'graph'       => $plan_graph,
			'source_kind' => 'demo',
			'source_text' => 'Утверждённый регламент закупки ТМЦ: заявка в 1С, согласование руководителя, авто-проверка N2J, виза юриста, оплата бухгалтерией.',
			'created_by'  => $user_id,
			'model'       => 'demo',
			'activate'    => TRUE,
		]);

		if ( ! Arr::get($plan, 'ok'))
		{
			return $plan;
		}

		$fact = Service_Nj670_Process::create_version((int) $process['id'], 'fact', [
			'version_tag' => 'fact-2026-q4',
			'status'      => Service_Nj670_Process::S_ACTIVE,
			'graph'       => $fact_graph,
			'source_kind' => 'demo',
			'source_text' => 'Журнал 40 закупочных кейсов за квартал: Case_ID, Activity, Timestamp, Resource.',
			'created_by'  => $user_id,
			'model'       => 'demo-mining',
			'activate'    => TRUE,
		]);

		if ( ! Arr::get($fact, 'ok'))
		{
			return $fact;
		}

		$audit = self::_seed_audit($process, (int) $plan['id'], (int) $fact['id'], $user_id);

		self::_event((int) $process['id'], $user_id, 'demo.seeded',
			'Загружен демо-сценарий «Закупка ТМЦ и согласование договора»', [
				'plan_version_id' => (int) $plan['id'],
				'fact_version_id' => (int) $fact['id'],
				'audit_id'        => $audit,
			]);

		return [
			'ok'      => TRUE,
			'created' => TRUE,
			'process' => Service_Nj670_Process::process_by_id((int) $process['id']),
			'audit_id'=> $audit,
			'plan_version_id' => (int) $plan['id'],
			'fact_version_id' => (int) $fact['id'],
		];
	}

	// ---------------------------------------------------------------- справочники

	protected static function _seed_ontology($workspace_id)
	{
		$workspace_id = (int) $workspace_id;

		$rows = [
			['role', 'cfo', 'Финансовый контролер', ['rate_per_hour' => 2200]],
			['system', 'diadoc', 'СЭД «Диадок»', ['system_kind' => 'document']],
			['system', 'n2j_api', 'API N2J (проверка контрагента)', ['system_kind' => 'api']],
			['artifact', 'memo', 'Служебная записка', ['unit' => 'документ']],
			['artifact', 'reliability', 'Отчёт о благонадёжности N2J', ['unit' => 'документ']],
			['artifact', 'passport', 'Скан паспорта', ['unit' => 'документ']],
			['artifact', 'offer', 'Коммерческое предложение', ['unit' => 'документ']],
			['artifact', 'payment_order', 'Платёжное поручение', ['unit' => 'документ']],
			['event', 'request_created', 'Заявка создана', []],
			['event', 'contract_signed', 'Договор подписан', []],
			['event', 'payment_sent', 'Оплата отправлена', []],
			['metric', 'cycle_time', 'Срок закупки', ['unit' => 'час', 'target' => 72]],
			['metric', 'security_wait', 'Простой проверки СБ', ['unit' => 'час', 'target' => 24]],
			['rule', 'amount_500k', 'Закупка свыше 500 000 ₽ — контроль финконтролера', [
				'description' => 'Если сумма заявки выше порога, требуется виза финансового контролера до заключения договора.',
				'criticality' => 'high',
			]],
			['rule', 'legal_visa', 'Проект договора обязателен к визе юриста', [
				'description' => 'Договор без визы юриста не подлежит оплате.',
				'criticality' => 'high',
			]],
			['rule', 'security_check', 'Проверка СБ до подписания договора', [
				'description' => 'Служба безопасности проверяет контрагента и отсутствие признаков аффилированности.',
				'criticality' => 'high',
			]],
			['rule', 'secure_channel', 'Документы с персональными данными — только в СЭД', [
				'description' => 'Сканы паспортов и коммерческие предложения нельзя передавать в незащищённых мессенджерах.',
				'criticality' => 'critical',
			]],
			['department', 'procurement', 'Отдел закупок', []],
			['department', 'finance', 'Финансовый отдел', []],
			['department', 'legal_dept', 'Юридический отдел', []],
			['department', 'security_dept', 'Служба безопасности', []],
		];

		foreach ($rows as $row)
		{
			Service_Nj670_Ontology::create($workspace_id, [
				'class_type' => $row[0],
				'code'       => $row[1],
				'name'       => $row[2],
				'attributes' => $row[3],
				'is_base'    => FALSE,
			]);
		}

		// Имена базовых ролей уточняем под сценарий (коды не меняем — на них
		// ссылаются схемы).
		$renames = [
			'initiator' => 'Инициатор закупки',
			'manager'   => 'Руководитель подразделения',
			'accountant'=> 'Бухгалтерия',
			'security'  => 'Служба безопасности (СБ)',
		];

		Service_Nj670_Ontology::reset($workspace_id);
		$index = Service_Nj670_Ontology::index($workspace_id, TRUE);

		foreach ($renames as $code => $name)
		{
			$row = (array) Arr::path($index['by_code'], 'role.'.$code, []);

			if ( ! empty($row['id']))
			{
				Service_Nj670_Ontology::update($workspace_id, (int) $row['id'], ['name' => $name]);
			}
		}

		return Service_Nj670_Ontology::index($workspace_id, TRUE);
	}

	// ---------------------------------------------------------------- ПЛАН

	protected static function _plan_graph($process_code)
	{
		return [
			'lanes' => [
				['id' => 'initiator', 'title' => 'Инициатор закупки', 'role_code' => 'initiator'],
				['id' => 'manager', 'title' => 'Руководитель подразделения', 'role_code' => 'manager'],
				['id' => 'lawyer', 'title' => 'Юрист', 'role_code' => 'lawyer'],
				['id' => 'accountant', 'title' => 'Бухгалтерия', 'role_code' => 'accountant'],
			],
			'nodes' => [
				[
					'id' => 'start', 'step_id' => 'start', 'type' => 'start',
					'title' => 'Заявка на закупку', 'lane' => 'initiator',
				],
				[
					'id' => 'request', 'step_id' => 'request', 'type' => 'user_task',
					'title' => 'Формирует заявку в 1С', 'lane' => 'initiator',
					'role_code' => 'initiator', 'system_code' => '1c',
					'input_code' => 'memo', 'output_code' => 'memo',
					'duration_minutes' => 60, 'wait_time_minutes' => 0,
					'properties' => ['description' => 'Служебная записка с обоснованием цены и ссылкой на коммерческое предложение.'],
				],
				[
					'id' => 'approve', 'step_id' => 'approve', 'type' => 'user_task',
					'title' => 'Согласует заявку', 'lane' => 'manager',
					'role_code' => 'manager', 'system_code' => '1c',
					'input_code' => 'memo', 'output_code' => 'memo',
					'duration_minutes' => 30, 'wait_time_minutes' => 240,
					'properties' => ['sla_minutes' => 240, 'control' => true],
				],
				[
					'id' => 'check', 'step_id' => 'check', 'type' => 'service_task',
					'title' => 'Авто-проверка контрагента по API N2J', 'lane' => 'initiator',
					'system_code' => 'n2j_api', 'output_code' => 'reliability',
					'duration_minutes' => 5, 'wait_time_minutes' => 10,
					'properties' => ['control' => true, 'description' => 'Отчёт о благонадёжности прикладывается к заявке автоматически.'],
				],
				[
					'id' => 'legal', 'step_id' => 'legal', 'type' => 'user_task',
					'title' => 'Визирует проект договора', 'lane' => 'lawyer',
					'role_code' => 'lawyer', 'system_code' => 'diadoc',
					'input_code' => 'contract', 'output_code' => 'contract',
					'duration_minutes' => 90, 'wait_time_minutes' => 480,
					'properties' => ['control' => true, 'sla_minutes' => 480],
				],
				[
					'id' => 'pay', 'step_id' => 'pay', 'type' => 'user_task',
					'title' => 'Оплачивает счёт', 'lane' => 'accountant',
					'role_code' => 'accountant', 'system_code' => '1c',
					'input_code' => 'invoice', 'output_code' => 'payment_order',
					'duration_minutes' => 45, 'wait_time_minutes' => 480,
					'properties' => ['control' => true],
				],
				[
					'id' => 'end', 'step_id' => 'end', 'type' => 'end',
					'title' => 'Договор оплачен', 'lane' => 'accountant',
				],
			],
			'edges' => [
				['from' => 'start', 'to' => 'request'],
				['from' => 'request', 'to' => 'approve'],
				['from' => 'approve', 'to' => 'check'],
				['from' => 'check', 'to' => 'legal'],
				['from' => 'legal', 'to' => 'pay'],
				['from' => 'pay', 'to' => 'end'],
			],
			'meta' => [
				'title' => 'Закупка ТМЦ — утверждённый регламент',
				'source' => 'demo',
				'mode' => 'plan',
			],
		];
	}

	// ---------------------------------------------------------------- ФАКТ

	protected static function _fact_graph($process_code, $user_id)
	{
		return [
			'lanes' => [
				['id' => 'initiator', 'title' => 'Инициатор закупки', 'role_code' => 'initiator'],
				['id' => 'manager', 'title' => 'Руководитель подразделения', 'role_code' => 'manager'],
				['id' => 'cfo', 'title' => 'Финансовый контролер', 'role_code' => 'cfo'],
				['id' => 'lawyer', 'title' => 'Юрист', 'role_code' => 'lawyer'],
				['id' => 'security', 'title' => 'Служба безопасности (СБ)', 'role_code' => 'security'],
				['id' => 'accountant', 'title' => 'Бухгалтерия', 'role_code' => 'accountant'],
			],
			'nodes' => [
				[
					'id' => 'start', 'step_id' => 'start', 'type' => 'start',
					'title' => 'Закупка инициирована', 'lane' => 'initiator',
				],
				[
					'id' => 'request', 'step_id' => 'request', 'type' => 'user_task',
					'title' => 'Создаёт заявку в 1С', 'lane' => 'initiator',
					'role_code' => 'initiator', 'system_code' => '1c',
					'input_code' => 'memo', 'output_code' => 'memo',
					'duration_minutes' => 60, 'wait_time_minutes' => 0,
					'properties' => ['count' => 40],
				],
				[
					'id' => 'telegram', 'step_id' => 'telegram', 'type' => 'manual_task',
					'title' => 'Пересылает файлы и уточняет реквизиты', 'lane' => 'initiator',
					'role_code' => 'initiator', 'system_code' => 'telegram',
					'input_code' => 'passport', 'output_code' => 'offer',
					'duration_minutes' => 145, 'wait_time_minutes' => 765,
					'properties' => [
						'shadow' => true, 'problem' => true, 'severity' => 'critical',
						'count' => 40,
						'evidence' => '40 из 40 кейсов: сканы паспортов и КП идут вложением в чат.',
					],
				],
				[
					'id' => 'head_chat', 'step_id' => 'head_chat', 'type' => 'user_task',
					'title' => 'Согласует в чате Telegram', 'lane' => 'manager',
					'role_code' => 'manager', 'system_code' => 'telegram',
					'input_code' => 'memo', 'output_code' => 'memo',
					'duration_minutes' => 40, 'wait_time_minutes' => 960,
					'properties' => ['shadow' => true, 'problem' => true, 'count' => 40],
				],
				[
					'id' => 'cfo', 'step_id' => 'cfo', 'type' => 'user_task',
					'title' => 'Финансовый контроль (не по регламенту)', 'lane' => 'cfo',
					'role_code' => 'cfo', 'system_code' => '1c',
					'input_code' => 'memo', 'output_code' => 'memo',
					'duration_minutes' => 45, 'wait_time_minutes' => 960,
					'properties' => ['shadow' => true, 'problem' => true, 'count' => 40],
				],
				[
					'id' => 'legal', 'step_id' => 'legal', 'type' => 'user_task',
					'title' => 'Проверяет проект договора', 'lane' => 'lawyer',
					'role_code' => 'lawyer', 'system_code' => 'diadoc',
					'input_code' => 'contract', 'output_code' => 'contract',
					'duration_minutes' => 90, 'wait_time_minutes' => 720,
					'properties' => ['control' => true, 'count' => 26],
				],
				[
					'id' => 'rework', 'step_id' => 'rework', 'type' => 'user_task',
					'title' => 'Возвращает договор на доработку', 'lane' => 'lawyer',
					'role_code' => 'lawyer', 'system_code' => 'diadoc',
					'input_code' => 'contract', 'output_code' => 'contract',
					'duration_minutes' => 15, 'wait_time_minutes' => 240,
					'properties' => ['problem' => true, 'rework' => 2, 'count' => 18],
				],
				[
					'id' => 'security', 'step_id' => 'security', 'type' => 'user_task',
					'title' => 'Проверка СБ (подключается стихийно)', 'lane' => 'security',
					'role_code' => 'security', 'system_code' => 'outlook',
					'input_code' => 'passport', 'output_code' => 'reliability',
					'duration_minutes' => 120, 'wait_time_minutes' => 3600,
					'properties' => [
						'shadow' => true, 'problem' => true, 'severity' => 'critical',
						'count' => 40, 'control' => true,
						'evidence' => 'СБ подключается на 6-й день и держит заявку 7,5 рабочих дней — 54 % срока.',
					],
				],
				[
					'id' => 'invoice', 'step_id' => 'invoice', 'type' => 'manual_task',
					'title' => 'Ручное выбивание счёта у поставщика', 'lane' => 'initiator',
					'role_code' => 'initiator', 'system_code' => 'telegram',
					'input_code' => 'invoice', 'output_code' => 'invoice',
					'duration_minutes' => 180, 'wait_time_minutes' => 480,
					'properties' => ['shadow' => true, 'problem' => true, 'count' => 40],
				],
				[
					'id' => 'pay', 'step_id' => 'pay', 'type' => 'user_task',
					'title' => 'Оплачивает счёт', 'lane' => 'accountant',
					'role_code' => 'accountant', 'system_code' => '1c',
					'input_code' => 'invoice', 'output_code' => 'payment_order',
					'duration_minutes' => 45, 'wait_time_minutes' => 480,
					'properties' => ['count' => 40],
				],
				[
					'id' => 'end', 'step_id' => 'end', 'type' => 'end',
					'title' => 'Договор оплачен', 'lane' => 'accountant',
				],
			],
			'edges' => [
				['from' => 'start', 'to' => 'request', 'weight' => 40, 'properties' => ['count' => 40]],
				['from' => 'request', 'to' => 'telegram', 'weight' => 40, 'properties' => ['count' => 40]],
				['from' => 'telegram', 'to' => 'head_chat', 'weight' => 40, 'properties' => ['count' => 40]],
				['from' => 'head_chat', 'to' => 'cfo', 'weight' => 40, 'properties' => ['count' => 40]],
				['from' => 'cfo', 'to' => 'legal', 'weight' => 26, 'condition' => 'договор направлен юристу', 'avg_minutes' => 1440, 'properties' => ['count' => 26]],
				[
					'from' => 'cfo', 'to' => 'security', 'weight' => 14,
					'type' => 'conditional', 'condition' => 'без юриста (35 %)', 'avg_minutes' => 3600,
					'properties' => ['count' => 14, 'problem' => true],
				],
				[
					'from' => 'legal', 'to' => 'rework', 'weight' => 18,
					'type' => 'conditional', 'condition' => 'нет реквизитов', 'avg_minutes' => 480,
					'properties' => ['count' => 18, 'problem' => true],
				],
				[
					'from' => 'rework', 'to' => 'telegram', 'weight' => 18,
					'type' => 'loop', 'condition' => 'возврат (2 раза)', 'avg_minutes' => 240,
					'properties' => ['count' => 18, 'problem' => true],
				],
				['from' => 'legal', 'to' => 'security', 'weight' => 26, 'avg_minutes' => 480, 'properties' => ['count' => 26]],
				['from' => 'security', 'to' => 'invoice', 'weight' => 40, 'avg_minutes' => 3600, 'properties' => ['count' => 40]],
				['from' => 'invoice', 'to' => 'pay', 'weight' => 40, 'avg_minutes' => 480, 'properties' => ['count' => 40]],
				['from' => 'pay', 'to' => 'end', 'weight' => 40, 'properties' => ['count' => 40]],
			],
			'meta' => [
				'title' => 'Закупка ТМЦ — фактическое исполнение (40 кейсов)',
				'source' => 'demo-mining',
				'mode' => 'fact',
				'elapsed_minutes' => 6720,
				'note' => 'Срок — календарный: ветки согласования идут параллельно. Простой СБ 3600 мин = 53,6 % срока.',
			],
		];
	}

	// ---------------------------------------------------------------- АУДИТ

	protected static function _seed_audit(array $process, $plan_version_id, $fact_version_id, $user_id)
	{
		$findings = [
			[
				'id' => 'f1',
				'severity' => 'critical',
				'category' => 'shadow_it',
				'title' => 'Паспорта и коммерческие предложения уходят в Telegram',
				'description' => 'Файлы с персональными данными и коммерческой тайной передаются через незащищённый мессенджер. Это нарушает правило secure_channel и создаёт риск утечки.',
				'evidence' => '40 из 40 кейсов: шаг «Пересылает файлы в Telegram»; система telegram не предусмотрена планом.',
				'recommendation' => 'Перевести обмен документами в СЭД «Диадок», запретить вложения с ПДн в мессенджерах, включить DLP-контроль.',
				'impact_rub' => 0,
				'node_id' => 'telegram',
				'metric' => '100 % кейсов',
			],
			[
				'id' => 'f2',
				'severity' => 'critical',
				'category' => 'bottleneck',
				'title' => 'Ожидание проверки СБ — 54 % срока закупки',
				'description' => 'Служба безопасности подключается стихийно на шестой день и держит заявку ещё 7,5 рабочих дней. Это самое дорогое узкое место процесса.',
				'evidence' => '3600 мин ожидания из 6720 мин календарного срока (53,6 %).',
				'recommendation' => 'Встроить проверку СБ в план до визы юриста, задать SLA 24 часа и автоматический эскалейт через 8 часов.',
				'impact_rub' => 7200,
				'node_id' => 'security',
				'metric' => '3600 мин простоя',
			],
			[
				'id' => 'f3',
				'severity' => 'high',
				'category' => 'bypass',
				'title' => 'Юрист обойдён в 35 % случаев',
				'description' => 'По 14 из 40 кейсов договор уходит на проверку СБ и оплату без визы юриста. Контрольное правило legal_visa нарушается.',
				'evidence' => 'Переход «Финансовый контроль → СБ» с условием «без юриста»: 14 кейсов из 40 (35 %).',
				'recommendation' => 'Сделать визу юриста обязательным предусловием оплаты в 1С; блокировать счёт без отметки в СЭД.',
				'impact_rub' => 5400,
				'node_id' => 'legal',
				'metric' => '14 из 40 кейсов',
			],
			[
				'id' => 'f4',
				'severity' => 'high',
				'category' => 'extra_step',
				'title' => 'Неучтённый шаг: ручное выбивание счёта',
				'description' => 'Инициатор тратит 3 часа на переписку с поставщиком. Шага нет в регламенте, ответственность и SLA не определены.',
				'evidence' => 'Шаг «Ручное выбивание счёта у поставщика»: 40 кейсов, 180 мин работы + 480 мин ожидания.',
				'recommendation' => 'Перенести получение счёта в 1С (ЭДО), закрепить ответственного и срок 1 рабочий день.',
				'impact_rub' => 3400,
				'node_id' => 'invoice',
				'metric' => '180 мин на кейс',
			],
			[
				'id' => 'f5',
				'severity' => 'medium',
				'category' => 'loop',
				'title' => 'Договор возвращается на доработку дважды',
				'description' => 'В 18 кейсах юрист возвращал договор из-за отсутствия реквизитов. Петля увеличивает срок и сжигает время инициатора.',
				'evidence' => 'Петля «Возвращает договор → Уточняет реквизиты», 18 кейсов, до 2 возвратов на кейс.',
				'recommendation' => 'Добавить обязательные поля реквизитов в форму заявки и автопроверку по ЕГРЮЛ до визы.',
				'impact_rub' => 2400,
				'node_id' => 'rework',
				'metric' => '18 кейсов',
			],
			[
				'id' => 'f6',
				'severity' => 'medium',
				'category' => 'shadow_it',
				'title' => 'Финансовый контроль выполняется вне регламента',
				'description' => 'Финконтролер подключился к процессу, хотя в плане его нет. Правило amount_500k работает, но без формального шага.',
				'evidence' => 'Шаг «Финансовый контроль»: 40 кейсов, 960 мин ожидания.',
				'recommendation' => 'Внести шаг в регламент с порогом 500 000 ₽ и SLA 8 часов.',
				'impact_rub' => 0,
				'node_id' => 'cfo',
				'metric' => '40 кейсов',
			],
		];

		$summary = 'Фактический процесс длится 14 рабочих дней вместо 3 по регламенту. Узкое место — проверка СБ (54 % срока). '
			.'В 35 % кейсов юрист обойдён, документы с ПДн идут через Telegram, появился неучтённый шаг ручного получения счёта. '
			.'Суммарный экономический эффект устранения разрывов — 18 400 ₽ на одну процедуру закупки.';

		$id = self::_insert('nj_670_audits', [
			'process_id'      => (int) $process['id'],
			'plan_version_id' => (int) $plan_version_id,
			'fact_version_id' => (int) $fact_version_id,
			'status'          => 'done',
			'summary'         => $summary,
			'findings'        => $findings,
			'total_impact_rub'=> 18400,
			'risk_level'      => 'critical',
			'model'           => 'demo',
			'mode'            => 'rules',
			'created_by'      => (int) $user_id,
		], ['findings']);

		return $id;
	}
}
