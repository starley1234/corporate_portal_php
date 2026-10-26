<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сырые данные → онтология и процессы (канал nj670.ontology).
 *
 * Зачем отдельный сервис: страница онтологии принимает документы, воркер
 * разбирает их моделью, но справочник компании нельзя менять «на лету».
 * Предложение живёт в nj_670_ontology_jobs, пока человек не нажмёт
 * «Принять»: только тогда появляются сущности и заготовки процессов.
 *
 * Без модели сервис не пустой: нормализация предложений, дедупликация по
 * коду, проверка ссылок шагов на справочник и сборка графа — детерминированные
 * операции, их проверяет юнит-тест.
 */
class Service_Nj670_Ontologyfeed extends Service_Nj670_Repo
{
	const CHANNEL = 'nj670.ontology';

	const S_QUEUED = 'queued';
	const S_RUNNING = 'running';
	const S_DONE = 'done';
	const S_ERROR = 'error';

	const MAX_ENTITIES = 120;
	const MAX_PROCESSES = 10;
	const MAX_STEPS = 30;

	/**
	 * Поставить разбор документа в очередь.
	 *
	 * @param array $data text, source (результат Process::store_source), name
	 */
	public static function queue($workspace_id, $user_id, array $data)
	{
		$workspace_id = (int) $workspace_id;
		$user_id = (int) $user_id;

		if ($workspace_id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Рабочее пространство не найдено.'];
		}

		if ( ! (bool) Arr::get(self::config(), 'enabled', TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Приём задач временно выключен администратором.'];
		}

		$text = trim((string) Arr::get($data, 'text', ''));
		$source = (array) Arr::get($data, 'source', []);

		// Текстовый файл уже прочитан на сайте — используем его, если поле
		// пустое. Иначе пользователь прикладывает .txt и получает «нет данных».
		$file_text = trim((string) Arr::get($source, 'text', ''));

		if ($text === '' AND $file_text !== '')
		{
			$text = $file_text;
		}

		if ($text === '' AND ! Arr::get($source, 'ok'))
		{
			return ['ok' => FALSE, 'error' => 'Вставьте описание или приложите файл: без исходных данных разбирать нечего.'];
		}

		if ($text !== '' AND UTF8::strlen($text) < 40 AND ! Arr::get($source, 'ok'))
		{
			return ['ok' => FALSE, 'error' => 'Текст слишком короткий: нужно хотя бы несколько строк о процессе или подразделении.'];
		}

		$text = UTF8::substr($text, 0, (int) self::limits('max_text', 20000));

		// Дневной лимит считаем по заданиям, а не по «сборкам»: одно и то же
		// действие пользователя не должно съедать квоту синтеза схем.
		$per_day = (int) self::limits('feed_per_day', 20);
		$today = (int) self::_value('SELECT count(*) FROM nj_670_ontology_jobs
			WHERE user_id = '.$user_id.' AND created_at > now() - interval \'1 day\'');

		if ($today >= $per_day)
		{
			return ['ok' => FALSE, 'error' => 'Дневной лимит разборов исчерпан ('.$per_day.'). Повторите завтра.'];
		}

		$source_kind = (string) Arr::get($source, 'kind', 'text');
		$source_kind = in_array($source_kind, ['text', 'pdf', 'image', 'audio'], TRUE) ? $source_kind : 'text';
		$source_name = UTF8::substr(trim((string) Arr::get($source, 'filename', '')), 0, 255);

		$id = self::_insert('nj_670_ontology_jobs', [
			'workspace_id' => $workspace_id,
			'user_id'      => $user_id,
			'status'       => self::S_QUEUED,
			'source_kind'  => $source_kind,
			'source_name'  => $source_name,
			'source_text'  => $text,
			'source_path'  => UTF8::substr((string) Arr::get($source, 'stored_path', ''), 0, 255),
			'model'        => self::model(),
			'result'       => [],
		], ['result']);

		if ($id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить задание.'];
		}

		$queued = self::_push(self::CHANNEL, [
			'ontology_job_id' => (int) $id,
			'model'           => self::model(),
		], $user_id, 'feed');

		if ( ! Arr::get($queued, 'ok'))
		{
			self::_update('nj_670_ontology_jobs', [
				'status' => self::S_ERROR,
				'error'  => UTF8::substr((string) Arr::get($queued, 'error', 'Очередь недоступна.'), 0, 1000),
			], $id);

			return $queued;
		}

		self::_update('nj_670_ontology_jobs', [
			'job_id' => (int) $queued['job_id'],
		], $id);

		self::_event(0, $user_id, 'ontology.queued', 'Разбор документа «'.($source_name !== '' ? $source_name : 'текст').'» поставлен в очередь',
			['ontology_job_id' => (int) $id, 'job_id' => (int) $queued['job_id']]);

		return ['ok' => TRUE, 'id' => (int) $id, 'job_id' => (int) $queued['job_id']];
	}

	public static function job($id, $workspace_id = 0)
	{
		$id = (int) $id;

		if ($id <= 0)
		{
			return NULL;
		}

		$sql = 'SELECT * FROM nj_670_ontology_jobs WHERE id = '.$id;

		if ((int) $workspace_id > 0)
		{
			$sql .= ' AND workspace_id = '.(int) $workspace_id;
		}

		$row = self::_one($sql);

		if ( ! $row)
		{
			return NULL;
		}

		$row['result'] = self::json_decode(Arr::get($row, 'result', []));

		return $row;
	}

	public static function latest($workspace_id)
	{
		$row = self::_one('SELECT * FROM nj_670_ontology_jobs
			WHERE workspace_id = '.(int) $workspace_id.'
			ORDER BY id DESC LIMIT 1');

		if ( ! $row)
		{
			return NULL;
		}

		$row['result'] = self::json_decode(Arr::get($row, 'result', []));

		return $row;
	}

	public static function jobs($workspace_id, $limit = 10)
	{
		return self::_all('SELECT id, status, source_kind, source_name, model, error,
				accepted_at, created_at
			FROM nj_670_ontology_jobs
			WHERE workspace_id = '.(int) $workspace_id.'
			ORDER BY id DESC LIMIT '.max(1, min(50, (int) $limit)));
	}

	/**
	 * Контекст для воркера: текст (или файл), справочник и лимиты.
	 */
	public static function context(array $body)
	{
		$job = self::job((int) Arr::get($body, 'ontology_job_id', 0));

		if ( ! $job)
		{
			throw new DomainException('Задание разбора не найдено.');
		}

		$workspace_id = (int) $job['workspace_id'];

		$context = [
			'ok'      => TRUE,
			'mode'    => 'ontology',
			'job'     => [
				'id'          => (int) $job['id'],
				'source_kind' => (string) $job['source_kind'],
				'source_name' => (string) $job['source_name'],
			],
			// _source_text воркера читает version.source_text — оставляем
			// тот же контракт, что у синтеза схемы: меньше ветвлений в Python.
			'version' => [
				'id'          => 0,
				'kind'        => 'plan',
				'version_tag' => 'ontology',
				'source_text' => (string) $job['source_text'],
			],
			'process' => [
				'id'          => 0,
				'title'       => (string) $job['source_name'] !== '' ? (string) $job['source_name'] : 'Онтология организации',
				'code'        => '',
				'description' => '',
			],
			'ontology' => Service_Nj670_Ontology::prompt_dictionary($workspace_id, 200),
			'existing' => [
				'entities' => (int) self::_value('SELECT count(*) FROM nj_670_entities
					WHERE workspace_id = '.$workspace_id),
				'processes' => (int) self::_value('SELECT count(*) FROM nj_670_processes
					WHERE workspace_id = '.$workspace_id),
			],
			'limits' => [
				'max_text'     => (int) self::limits('max_text', 20000),
				'max_entities' => self::MAX_ENTITIES,
				'max_processes'=> self::MAX_PROCESSES,
				'max_steps'    => self::MAX_STEPS,
				'out_tokens'   => (int) self::limits('feed_out_tokens', 8000),
			],
			'model' => self::model(),
		];

		$path = (string) $job['source_path'];

		if ($path !== '')
		{
			$bytes = Service_Nj670_Process::source_bytes($path);

			if ($bytes === '')
			{
				throw new DomainException('Файл-источник не найден на сайте.');
			}

			$context['source'] = [
				'kind'        => (string) $job['source_kind'],
				'filename'    => (string) $job['source_name'],
				'content_b64' => base64_encode($bytes),
			];
		}

		self::_update('nj_670_ontology_jobs', [
			'status' => self::S_RUNNING,
			'error'  => '',
		], (int) $job['id']);

		return $context;
	}

	/**
	 * Результат воркера: предложение сущностей и процессов.
	 */
	public static function store_result(array $body)
	{
		$job = self::job((int) Arr::get($body, 'ontology_job_id', 0));

		if ( ! $job)
		{
			throw new DomainException('Задание разбора не найдено.');
		}

		// Воркер может прислать {result:{entities,processes}} или плоские
		// поля — принимаем оба варианта, чтобы не расходиться с экспортом.
		$raw = Arr::get($body, 'result', NULL);
		$raw = is_array($raw) ? $raw : $body;

		$result = self::normalize_result($raw, (int) $job['workspace_id'], (string) Arr::get($body, 'model', ''));

		self::_update('nj_670_ontology_jobs', [
			'status' => self::S_DONE,
			'result' => $result,
			'model'  => UTF8::substr(trim((string) Arr::get($body, 'model', $job['model'])), 0, 160),
			'error'  => '',
		], (int) $job['id'], ['result']);

		self::_event(0, (int) $job['user_id'], 'ontology.ready',
			'Разбор готов: '.count($result['entities']).' сущностей, '.count($result['processes']).' процессов',
			['ontology_job_id' => (int) $job['id'], 'stats' => $result['stats']]);

		return [
			'ok'        => TRUE,
			'entities'  => count($result['entities']),
			'processes' => count($result['processes']),
		];
	}

	public static function fail(array $body)
	{
		$job = self::job((int) Arr::get($body, 'ontology_job_id', 0));

		if ( ! $job)
		{
			throw new DomainException('Задание разбора не найдено.');
		}

		self::_update('nj_670_ontology_jobs', [
			'status' => self::S_ERROR,
			'error'  => UTF8::substr(trim((string) Arr::get($body, 'error', 'Разбор не удался.')), 0, 1000),
		], (int) $job['id']);

		return ['ok' => TRUE];
	}

	/**
	 * Нормализация ответа модели: разрешённые классы, коды, дедупликация,
	 * проверка ссылок шагов на справочник. Без БД не вызывается.
	 */
	public static function normalize_result($raw, $workspace_id, $model = '')
	{
		$raw = is_array($raw) ? $raw : [];
		$index = Service_Nj670_Ontology::index((int) $workspace_id);
		$by_code = (array) Arr::get($index, 'by_code', []);

		$aliases = [
			'role' => 'role', 'роль' => 'role', 'роли' => 'role', 'position' => 'role', 'должность' => 'role',
			'system' => 'system', 'система' => 'system', 'системы' => 'system', 'it' => 'system', 'tool' => 'system',
			'artifact' => 'artifact', 'артефакт' => 'artifact', 'document' => 'artifact', 'документ' => 'artifact',
			'event' => 'event', 'событие' => 'event',
			'metric' => 'metric', 'метрика' => 'metric', 'kpi' => 'metric',
			'rule' => 'rule', 'правило' => 'rule', 'control' => 'rule',
			'department' => 'department', 'подразделение' => 'department', 'отдел' => 'department', 'unit' => 'department',
		];

		$entities = [];
		$seen = [];

		foreach ((array) Arr::get($raw, 'entities', []) as $item)
		{
			if ( ! is_array($item))
			{
				continue;
			}

			$type_raw = mb_strtolower(trim((string) Arr::get($item, 'class_type', Arr::get($item, 'type', ''))));
			$type = (string) Arr::get($aliases, $type_raw, '');

			if ($type === '')
			{
				continue;
			}

			$name = UTF8::substr(trim((string) Arr::get($item, 'name', '')), 0, 255);

			if ($name === '')
			{
				continue;
			}

			$code = Service_Nj670_Ontology::normalize_code((string) Arr::get($item, 'code', ''));

			if ($code === '')
			{
				$code = Service_Nj670_Ontology::code_from_name($name);
			}

			if ($code === '')
			{
				continue;
			}

			$key = $type.'|'.$code;

			if (isset($seen[$key]))
			{
				continue;
			}

			$seen[$key] = TRUE;

			$attributes = Arr::get($item, 'attributes', Arr::get($item, 'attrs', []));
			$attributes = is_array($attributes) ? $attributes : [];

			// Модель часто кладёт описание рядом с атрибутами.
			foreach (['description', 'note', 'purpose'] as $field)
			{
				$value = trim((string) Arr::get($item, $field, ''));

				if ($value !== '' AND ! isset($attributes['description']))
				{
					$attributes['description'] = $value;
				}
			}

			$attributes = Service_Nj670_Ontology::clean_attributes($type, $attributes);
			$existing = (array) Arr::get((array) Arr::get($by_code, $type, []), $code, []);

			$entities[] = [
				'class_type' => $type,
				'code'       => UTF8::substr($code, 0, 100),
				'name'       => $name,
				'attributes' => $attributes,
				'exists'     => ! empty($existing),
				'existing_name' => (string) Arr::get($existing, 'name', ''),
				'source'     => UTF8::substr(trim((string) Arr::get($item, 'source', Arr::get($item, 'evidence', ''))), 0, 240),
			];

			if (count($entities) >= self::MAX_ENTITIES)
			{
				break;
			}
		}

		$processes = [];

		foreach ((array) Arr::get($raw, 'processes', []) as $item)
		{
			if ( ! is_array($item))
			{
				continue;
			}

			$title = UTF8::substr(trim((string) Arr::get($item, 'title', Arr::get($item, 'name', ''))), 0, 255);

			if ($title === '')
			{
				continue;
			}

			$steps = [];

			foreach ((array) Arr::get($item, 'steps', []) as $step)
			{
				if ( ! is_array($step))
				{
					continue;
				}

				$step_title = UTF8::substr(trim((string) Arr::get($step, 'title', Arr::get($step, 'name', ''))), 0, 255);

				if ($step_title === '')
				{
					continue;
				}

				$steps[] = [
					'title'             => $step_title,
					'type'              => self::_step_type((string) Arr::get($step, 'type', '')),
					'role_code'         => self::_code((string) Arr::get($step, 'role_code', Arr::get($step, 'role', ''))),
					'system_code'       => self::_code((string) Arr::get($step, 'system_code', Arr::get($step, 'system', ''))),
					'input_code'        => self::_code((string) Arr::get($step, 'input_code', Arr::get($step, 'input', ''))),
					'output_code'       => self::_code((string) Arr::get($step, 'output_code', Arr::get($step, 'output', ''))),
					'duration_minutes'  => max(0, min(100000, (int) Arr::get($step, 'duration_minutes', Arr::get($step, 'duration', 0)))),
					'wait_time_minutes' => max(0, min(1000000, (int) Arr::get($step, 'wait_time_minutes', Arr::get($step, 'wait', 0)))),
					'condition'         => UTF8::substr(trim((string) Arr::get($step, 'condition', '')), 0, 200),
				];

				if (count($steps) >= self::MAX_STEPS)
				{
					break;
				}
			}

			if (count($steps) < 2)
			{
				// Один шаг — ещё не процесс: нечего показывать на холсте.
				continue;
			}

			$processes[] = [
				'title'           => $title,
				'description'     => UTF8::substr(trim((string) Arr::get($item, 'description', '')), 0, 5000),
				'department'      => UTF8::substr(trim((string) Arr::get($item, 'department', '')), 0, 255),
				'owner_role_code' => self::_code((string) Arr::get($item, 'owner_role_code', Arr::get($item, 'owner', ''))),
				'steps'           => $steps,
				'source'          => UTF8::substr(trim((string) Arr::get($item, 'source', '')), 0, 240),
			];

			if (count($processes) >= self::MAX_PROCESSES)
			{
				break;
			}
		}

		$stats = ['role' => 0, 'system' => 0, 'artifact' => 0, 'event' => 0, 'metric' => 0, 'rule' => 0, 'department' => 0];

		foreach ($entities as $entity)
		{
			$stats[$entity['class_type']] = (int) Arr::get($stats, $entity['class_type'], 0) + 1;
		}

		return [
			'entities'  => $entities,
			'processes' => $processes,
			'note'      => UTF8::substr(trim((string) Arr::get($raw, 'note', Arr::get($raw, 'summary', ''))), 0, 500),
			'model'     => UTF8::substr(trim((string) $model), 0, 160),
			'stats'     => $stats,
		];
	}

	/**
	 * Принять предложение: создать выбранные сущности и (по желанию) процессы.
	 *
	 * @param array $post entity[] = "role|lawyer", process[] = индексы, make_processes
	 */
	public static function accept($workspace_id, $user_id, $job_id, array $post)
	{
		$workspace_id = (int) $workspace_id;
		$job = self::job($job_id, $workspace_id);

		if ( ! $job)
		{
			return ['ok' => FALSE, 'error' => 'Предложение не найдено.'];
		}

		if ((string) $job['status'] !== self::S_DONE)
		{
			return ['ok' => FALSE, 'error' => 'Разбор ещё не готов: дождитесь ответа воркера.'];
		}

		if ( ! empty($job['accepted_at']))
		{
			return ['ok' => FALSE, 'error' => 'Это предложение уже принято. Запустите новый разбор, если данные изменились.'];
		}

		$result = (array) $job['result'];
		$selected = [];

		foreach ((array) Arr::get($post, 'entity', []) as $value)
		{
			$value = trim((string) $value);
			$parts = explode('|', $value, 2);

			if (count($parts) === 2 AND $parts[0] !== '' AND $parts[1] !== '')
			{
				$selected[$parts[0].'|'.$parts[1]] = TRUE;
			}
		}

		$created_entities = 0;
		$skipped = 0;

		foreach ((array) Arr::get($result, 'entities', []) as $entity)
		{
			$key = (string) $entity['class_type'].'|'.(string) $entity['code'];

			if ( ! isset($selected[$key]))
			{
				continue;
			}

			$created = Service_Nj670_Ontology::create($workspace_id, [
				'class_type' => (string) $entity['class_type'],
				'code'       => (string) $entity['code'],
				'name'       => (string) $entity['name'],
				'attributes' => (array) $entity['attributes'],
			]);

			if (Arr::get($created, 'ok'))
			{
				$created_entities++;
			}
			else
			{
				// Код заняли между разбором и приёмом — это не ошибка.
				$skipped++;
			}
		}

		// Свежий индекс: шаги процессов сопоставляются уже с созданными кодами.
		Service_Nj670_Ontology::reset($workspace_id);
		$index = Service_Nj670_Ontology::index($workspace_id);

		$created_processes = [];
		$failed = 0;

		if ( ! empty($post['make_processes']))
		{
			foreach ((array) Arr::get($post, 'process', []) as $raw_index)
			{
				$index_no = (int) $raw_index;
				$proposal = (array) Arr::get((array) Arr::get($result, 'processes', []), $index_no, []);

				if ( ! $proposal)
				{
					continue;
				}

				$created = self::create_process_from_proposal($user_id, $workspace_id, $proposal, (string) $job['source_text']);

				if (Arr::get($created, 'ok'))
				{
					$created_processes[] = (string) $created['code'];
				}
				else
				{
					$failed++;
				}
			}
		}

		$result['accepted'] = [
			'entities'  => $created_entities,
			'processes' => count($created_processes),
			'codes'     => $created_processes,
			'at'        => date('c'),
			'user_id'   => (int) $user_id,
		];

		self::_update('nj_670_ontology_jobs', [
			'result'      => $result,
			'accepted_at' => date('c'),
		], (int) $job['id'], ['result']);

		self::_event(0, (int) $user_id, 'ontology.accepted',
			'Принято предложение: '.$created_entities.' сущностей, '.count($created_processes).' процессов',
			['ontology_job_id' => (int) $job['id'], 'codes' => $created_processes]);

		return [
			'ok'         => TRUE,
			'entities'   => $created_entities,
			'processes'  => count($created_processes),
			'skipped'    => $skipped,
			'failed'     => $failed,
			'codes'      => $created_processes,
		];
	}

	/**
	 * Черновик процесса из предложения: линейный граф «начало → шаги → конец».
	 * Ветвления по condition превращаются в условный переход, как в синтезе.
	 */
	public static function create_process_from_proposal($user_id, $workspace_id, array $proposal, $source_text = '')
	{
		$created = Service_Nj670_Process::create_process((int) $user_id, [
			'title'           => (string) Arr::get($proposal, 'title', 'Процесс из документа'),
			'description'     => (string) Arr::get($proposal, 'description', ''),
			'department'      => (string) Arr::get($proposal, 'department', ''),
			'owner_role_code' => (string) Arr::get($proposal, 'owner_role_code', ''),
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return $created;
		}

		$process_id = (int) $created['id'];
		$ontology = Service_Nj670_Ontology::index((int) $workspace_id);
		$graph = self::graph_from_steps((string) Arr::get($proposal, 'title', ''), (array) Arr::get($proposal, 'steps', []), $ontology);

		$version = Service_Nj670_Process::create_version($process_id, 'plan', [
			'status'      => Service_Nj670_Process::S_DRAFT,
			'graph'       => $graph,
			'source_kind' => 'ontology',
			'source_text' => UTF8::substr(trim((string) $source_text), 0, (int) self::limits('max_text', 20000)),
			'created_by'  => (int) $user_id,
			'model'       => 'ontology-feed',
			'activate'    => TRUE,
		]);

		if ( ! Arr::get($version, 'ok'))
		{
			return $version;
		}

		return ['ok' => TRUE, 'id' => $process_id, 'code' => (string) $created['code'], 'version_id' => (int) $version['id']];
	}

	public static function graph_from_steps($title, array $steps, array $ontology = [])
	{
		$nodes = [
			['id' => 'start', 'step_id' => 'start', 'type' => 'start', 'title' => 'Начало процесса', 'lane' => ''],
		];
		$edges = [];
		$prev = 'start';
		$i = 0;

		foreach ($steps as $step)
		{
			$i++;
			$id = 's'.$i;
			$type = self::_step_type((string) Arr::get($step, 'type', 'user_task'));
			$role = (string) Arr::get($step, 'role_code', '');
			$condition = trim((string) Arr::get($step, 'condition', ''));

			$nodes[] = [
				'id'                => $id,
				'step_id'           => $id,
				'type'              => $type,
				'title'             => UTF8::substr(trim((string) Arr::get($step, 'title', '')), 0, 255),
				'lane'              => $role,
				'role_code'         => $role,
				'system_code'       => (string) Arr::get($step, 'system_code', ''),
				'input_code'        => (string) Arr::get($step, 'input_code', ''),
				'output_code'       => (string) Arr::get($step, 'output_code', ''),
				'duration_minutes'  => (int) Arr::get($step, 'duration_minutes', 0),
				'wait_time_minutes' => (int) Arr::get($step, 'wait_time_minutes', 0),
				'properties'        => $condition !== '' ? ['condition' => $condition] : [],
			];

			$edges[] = [
				'from'      => $prev,
				'to'        => $id,
				'type'      => $condition !== '' ? 'conditional' : 'sequence',
				'condition' => $condition,
			];

			$prev = $id;
		}

		$nodes[] = ['id' => 'end', 'step_id' => 'end', 'type' => 'end', 'title' => 'Конец процесса', 'lane' => ''];
		$edges[] = ['from' => $prev, 'to' => 'end', 'type' => 'sequence', 'condition' => ''];

		$graph = [
			'lanes' => [],
			'nodes' => $nodes,
			'edges' => $edges,
			'meta'  => [
				'title'  => UTF8::substr(trim((string) $title), 0, 255),
				'source' => 'ontology',
				'mode'   => 'plan',
				'note'   => 'Черновик из разбора документа: проверьте роли, системы и сроки.',
			],
		];

		return Service_Nj670_Graph::normalize($graph, $ontology, [
			'reflow'    => TRUE,
			'mode'      => 'plan',
			'max_nodes' => (int) self::limits('max_nodes', 80),
			'max_edges' => (int) self::limits('max_edges', 160),
		]);
	}

	/**
	 * Подготовка для страницы: предложения, сгруппированные по классам, плюс
	 * пометки «уже есть в справочнике» — чтобы человек не создавал дубли.
	 */
	public static function view(array $job)
	{
		$result = (array) Arr::get($job, 'result', []);
		$groups = [];

		foreach (self::entity_types() as $type => $label)
		{
			$groups[$type] = ['label' => $label, 'items' => []];
		}

		foreach ((array) Arr::get($result, 'entities', []) as $entity)
		{
			$type = (string) $entity['class_type'];

			if ( ! isset($groups[$type]))
			{
				continue;
			}

			$groups[$type]['items'][] = $entity;
		}

		$processes = [];

		foreach ((array) Arr::get($result, 'processes', []) as $i => $process)
		{
			$process['index'] = (int) $i;
			$process['minutes'] = 0;
			$process['wait'] = 0;

			foreach ((array) Arr::get($process, 'steps', []) as $step)
			{
				$process['minutes'] += (int) Arr::get($step, 'duration_minutes', 0);
				$process['wait'] += (int) Arr::get($step, 'wait_time_minutes', 0);
			}

			$processes[] = $process;
		}

		return [
			'groups'    => $groups,
			'processes' => $processes,
			'stats'     => (array) Arr::get($result, 'stats', []),
			'note'      => (string) Arr::get($result, 'note', ''),
			'accepted'  => (array) Arr::get($result, 'accepted', []),
			'new_count' => count(array_filter((array) Arr::get($result, 'entities', []), function ($entity) {
				return empty($entity['exists']);
			})),
		];
	}

	public static function statuses()
	{
		return [
			self::S_QUEUED  => 'В очереди',
			self::S_RUNNING => 'Модель разбирает',
			self::S_DONE    => 'Готово к приёму',
			self::S_ERROR   => 'Ошибка',
		];
	}

	protected static function _code($value)
	{
		$value = Service_Nj670_Ontology::normalize_code($value);

		return UTF8::substr($value, 0, 100);
	}

	protected static function _step_type($type)
	{
		$type = mb_strtolower(trim((string) $type));
		$allowed = ['user_task', 'service_task', 'manual_task', 'gateway_xor', 'gateway_and', 'timer', 'subprocess'];

		if (in_array($type, $allowed, TRUE))
		{
			return $type;
		}

		// Русские подписи модели: «пользовательская задача», «автоматически».
		if (mb_strpos($type, 'авто') !== FALSE OR mb_strpos($type, 'сервис') !== FALSE)
		{
			return 'service_task';
		}

		if (mb_strpos($type, 'вручную') !== FALSE OR mb_strpos($type, 'ручн') !== FALSE)
		{
			return 'manual_task';
		}

		return 'user_task';
	}
}
