<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ProcessHub: рабочие пространства, процессы, версии и очередь.
 *
 * Единственная дверь в данные nj_670_*. Контроллеры не пишут SQL, воркер не
 * ходит в базу напрямую — он получает контекст и возвращает результат через
 * /process/w/*. Владение проверяется здесь, а не в шаблоне.
 */
class Service_Nj670_Process extends Service_Nj670_Repo
{
	const S_DRAFT     = 'draft';
	const S_QUEUED    = 'queued';
	const S_IN_REVIEW = 'in_review';
	const S_ACTIVE    = 'active';
	const S_ARCHIVED  = 'archived';
	const S_ERROR     = 'error';

	const CH_SYNTH = 'nj670.synthesize';
	const CH_MINE  = 'nj670.mine';
	const CH_DIFF  = 'nj670.diff';
	const CH_EXPORT= 'nj670.export';

	/**
	 * Пользователь для CLI-команд: id, e-mail или username. Нужен демо-сидеру,
	 * чтобы не дублировать SQL в Task.
	 */
	public static function resolve_user_id($needle = '')
	{
		$needle = trim((string) $needle);

		if ($needle !== '' AND ctype_digit($needle))
		{
			return (int) self::_value('SELECT id FROM users WHERE id = '.(int) $needle);
		}

		if ($needle !== '')
		{
			return (int) self::_value('SELECT id FROM users WHERE email = '.self::_q($needle).'
				OR username = '.self::_q($needle).' ORDER BY id LIMIT 1');
		}

		$admin = (int) self::_value("SELECT id FROM users WHERE email = 'admin@n2j.ru' LIMIT 1");

		return $admin > 0 ? $admin : (int) self::_value('SELECT id FROM users ORDER BY id LIMIT 1');
	}

	// ------------------------------------------------------------ пространства

	public static function workspace_for($user_id, $create = TRUE)
	{
		$user_id = (int) $user_id;

		if ($user_id <= 0)
		{
			return NULL;
		}

		$row = self::_one('SELECT * FROM nj_670_workspaces
			WHERE user_id = '.$user_id.' ORDER BY id LIMIT 1');

		if ($row)
		{
			return $row;
		}

		if ( ! $create)
		{
			return NULL;
		}

		$title = 'Рабочее пространство';

		try
		{
			$user = self::_one('SELECT username, email FROM users WHERE id = '.$user_id);

			if ($user AND trim((string) $user['username']) !== '')
			{
				$title = 'Процессы: '.$user['username'];
			}
		}
		catch (Throwable $e)
		{
			// users — таблица ядра; её недоступность не должна мешать создать
			// пространство с нейтральным названием.
		}

		$code = self::code_new(10);

		$id = self::_insert('nj_670_workspaces', [
			'user_id'      => $user_id,
			'code'         => $code,
			'title'        => UTF8::substr($title, 0, 255),
			'company_name' => '',
			'settings'     => [],
		], ['settings']);

		if ($id <= 0)
		{
			return NULL;
		}

		Service_Nj670_Ontology::ensure_defaults($id);

		return self::_one('SELECT * FROM nj_670_workspaces WHERE id = '.$id);
	}

	public static function workspace($id)
	{
		return self::_one('SELECT * FROM nj_670_workspaces WHERE id = '.(int) $id);
	}

	public static function workspace_update($id, array $data)
	{
		$row = self::workspace($id);

		if ( ! $row)
		{
			return ['ok' => FALSE, 'error' => 'Рабочее пространство не найдено.'];
		}

		$title = UTF8::substr(trim((string) Arr::get($data, 'title', $row['title'])), 0, 255);
		$company = UTF8::substr(trim((string) Arr::get($data, 'company_name', $row['company_name'])), 0, 255);

		if ($title === '')
		{
			return ['ok' => FALSE, 'error' => 'Название пространства не может быть пустым.'];
		}

		self::_update('nj_670_workspaces', [
			'title'        => $title,
			'company_name' => $company,
		], (int) $id);

		return ['ok' => TRUE];
	}

	// ------------------------------------------------------------ процессы

	public static function create_process($user_id, array $data)
	{
		$workspace = self::workspace_for($user_id);

		if ( ! $workspace)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось открыть рабочее пространство.'];
		}

		$count = (int) self::_value('SELECT count(*) FROM nj_670_processes
			WHERE workspace_id = '.(int) $workspace['id'].' AND status = \'active\'');

		if ($count >= (int) self::limits('processes_per_user', 100))
		{
			return ['ok' => FALSE, 'error' => 'Достигнут лимит процессов ('.(int) self::limits('processes_per_user', 100).').'];
		}

		$title = UTF8::substr(trim((string) Arr::get($data, 'title', '')), 0, 255);

		if ($title === '')
		{
			$title = 'Новый процесс';
		}

		$description = UTF8::substr(trim((string) Arr::get($data, 'description', '')), 0, 5000);
		$department = UTF8::substr(trim((string) Arr::get($data, 'department', '')), 0, 255);
		$owner_code = trim((string) Arr::get($data, 'owner_role_code', ''));
		$owner_id = 0;

		if ($owner_code !== '')
		{
			$index = Service_Nj670_Ontology::index((int) $workspace['id']);
			$owner_id = (int) Arr::get((array) Arr::path($index['by_code'], 'role.'.$owner_code, []), 'id', 0);
		}

		// Родитель — только процесс своего пространства: иначе чужая ветка
		// попадёт в карту организации и покажет соседей.
		$parent_id = 0;

		if ( ! empty($data['parent_id']))
		{
			$parent = self::process_by_id((int) $data['parent_id']);

			if ($parent AND (int) $parent['workspace_id'] === (int) $workspace['id'])
			{
				$parent_id = (int) $parent['id'];
			}
		}
		elseif ( ! empty($data['parent_code']))
		{
			$parent = self::process((string) $data['parent_code']);

			if ($parent AND (int) $parent['workspace_id'] === (int) $workspace['id'])
			{
				$parent_id = (int) $parent['id'];
			}
		}

		$code = self::_unique_code('nj_670_processes', self::code_new(12));

		$id = self::_insert('nj_670_processes', [
			'workspace_id'  => (int) $workspace['id'],
			'code'          => $code,
			'title'         => $title,
			'description'   => $description,
			'department'    => $department,
			'owner_role_id' => $owner_id > 0 ? $owner_id : NULL,
			'parent_id'     => $parent_id > 0 ? $parent_id : NULL,
			'status'        => 'active',
			'created_by'    => (int) $user_id,
			'is_demo'       => ! empty($data['is_demo']),
		]);

		self::_event($id, $user_id, 'process.created', 'Создан процесс «'.$title.'»');

		return ['ok' => TRUE, 'id' => $id, 'code' => $code, 'process' => self::process_by_id($id)];
	}

	/**
	 * Создать процесс и сразу наполнить его схемой из текста.
	 *
	 * Общая точка для страницы «Новый процесс», JSON API и MCP: агент и
	 * человек получают одинаковое поведение — живой воркер собирает модель,
	 * без воркера срабатывает правиловый фолбэк (если он разрешён).
	 *
	 * @return array created + queued|fallback|version_id
	 */
	public static function create_with_graph($user_id, array $data)
	{
		$created = self::create_process($user_id, $data);

		if ( ! Arr::get($created, 'ok'))
		{
			return $created;
		}

		$process = $created['process'];
		$text = trim((string) Arr::get($data, 'text', ''));

		if ($text === '')
		{
			return $created + ['queued' => FALSE, 'empty' => TRUE];
		}

		$online = self::worker_online();

		if ($online)
		{
			$queued = self::queue_synthesize((int) $process['id'], (int) $user_id, [
				'text' => $text,
				'mode' => 'full',
			]);

			if (Arr::get($queued, 'ok'))
			{
				return $created + [
					'queued' => TRUE,
					'job_id' => (int) Arr::get($queued, 'job_id', 0),
				];
			}
		}

		if ( ! (bool) Arr::get(self::config(), 'allow_rule_fallback', TRUE))
		{
			return $created + [
				'queued' => FALSE,
				'error'  => $online
					? 'Очередь недоступна, а правиловый фолбэк выключен.'
					: 'Воркер не в сети, а правиловый фолбэк выключен.',
			];
		}

		$workspace = self::workspace_for($user_id);
		$build = Service_Nj670_Synthesis::from_text($text, Service_Nj670_Ontology::index((int) $workspace['id']), [
			'title' => (string) $process['title'],
			'mode'  => 'plan',
		]);

		if ( ! Arr::get($build, 'ok'))
		{
			return $created + [
				'queued' => FALSE,
				'error'  => (string) Arr::get($build, 'error', 'Не удалось собрать схему по правилам.'),
			];
		}

		$version = self::create_version((int) $process['id'], 'plan', [
			'status'      => self::S_IN_REVIEW,
			'graph'       => $build['graph'],
			'source_kind' => 'text',
			'source_text' => $text,
			'created_by'  => (int) $user_id,
			'model'       => 'rules-engine',
			'activate'    => TRUE,
		]);

		return $created + [
			'queued'     => FALSE,
			'fallback'   => TRUE,
			'version_id' => (int) Arr::get($version, 'id', 0),
			'warning'    => $online
				? 'Очередь недоступна — схема собрана правилами.'
				: 'Воркер не в сети — схема собрана правилами.',
		];
	}

	protected static function _unique_code($table, $code)
	{
		$base = $code;
		$i = 2;

		while ((int) self::_value('SELECT count(*) FROM '.$table.' WHERE code = '.self::_q($code)) > 0)
		{
			$code = UTF8::substr($base, 0, 20).$i;
			$i++;

			if ($i > 50)
			{
				$code = self::code_new(12);
				$base = $code;
				$i = 2;
			}
		}

		return $code;
	}

	public static function process($code)
	{
		$code = trim((string) $code);

		if ( ! self::code_valid($code))
		{
			return NULL;
		}

		return self::_one('SELECT p.*, w.user_id AS workspace_user_id, w.title AS workspace_title,
				w.company_name, e.name AS owner_role_name, e.code AS owner_role_code
			FROM nj_670_processes p
			JOIN nj_670_workspaces w ON w.id = p.workspace_id
			LEFT JOIN nj_670_entities e ON e.id = p.owner_role_id
			WHERE p.code = '.self::_q($code).' LIMIT 1');
	}

	public static function process_by_id($id)
	{
		return self::_one('SELECT p.*, w.user_id AS workspace_user_id, w.title AS workspace_title,
				w.company_name, e.name AS owner_role_name, e.code AS owner_role_code
			FROM nj_670_processes p
			JOIN nj_670_workspaces w ON w.id = p.workspace_id
			LEFT JOIN nj_670_entities e ON e.id = p.owner_role_id
			WHERE p.id = '.(int) $id.' LIMIT 1');
	}

	public static function can_view(array $process, $user_id, $is_admin = FALSE)
	{
		return $is_admin OR (int) Arr::get($process, 'workspace_user_id', 0) === (int) $user_id;
	}

	/**
	 * Дерево процессов: родитель → дети. Плоский список превращаем в карту,
	 * чтобы карточки и карта не строили O(n²) фильтров.
	 */
	public static function tree(array $processes)
	{
		$by_id = [];
		$children = [];

		foreach ($processes as $process)
		{
			$by_id[(int) $process['id']] = $process;
		}

		foreach ($processes as $process)
		{
			$parent = (int) Arr::get($process, 'parent_id', 0);
			$children[$parent][] = (int) $process['id'];
		}

		return ['by_id' => $by_id, 'children' => $children];
	}

	public static function children($process_id, $workspace_id = 0)
	{
		$sql = 'SELECT p.* FROM nj_670_processes p WHERE p.parent_id = '.(int) $process_id;

		if ((int) $workspace_id > 0)
		{
			$sql .= ' AND p.workspace_id = '.(int) $workspace_id;
		}

		return self::_all($sql.' ORDER BY p.title');
	}

	/**
	 * Связи «вызывает»: шаги-подпроцессы ссылаются на код другого процесса.
	 * Источник — properties.process_code в сохранённых схемах.
	 */
	public static function calls($workspace_id)
	{
		try
		{
			$rows = self::_all('SELECT p.id, p.code, p.title, pv.graph_topology
				FROM nj_670_processes p
				LEFT JOIN nj_670_versions pv ON pv.id = p.plan_version_id
				WHERE p.workspace_id = '.(int) $workspace_id);
		}
		catch (Throwable $e)
		{
			return [];
		}

		$edges = [];
		$seen = [];

		foreach ($rows as $row)
		{
			$graph = self::json_decode(Arr::get($row, 'graph_topology', []));
			$nodes = (array) Arr::get($graph, 'nodes', []);

			foreach ($nodes as $node)
			{
				$target = trim((string) Arr::get((array) Arr::get($node, 'properties', []), 'process_code', ''));

				if ($target === '' OR $target === (string) $row['code'])
				{
					continue;
				}

				$key = $row['code'].'>'.$target;

				if (isset($seen[$key]))
				{
					continue;
				}

				$seen[$key] = TRUE;
				$edges[] = [
					'from_code'  => (string) $row['code'],
					'from_title' => (string) $row['title'],
					'to_code'    => $target,
					'step'       => (string) Arr::get($node, 'title', ''),
				];
			}
		}

		return $edges;
	}

	public static function for_workspace($workspace_id, $limit = 100)
	{
		$workspace_id = (int) $workspace_id;
		$limit = max(1, min(500, (int) $limit));

		$rows = self::_all('SELECT p.*,
				(SELECT count(*) FROM nj_670_versions v WHERE v.process_id = p.id) AS versions_count,
				pv.metrics AS plan_metrics, pv.total_duration_minutes AS plan_minutes,
				pv.total_cost_rub AS plan_cost, pv.status AS plan_status,
				fv.metrics AS fact_metrics, fv.total_duration_minutes AS fact_minutes,
				fv.total_cost_rub AS fact_cost, fv.status AS fact_status,
				(SELECT a.status FROM nj_670_audits a WHERE a.process_id = p.id
					ORDER BY a.id DESC LIMIT 1) AS audit_status,
				(SELECT a.total_impact_rub FROM nj_670_audits a WHERE a.process_id = p.id
					AND a.status = \'done\' ORDER BY a.id DESC LIMIT 1) AS audit_impact,
				(SELECT count(*) FROM nj_670_audits a WHERE a.process_id = p.id
					AND a.status = \'done\') AS audits_count,
				(SELECT count(*) FROM nj_670_reports r WHERE r.process_id = p.id
					AND r.status = \'done\') AS reports_count,
				e.name AS owner_role_name
			FROM nj_670_processes p
			LEFT JOIN nj_670_versions pv ON pv.id = p.plan_version_id
			LEFT JOIN nj_670_versions fv ON fv.id = p.fact_version_id
			LEFT JOIN nj_670_entities e ON e.id = p.owner_role_id
			WHERE p.workspace_id = '.$workspace_id.'
			ORDER BY p.updated_at DESC
			LIMIT '.$limit);

		foreach ($rows as &$row)
		{
			$row['plan_metrics'] = self::json_decode(Arr::get($row, 'plan_metrics', []));
			$row['fact_metrics'] = self::json_decode(Arr::get($row, 'fact_metrics', []));
		}
		unset($row);

		return $rows;
	}

	/**
	 * Фильтр реестра: поиск по названию/коду, подразделение, владелец,
	 * наличие факта/аудита. Работает по уже выбранному списку — отдельный
	 * SQL не нужен, реестр на одну компанию редко больше пары сотен строк.
	 */
	public static function filter(array $processes, array $query)
	{
		$q = mb_strtolower(trim((string) Arr::get($query, 'q', '')));
		$department = trim((string) Arr::get($query, 'department', ''));
		$owner = (int) Arr::get($query, 'owner', 0);
		$state = (string) Arr::get($query, 'state', '');

		$out = [];

		foreach ($processes as $process)
		{
			if ($q !== '')
			{
				$haystack = mb_strtolower((string) Arr::get($process, 'title', '').' '
					.(string) Arr::get($process, 'code', '').' '
					.(string) Arr::get($process, 'description', '').' '
					.(string) Arr::get($process, 'department', '').' '
					.(string) Arr::get($process, 'owner_role_name', ''));

				if (mb_strpos($haystack, $q) === FALSE)
				{
					continue;
				}
			}

			if ($department !== '' AND (string) Arr::get($process, 'department', '') !== $department)
			{
				continue;
			}

			if ($owner > 0 AND (int) Arr::get($process, 'owner_role_id', 0) !== $owner)
			{
				continue;
			}

			if ($state === 'no_fact' AND (int) Arr::get($process, 'fact_minutes', 0) > 0)
			{
				continue;
			}

			if ($state === 'no_owner' AND (int) Arr::get($process, 'owner_role_id', 0) > 0)
			{
				continue;
			}

			if ($state === 'audited' AND (int) Arr::get($process, 'audits_count', 0) <= 0)
			{
				continue;
			}

			$out[] = $process;
		}

		return $out;
	}

	/**
	 * Справочник подразделений и владельцев для фильтра реестра.
	 */
	public static function facets(array $processes)
	{
		$departments = [];
		$owners = [];

		foreach ($processes as $process)
		{
			$department = trim((string) Arr::get($process, 'department', ''));

			if ($department !== '')
			{
				$departments[$department] = TRUE;
			}

			$owner_id = (int) Arr::get($process, 'owner_role_id', 0);
			$owner_name = trim((string) Arr::get($process, 'owner_role_name', ''));

			if ($owner_id > 0 AND $owner_name !== '')
			{
				$owners[$owner_id] = $owner_name;
			}
		}

		ksort($departments);
		asort($owners);

		return ['departments' => array_keys($departments), 'owners' => $owners];
	}

	public static function update_process($process_id, array $data)
	{
		$process = self::process_by_id($process_id);

		if ( ! $process)
		{
			return ['ok' => FALSE, 'error' => 'Процесс не найден.'];
		}

		$title = UTF8::substr(trim((string) Arr::get($data, 'title', $process['title'])), 0, 255);

		if ($title === '')
		{
			return ['ok' => FALSE, 'error' => 'Название процесса не может быть пустым.'];
		}

		$status = (string) Arr::get($data, 'status', $process['status']);

		if ( ! in_array($status, ['active', 'archived'], TRUE))
		{
			$status = 'active';
		}

		$owner_code = trim((string) Arr::get($data, 'owner_role_code', ''));
		$owner_id = $process['owner_role_id'];

		if ($owner_code !== '')
		{
			$index = Service_Nj670_Ontology::index((int) $process['workspace_id']);
			$owner_id = (int) Arr::get((array) Arr::path($index['by_code'], 'role.'.$owner_code, []), 'id', 0);
		}

		self::_update('nj_670_processes', [
			'title'         => $title,
			'description'   => UTF8::substr(trim((string) Arr::get($data, 'description', $process['description'])), 0, 5000),
			'department'    => UTF8::substr(trim((string) Arr::get($data, 'department', $process['department'])), 0, 255),
			'owner_role_id' => $owner_id > 0 ? (int) $owner_id : NULL,
			'status'        => $status,
		], (int) $process_id);

		return ['ok' => TRUE];
	}

	public static function delete_process($process_id, $user_id)
	{
		$process = self::process_by_id($process_id);

		if ( ! $process)
		{
			return ['ok' => FALSE, 'error' => 'Процесс не найден.'];
		}

		// Сначала файлы отчётов (каскад БД не умеет удалять файлы).
		Service_Nj670_Report::delete_for_process((int) $process_id);

		$files = self::_all('SELECT stored_path FROM nj_670_imports
			WHERE process_id = '.(int) $process_id);

		foreach ($files as $file)
		{
			$path = (string) $file['stored_path'];

			if ($path !== '')
			{
				$full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
					.str_replace('/', DIRECTORY_SEPARATOR, $path);

				if (is_file($full))
				{
					@unlink($full);
				}
			}
		}

		self::_exec('DELETE FROM nj_670_processes WHERE id = '.(int) $process_id);
		self::_event(0, $user_id, 'process.deleted', 'Удалён процесс «'.(string) $process['title'].'»');

		return ['ok' => TRUE];
	}

	public static function touch_process($process_id)
	{
		self::_exec('UPDATE nj_670_processes SET updated_at = now() WHERE id = '.(int) $process_id);
	}

	// ------------------------------------------------------------ версии

	public static function version($version_id)
	{
		return self::_one('SELECT v.*, p.code AS process_code, p.title AS process_title,
				p.workspace_id, p.plan_version_id, p.fact_version_id, p.owner_role_id,
				e.code AS owner_role_code, e.name AS owner_role_name
			FROM nj_670_versions v
			JOIN nj_670_processes p ON p.id = v.process_id
			LEFT JOIN nj_670_entities e ON e.id = p.owner_role_id
			WHERE v.id = '.(int) $version_id.' LIMIT 1');
	}

	public static function versions($process_id, $kind = NULL, $limit = 20)
	{
		$sql = 'SELECT * FROM nj_670_versions WHERE process_id = '.(int) $process_id;

		if ($kind !== NULL AND in_array($kind, ['plan', 'fact'], TRUE))
		{
			$sql .= ' AND kind = '.self::_q($kind);
		}

		$sql .= ' ORDER BY id DESC LIMIT '.max(1, min(100, (int) $limit));

		return self::_all($sql);
	}

	public static function current_version(array $process, $kind = 'plan')
	{
		$kind = $kind === 'fact' ? 'fact' : 'plan';
		$pointer = $kind === 'plan' ? 'plan_version_id' : 'fact_version_id';
		$id = (int) Arr::get($process, $pointer, 0);

		if ($id > 0)
		{
			$row = self::version($id);

			if ($row)
			{
				return $row;
			}
		}

		$row = self::_one('SELECT v.*, p.code AS process_code, p.title AS process_title,
				p.workspace_id, p.plan_version_id, p.fact_version_id, p.owner_role_id,
				e.code AS owner_role_code, e.name AS owner_role_name
			FROM nj_670_versions v
			JOIN nj_670_processes p ON p.id = v.process_id
			LEFT JOIN nj_670_entities e ON e.id = p.owner_role_id
			WHERE v.process_id = '.(int) $process['id'].'
			  AND v.kind = '.self::_q($kind).'
			  AND v.status <> \'archived\'
			ORDER BY v.id DESC LIMIT 1');

		return $row;
	}

	public static function graph_of(array $version)
	{
		return Service_Nj670_Graph::normalize(
			self::json_decode(Arr::get($version, 'graph_topology', [])),
			Service_Nj670_Ontology::index((int) Arr::get($version, 'workspace_id', 0)),
			['reflow' => FALSE, 'mode' => (string) Arr::get($version, 'kind', 'plan')]
		);
	}

	public static function create_version($process_id, $kind, array $data = [])
	{
		$process = self::process_by_id($process_id);

		if ( ! $process)
		{
			return ['ok' => FALSE, 'error' => 'Процесс не найден.'];
		}

		$kind = $kind === 'fact' ? 'fact' : 'plan';
		$next = (int) self::_value('SELECT count(*) FROM nj_670_versions
			WHERE process_id = '.(int) $process_id.' AND kind = '.self::_q($kind)) + 1;

		$tag = trim((string) Arr::get($data, 'version_tag', ''));

		if ($tag === '')
		{
			$tag = $kind === 'plan' ? 'plan-v'.$next : 'fact-'.$next;
		}

		$graph = Arr::get($data, 'graph', NULL);
		$graph = is_array($graph)
			? Service_Nj670_Graph::normalize($graph, Service_Nj670_Ontology::index((int) $process['workspace_id']))
			: Service_Nj670_Graph::empty_graph((string) $process['title']);

		$metrics = Service_Nj670_Graph::metrics($graph, Service_Nj670_Ontology::index((int) $process['workspace_id']));

		// Календарный срок пишем и в meta: иначе в JSONB остаётся 0, а в
		// колонке — 1440, и любой внешний читатель графа видит «ноль минут».
		if ((int) Arr::get((array) Arr::get($graph, 'meta', []), 'elapsed_minutes', 0) <= 0)
		{
			$graph['meta']['elapsed_minutes'] = (int) $metrics['elapsed_minutes'];
		}

		$id = self::_insert('nj_670_versions', [
			'process_id'             => (int) $process_id,
			'kind'                   => $kind,
			'version_tag'            => UTF8::substr($tag, 0, 50),
			'status'                 => (string) Arr::get($data, 'status', self::S_DRAFT),
			'graph_topology'         => $graph,
			'total_duration_minutes' => (int) $metrics['elapsed_minutes'],
			'total_cost_rub'         => (float) $metrics['cost_rub'],
			'source_kind'            => UTF8::substr((string) Arr::get($data, 'source_kind', 'manual'), 0, 20),
			'source_text'            => (string) Arr::get($data, 'source_text', ''),
			'metrics'                => $metrics,
			'created_by'             => (int) Arr::get($data, 'created_by', 0),
			'model'                  => UTF8::substr((string) Arr::get($data, 'model', ''), 0, 160),
		], ['graph_topology', 'metrics']);

		if ($id <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Не удалось создать версию.'];
		}

		self::_sync_index($id, $graph, (int) $process['workspace_id']);

		if ( ! empty($data['activate']))
		{
			$status = (string) Arr::get($data, 'status', self::S_DRAFT);

			if (in_array($status, [self::S_DRAFT, self::S_IN_REVIEW], TRUE))
			{
				// Черновик и «на рассмотрении» показываем на холсте, но не
				// объявляем утверждёнными: указатель процесса переводим, статус
				// оставляем прежним — до кнопки «Утвердить план».
				self::point_to((int) $process_id, (int) $id, $kind);
			}
			else
			{
				self::activate_version((int) $process_id, $id, (int) Arr::get($data, 'created_by', 0));
			}
		}

		return ['ok' => TRUE, 'id' => $id, 'version' => self::version($id)];
	}

	/**
	 * Сделать версию текущей, не меняя её статус. Нужно для черновиков и
	 * «на рассмотрении»: холст должен показывать новую схему, но зелёная
	 * плашка «Утверждена» появляется только после approve.
	 */
	public static function point_to($process_id, $version_id, $kind = 'plan')
	{
		$kind = $kind === 'fact' ? 'fact' : 'plan';

		self::_exec('UPDATE nj_670_processes SET '
			.($kind === 'fact' ? 'fact_version_id' : 'plan_version_id').' = '.(int) $version_id.',
			updated_at = now()
			WHERE id = '.(int) $process_id);
	}

	public static function activate_version($process_id, $version_id, $user_id = 0)
	{
		$process = self::process_by_id($process_id);
		$version = self::version($version_id);

		if ( ! $process OR ! $version OR (int) $version['process_id'] !== (int) $process_id)
		{
			return ['ok' => FALSE, 'error' => 'Версия не найдена.'];
		}

		$kind = (string) $version['kind'];
		$db = self::_db();
		$db->begin();

		try
		{
			self::_exec('UPDATE nj_670_versions SET status = \'archived\', updated_at = now()
				WHERE process_id = '.(int) $process_id.'
				  AND kind = '.self::_q($kind).'
				  AND id <> '.(int) $version_id.'
				  AND status IN (\'active\', \'in_review\')');

			self::_exec('UPDATE nj_670_versions SET status = \'active\', updated_at = now()
				WHERE id = '.(int) $version_id);

			self::_exec('UPDATE nj_670_processes SET '
				.($kind === 'plan' ? 'plan_version_id' : 'fact_version_id').' = '.(int) $version_id.',
				updated_at = now()
				WHERE id = '.(int) $process_id);

			$db->commit();
		}
		catch (Throwable $e)
		{
			$db->rollback();
			self::_log(Log::ERROR, 'активация версии :id: :error', [
				':id' => $version_id, ':error' => $e->getMessage(),
			]);

			return ['ok' => FALSE, 'error' => 'Не удалось утвердить версию.'];
		}

		self::_event((int) $process_id, $user_id, 'version.activated',
			'Утверждена '.($kind === 'plan' ? 'схема План' : 'схема Факт').' '.$version['version_tag']);

		return ['ok' => TRUE];
	}

	/**
	 * Сохранить граф: нормализация, метрики, замена семантического индекса.
	 * $opts['reflow'] = TRUE пересчитывает раскладку (синтез/импорт),
	 * FALSE сохраняет координаты холста.
	 */
	public static function save_graph($version_id, array $graph, $user_id = 0, array $opts = [])
	{
		$version = self::version($version_id);

		if ( ! $version)
		{
			return ['ok' => FALSE, 'error' => 'Версия не найдена.'];
		}

		$workspace_id = (int) $version['workspace_id'];
		$ontology = Service_Nj670_Ontology::index($workspace_id);

		$clean = Service_Nj670_Graph::normalize($graph, $ontology, [
			'reflow'   => ! empty($opts['reflow']),
			'mode'     => (string) $version['kind'],
			'max_nodes'=> (int) self::limits('nodes_per_graph', 80),
		]);

		if ( ! $clean['nodes'])
		{
			return ['ok' => FALSE, 'error' => 'В схеме нет шагов.'];
		}

		$metrics = Service_Nj670_Graph::metrics($clean, $ontology);

		if ((int) Arr::get((array) Arr::get($clean, 'meta', []), 'elapsed_minutes', 0) <= 0)
		{
			$clean['meta']['elapsed_minutes'] = (int) $metrics['elapsed_minutes'];
		}

		$db = self::_db();
		$db->begin();

		try
		{
			self::_exec('UPDATE nj_670_versions SET
					graph_topology = '.self::_q(self::json_encode($clean)).'::jsonb,
					metrics = '.self::_q(self::json_encode($metrics)).'::jsonb,
					total_duration_minutes = '.(int) $metrics['elapsed_minutes'].',
					total_cost_rub = '.number_format((float) $metrics['cost_rub'], 2, '.', '').',
					error = \'\',
					updated_at = now()
				WHERE id = '.(int) $version_id);

			self::_sync_index($version_id, $clean, $workspace_id);
			$db->commit();
		}
		catch (Throwable $e)
		{
			$db->rollback();
			self::_log(Log::ERROR, 'сохранение графа версии :id: :error', [
				':id' => $version_id, ':error' => $e->getMessage(),
			]);

			return ['ok' => FALSE, 'error' => 'Не удалось сохранить схему.'];
		}

		self::touch_process((int) $version['process_id']);
		self::_event((int) $version['process_id'], $user_id, 'graph.saved',
			'Сохранена схема '.$version['version_tag'].': '.count($clean['nodes']).' шагов');

		return [
			'ok'      => TRUE,
			'graph'   => $clean,
			'metrics' => $metrics,
			'version' => self::version($version_id),
		];
	}

	protected static function _sync_index($version_id, array $graph, $workspace_id)
	{
		$index = Service_Nj670_Ontology::index($workspace_id);
		$roles = (array) Arr::get($index['by_code'], 'role', []);
		$systems = (array) Arr::get($index['by_code'], 'system', []);
		$artifacts = (array) Arr::get($index['by_code'], 'artifact', []);

		self::_exec('DELETE FROM nj_670_edges WHERE version_id = '.(int) $version_id);
		self::_exec('DELETE FROM nj_670_nodes WHERE version_id = '.(int) $version_id);

		foreach ($graph['nodes'] as $node)
		{
			self::_insert('nj_670_nodes', [
				'version_id'         => (int) $version_id,
				'step_id'            => $node['step_id'],
				'node_type'          => $node['type'],
				'title'              => $node['title'],
				'lane_id'            => $node['lane'],
				'role_id'            => (int) Arr::get((array) Arr::get($roles, $node['role_code'], []), 'id', 0) ?: NULL,
				'system_id'          => (int) Arr::get((array) Arr::get($systems, $node['system_code'], []), 'id', 0) ?: NULL,
				'input_artifact_id'  => (int) Arr::get((array) Arr::get($artifacts, $node['input_code'], []), 'id', 0) ?: NULL,
				'output_artifact_id' => (int) Arr::get((array) Arr::get($artifacts, $node['output_code'], []), 'id', 0) ?: NULL,
				'duration_minutes'   => (int) $node['duration_minutes'],
				'wait_time_minutes'  => (int) $node['wait_time_minutes'],
				'position_x'         => (int) $node['x'],
				'position_y'         => (int) $node['y'],
				'properties'         => $node['properties'],
			], ['properties']);
		}

		foreach ($graph['edges'] as $i => $edge)
		{
			self::_insert('nj_670_edges', [
				'version_id'           => (int) $version_id,
				'source_step_id'       => $edge['from'],
				'target_step_id'       => $edge['to'],
				'transition_type'      => $edge['type'],
				'condition_expression' => $edge['condition'],
				'weight'               => (float) $edge['weight'],
				'avg_minutes'          => (int) $edge['avg_minutes'],
				'properties'           => $edge['properties'],
			], ['properties']);
		}
	}

	// ------------------------------------------------------------ очередь

	public static function quota($user_id, $kind = 'synth')
	{
		$user_id = (int) $user_id;
		$key = $kind === 'audit' ? 'audit_per_day' : ($kind === 'report' ? 'report_per_day' : 'synth_per_day');
		$max = (int) self::limits($key, 10);

		$used = 0;

		try
		{
			if ($kind === 'audit')
			{
				$used = (int) self::_value('SELECT count(*) FROM nj_670_audits
					WHERE created_by = '.$user_id.' AND created_at > now() - interval \'24 hours\'');
			}
			elseif ($kind === 'report')
			{
				$used = (int) self::_value('SELECT count(*) FROM nj_670_reports
					WHERE created_by = '.$user_id.' AND created_at > now() - interval \'24 hours\'');
			}
			else
			{
				$used = (int) self::_value('SELECT count(*) FROM nj_670_versions v
					JOIN nj_670_processes p ON p.id = v.process_id
					JOIN nj_670_workspaces w ON w.id = p.workspace_id
					WHERE w.user_id = '.$user_id.'
					  AND v.source_kind IN (\'text\', \'file\', \'copilot\')
					  AND v.created_at > now() - interval \'24 hours\'');
			}
		}
		catch (Throwable $e)
		{
			$used = 0;
		}

		return [
			'used'  => $used,
			'max'   => max(1, $max),
			'left'  => max(0, max(1, $max) - $used),
			'label' => $kind === 'audit' ? 'аудитов' : ($kind === 'report' ? 'регламентов' : 'сборок'),
		];
	}

	public static function rate_text()
	{
		$synth = self::limits('synth_per_day', 10);
		$audit = self::limits('audit_per_day', 10);

		return 'до '.$synth.' сборок и '.$audit.' аудитов в сутки на аккаунт';
	}

	public static function queue_synthesize($process_id, $user_id, array $data)
	{
		if ( ! (bool) Arr::get(self::config(), 'enabled', TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Приём задач временно выключен администратором.'];
		}

		$process = self::process_by_id($process_id);

		if ( ! $process)
		{
			return ['ok' => FALSE, 'error' => 'Процесс не найден.'];
		}

		$quota = self::quota($user_id, 'synth');

		if ($quota['left'] <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Дневной лимит сборок исчерпан ('.$quota['max'].'). Повторите завтра.'];
		}

		$mode = (string) Arr::get($data, 'mode', 'full');
		$mode = $mode === 'copilot' ? 'copilot' : 'full';

		$text = trim((string) Arr::get($data, 'text', ''));
		$text = UTF8::substr($text, 0, (int) self::limits('max_text', 20000));
		$instruction = trim((string) Arr::get($data, 'instruction', ''));
		$instruction = UTF8::substr($instruction, 0, 2000);
		$node_id = trim((string) Arr::get($data, 'node_id', ''));

		if ($mode === 'copilot' AND $instruction === '')
		{
			return ['ok' => FALSE, 'error' => 'Опишите, что добавить в схему.'];
		}

		if ($mode === 'full' AND $text === '')
		{
			return ['ok' => FALSE, 'error' => 'Вставьте текст регламента.'];
		}

		$current = self::current_version($process, 'plan');
		$graph = $current ? self::graph_of($current) : Service_Nj670_Graph::empty_graph((string) $process['title']);

		$source = (array) Arr::get($data, 'source', []);
		$source_kind = $mode === 'copilot' ? 'copilot'
			: (string) Arr::get($source, 'kind', 'text');
		$source_kind = in_array($source_kind, ['text', 'pdf', 'image', 'audio'], TRUE) ? $source_kind : 'text';

		$created = self::create_version((int) $process_id, 'plan', [
			'status'      => self::S_QUEUED,
			'graph'       => $graph,
			'source_kind' => $source_kind,
			'source_text' => $text,
			'created_by'  => (int) $user_id,
			'model'       => self::model(),
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return $created;
		}

		$version_id = (int) $created['id'];

		$payload = [
			'process_id' => (int) $process_id,
			'version_id' => $version_id,
			'kind'       => 'plan',
			'mode'       => $mode,
			'node_id'    => $node_id,
			'instruction'=> $instruction,
			'model'      => self::model(),
		];

		if ($source_kind !== 'text' AND (string) Arr::get($source, 'stored_path', '') !== '')
		{
			$payload['source'] = [
				'kind'       => $source_kind,
				'filename'   => (string) Arr::get($source, 'filename', ''),
				'stored_path'=> (string) $source['stored_path'],
			];
		}

		$queued = self::_push(self::CH_SYNTH, $payload, (int) $user_id, 'synthesize');

		if ( ! Arr::get($queued, 'ok'))
		{
			self::_fail_version($version_id, (string) Arr::get($queued, 'error', 'Очередь недоступна.'));
			return $queued;
		}

		self::_exec('UPDATE nj_670_versions SET job_id = '.(int) $queued['job_id'].'
			WHERE id = '.$version_id);

		self::_event((int) $process_id, $user_id, 'synth.queued',
			$mode === 'copilot' ? 'AI-достройка схемы поставлена в очередь' : 'Синтез схемы поставлен в очередь',
			['job_id' => (int) $queued['job_id']]);

		return [
			'ok'         => TRUE,
			'job_id'     => (int) $queued['job_id'],
			'version_id' => $version_id,
			'mode'       => $mode,
		];
	}

	public static function queue_mine($process_id, $user_id, array $import)
	{
		$process = self::process_by_id($process_id);

		if ( ! $process)
		{
			return ['ok' => FALSE, 'error' => 'Процесс не найден.'];
		}

		$current = self::current_version($process, 'fact');
		$graph = $current ? self::graph_of($current) : Service_Nj670_Graph::empty_graph((string) $process['title']);

		$created = self::create_version((int) $process_id, 'fact', [
			'status'      => self::S_QUEUED,
			'graph'       => $graph,
			'source_kind' => (string) Arr::get($import, 'kind', 'csv'),
			'created_by'  => (int) $user_id,
			'model'       => 'process-mining',
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return $created;
		}

		$version_id = (int) $created['id'];

		$import_id = self::_insert('nj_670_imports', [
			'process_id'  => (int) $process_id,
			'user_id'     => (int) $user_id,
			'kind'        => (string) Arr::get($import, 'kind', 'csv'),
			'filename'    => UTF8::substr((string) Arr::get($import, 'filename', ''), 0, 255),
			'stored_path' => (string) Arr::get($import, 'stored_path', ''),
			'size_bytes'  => (int) Arr::get($import, 'size_bytes', 0),
			'rows_count'  => (int) Arr::get($import, 'rows_count', 0),
			'status'      => 'queued',
		]);

		$queued = self::_push(self::CH_MINE, [
			'process_id' => (int) $process_id,
			'version_id' => $version_id,
			'import_id'  => $import_id,
		], (int) $user_id, 'mine');

		if ( ! Arr::get($queued, 'ok'))
		{
			self::_fail_version($version_id, (string) Arr::get($queued, 'error', 'Очередь недоступна.'));
			self::_update('nj_670_imports', [
				'status' => 'error',
				'error'  => (string) Arr::get($queued, 'error', 'Очередь недоступна.'),
			], $import_id);
			return $queued;
		}

		self::_exec('UPDATE nj_670_versions SET job_id = '.(int) $queued['job_id'].'
			WHERE id = '.$version_id);
		self::_exec('UPDATE nj_670_imports SET job_id = '.(int) $queued['job_id'].'
			WHERE id = '.$import_id);

		self::_event((int) $process_id, $user_id, 'mine.queued',
			'Журнал «'.(string) Arr::get($import, 'filename', 'файл').'» поставлен в разбор',
			['job_id' => (int) $queued['job_id']]);

		return [
			'ok'         => TRUE,
			'job_id'     => (int) $queued['job_id'],
			'version_id' => $version_id,
			'import_id'  => $import_id,
		];
	}

	public static function queue_audit($process_id, $user_id)
	{
		$process = self::process_by_id($process_id);

		if ( ! $process)
		{
			return ['ok' => FALSE, 'error' => 'Процесс не найден.'];
		}

		$plan = self::current_version($process, 'plan');
		$fact = self::current_version($process, 'fact');

		if ( ! $plan)
		{
			return ['ok' => FALSE, 'error' => 'Сначала соберите схему «План».'];
		}

		if ( ! $fact)
		{
			return ['ok' => FALSE, 'error' => 'Сначала загрузите журнал и соберите схему «Факт».'];
		}

		$quota = self::quota($user_id, 'audit');

		if ($quota['left'] <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Дневной лимит аудитов исчерпан ('.$quota['max'].').'];
		}

		$id = self::_insert('nj_670_audits', [
			'process_id'      => (int) $process_id,
			'plan_version_id' => (int) $plan['id'],
			'fact_version_id' => (int) $fact['id'],
			'status'          => 'queued',
			'created_by'      => (int) $user_id,
		]);

		$queued = self::_push(self::CH_DIFF, [
			'audit_id'        => $id,
			'process_id'      => (int) $process_id,
			'plan_version_id' => (int) $plan['id'],
			'fact_version_id' => (int) $fact['id'],
		], (int) $user_id, 'diff');

		if ( ! Arr::get($queued, 'ok'))
		{
			self::_update('nj_670_audits', [
				'status' => 'error',
				'error'  => (string) Arr::get($queued, 'error', 'Очередь недоступна.'),
			], $id);

			return $queued;
		}

		self::_exec('UPDATE nj_670_audits SET job_id = '.(int) $queued['job_id'].'
			WHERE id = '.$id);

		self::_event((int) $process_id, $user_id, 'audit.queued', 'AI-аудит поставлен в очередь',
			['job_id' => (int) $queued['job_id']]);

		return ['ok' => TRUE, 'job_id' => (int) $queued['job_id'], 'audit_id' => $id];
	}

	public static function queue_report($process_id, $user_id, $version_id, $audit_id = 0)
	{
		$process = self::process_by_id($process_id);
		$version = self::version($version_id);

		if ( ! $process OR ! $version OR (int) $version['process_id'] !== (int) $process_id)
		{
			return ['ok' => FALSE, 'error' => 'Версия процесса не найдена.'];
		}

		$quota = self::quota($user_id, 'report');

		if ($quota['left'] <= 0)
		{
			return ['ok' => FALSE, 'error' => 'Дневной лимит выпуска регламентов исчерпан ('.$quota['max'].').'];
		}

		$id = self::_insert('nj_670_reports', [
			'process_id' => (int) $process_id,
			'version_id' => (int) $version_id,
			'audit_id'   => $audit_id > 0 ? (int) $audit_id : NULL,
			'status'     => 'queued',
			'created_by' => (int) $user_id,
		]);

		$queued = self::_push(self::CH_EXPORT, [
			'report_id'  => $id,
			'process_id' => (int) $process_id,
			'version_id' => (int) $version_id,
			'audit_id'   => (int) $audit_id,
		], (int) $user_id, 'export');

		if ( ! Arr::get($queued, 'ok'))
		{
			self::_update('nj_670_reports', [
				'status' => 'error',
				'error'  => (string) Arr::get($queued, 'error', 'Очередь недоступна.'),
			], $id);

			return $queued;
		}

		self::_exec('UPDATE nj_670_reports SET job_id = '.(int) $queued['job_id'].'
			WHERE id = '.$id);

		self::_event((int) $process_id, $user_id, 'report.queued', 'Выпуск регламента поставлен в очередь',
			['job_id' => (int) $queued['job_id']]);

		return ['ok' => TRUE, 'job_id' => (int) $queued['job_id'], 'report_id' => $id];
	}

	/**
	 * Есть ли живой воркер: свежий heartbeat у задачи, которую он взял.
	 *
	 * Зачем: очередь nj120 принимает задачу и без слушателя. Без этой проверки
	 * кнопки «Собрать схему» и «Выпустить регламент» в песочнице без воркера
	 * оставляли бы вечную «В работе». Срок 20 минут сознательно короткий:
	 * лучше собрать регламент локально, чем показывать очередь, которую никто
	 * не разбирает. Ошибка БД не должна ломать страницу — считаем «нет».
	 */
	/**
	 * Жив ли воркер.
	 *
	 * Раньше признаком было только «есть задача, взятая воркером, и её пульс
	 * свежий». У простаивающего воркера задач нет — сайт считал его мёртвым и
	 * on каждое создание процесса отвечал «воркер не в сети», хотя воркер
	 * работал. Теперь основной признак — собственный пульс воркера на
	 * /process/w/ping; пульс задач остаётся запасным (старые воркеры).
	 */
	public static function worker_online($minutes = NULL)
	{
		$status = self::worker_status($minutes);

		return (bool) $status['online'];
	}

	/**
	 * Подробный статус воркера для интерфейса и диагностики.
	 *
	 * @param int|null $minutes окно пульса задач (по умолчанию из конфига)
	 * @return array online, source, last_ping_at, ping_age_minutes, worker,
	 *               version, last_job_at, job_age_minutes, queued, hint
	 */
	public static function worker_status($minutes = NULL)
	{
		$presence_minutes = (int) Arr::get(self::branch('worker'), 'presence_minutes', 5);
		$presence_minutes = max(1, min(1440, (int) (getenv('NJ670_WORKER_PRESENCE_MINUTES') ?: $presence_minutes)));
		$job_minutes = $minutes !== NULL
			? max(1, min(1440, (int) $minutes))
			: max(1, min(1440, (int) Arr::get(self::branch('worker'), 'job_minutes', 60)));

		$status = [
			'online'           => FALSE,
			'source'           => 'none',
			'presence_minutes' => $presence_minutes,
			'job_minutes'      => $job_minutes,
			'last_ping_at'     => NULL,
			'ping_age_minutes' => NULL,
			'worker'           => '',
			'version'          => '',
			'channels'         => [],
			'last_job_at'      => NULL,
			'job_age_minutes'  => NULL,
			'queued'           => 0,
			'running'          => 0,
			'hint'             => '',
		];

		// 1. Собственный пульс воркера (новые воркеры) и пульс задач.
		$ping = NULL;
		$job = NULL;

		try
		{
			$ping = self::_one("SELECT payload, created_at FROM nj_670_events
				WHERE kind = 'worker_ping' ORDER BY created_at DESC LIMIT 1");
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'пульс воркера не прочитан: :error', [':error' => $e->getMessage()]);
			$status['hint'] = 'Таблица событий недоступна: '.(string) $e->getMessage();
		}

		// Задачный пульс нужен, только если собственный пульс не свежий:
		// старый ping (воркер упал сразу после старта) не должен навсегда
		// отключать запасной признак — иначе живой воркер с активной задачей
		// снова выглядел бы выключенным.
		$ping_age = $ping ? self::_age_minutes((string) $ping['created_at'], time()) : NULL;
		// Скобки обязательны: без них `AND` присваивал в $ping_fresh только
		// первый операнд, и устаревший пульс считался свежим всегда.
		$ping_fresh = ($ping_age !== NULL AND $ping_age <= $presence_minutes);

		if ( ! $ping_fresh)
		{
			try
			{
				$job = self::_one("SELECT max(coalesce(heartbeat_at, finished_at, started_at)) AS beat, count(*) AS n
					FROM nj_120_jobs
					WHERE claimed_by IS NOT NULL AND claimed_by <> ''
					  AND coalesce(heartbeat_at, finished_at, started_at) > now() - interval '".$job_minutes." minutes'");

				if ( ! $job OR (int) Arr::get($job, 'n', 0) <= 0)
				{
					$job = NULL;
				}
			}
			catch (Throwable $e)
			{
				self::_log(Log::WARNING, 'проверка воркера не удалась: :error', [':error' => $e->getMessage()]);
			}
		}

		$state = self::presence_state(
			$ping ? (string) $ping['created_at'] : NULL,
			$presence_minutes,
			$job ? (string) Arr::get($job, 'beat', '') : NULL,
			$job_minutes
		);

		$status['online'] = (bool) $state['online'];
		$status['source'] = (string) $state['source'];
		$status['last_ping_at'] = $state['last_ping_at'];
		$status['ping_age_minutes'] = $state['ping_age_minutes'];
		$status['last_job_at'] = $state['last_job_at'];
		$status['job_age_minutes'] = $state['job_age_minutes'];

		if ($ping)
		{
			$payload = self::json_decode(Arr::get($ping, 'payload', []));

			$status['worker'] = (string) Arr::get($payload, 'worker', '');
			$status['version'] = (string) Arr::get($payload, 'version', '');
			$status['channels'] = (array) Arr::get($payload, 'channels', []);
		}

		// 3. Сколько задач модуля ждёт воркера прямо сейчас.
		try
		{
			$status['queued'] = (int) self::_value("SELECT count(*) FROM nj_120_jobs
				WHERE status = 'pending' AND channel LIKE 'nj670.%'");
			$status['running'] = (int) self::_value("SELECT count(*) FROM nj_120_jobs
				WHERE status = 'processing' AND channel LIKE 'nj670.%'");
		}
		catch (Throwable $e)
		{
			// очередь может быть недоступна — это не повод падать странице
		}

		// 4. Ручное переопределение для нестандартных схем (воркер за NAT и т.п.).
		if (in_array(strtolower(trim((string) getenv('NJ670_WORKER_FORCE_ONLINE'))), ['1', 'true', 'yes', 'on'], TRUE))
		{
			$status['online'] = TRUE;
			$status['source'] = 'forced';
		}

		if ($status['online'])
		{
			$status['hint'] = $status['source'] === 'ping'
				? 'Пульс '.($status['ping_age_minutes'] === 0 ? 'только что' : $status['ping_age_minutes'].' мин назад').'.'
				: ($status['source'] === 'job'
					? 'Воркер занят задачей (пульс '.($status['job_age_minutes'] ?? 0).' мин назад).'
					: 'Онлайн включён принудительно (NJ670_WORKER_FORCE_ONLINE).');
		}
		elseif ($status['last_ping_at'] === NULL)
		{
			$status['hint'] = 'Пульса ещё не было: воркер не запускался с поддержкой /process/w/ping '
				.'или не доходит до сайта. Проверьте NJ670_API_BASE и NJ670_SITE_TOKEN в workers/.env.';
		}
		else
		{
			$status['hint'] = 'Последний пульс '.$status['ping_age_minutes'].' мин назад — больше окна '
				.$presence_minutes.' мин. Проверьте, что процесс воркера жив.';
		}

		return $status;
	}

	/**
	 * Решение «воркер жив» по двум отметкам времени. Без БД — проверяется тестом.
	 *
	 * Пульс воркера главнее: он приходит и без задач. Пульс задачи — запасной
	 * признак для воркеров, которые ещё не умеют /process/w/ping.
	 *
	 * @param string|null $ping_at       время пульса (ISO/БД-строка)
	 * @param int         $ping_minutes  окно пульса
	 * @param string|null $job_at        время пульса задачи
	 * @param int         $job_minutes   окно задач
	 * @param int|null    $now           текущее время (для теста)
	 */
	public static function presence_state($ping_at, $ping_minutes, $job_at = NULL, $job_minutes = 60, $now = NULL)
	{
		$now = $now === NULL ? time() : (int) $now;
		$ping_age = self::_age_minutes($ping_at, $now);
		$job_age = self::_age_minutes($job_at, $now);
		$online = FALSE;
		$source = 'none';

		if ($ping_age !== NULL AND $ping_age <= (int) $ping_minutes)
		{
			$online = TRUE;
			$source = 'ping';
		}
		elseif ($job_age !== NULL AND $job_age <= (int) $job_minutes)
		{
			$online = TRUE;
			$source = 'job';
		}

		return [
			'online'           => $online,
			'source'           => $source,
			// Скобки обязательны: AND связывает слабее ?:, и без них сюда
			// попадал boolean вместо отметки времени. Нечитаемое время — NULL.
			'last_ping_at'     => $ping_age !== NULL ? (string) $ping_at : NULL,
			'ping_age_minutes' => $ping_age,
			'last_job_at'      => $job_age !== NULL ? (string) $job_at : NULL,
			'job_age_minutes'  => $job_age,
		];
	}

	/**
	 * Возраст отметки времени в минутах; NULL — отметки нет.
	 *
	 * Отрицательный возраст (часы сервера разошлись) считаем нулём: пульс из
	 * будущего — это свежий пульс, а не «минус пять минут».
	 */
	protected static function _age_minutes($at, $now)
	{
		$at = trim((string) $at);

		if ($at === '')
		{
			return NULL;
		}

		$ts = strtotime($at);

		if ($ts === FALSE)
		{
			return NULL;
		}

		return max(0, (int) floor(($now - $ts) / 60));
	}

	/**
	 * Приёмник пульса воркера: /process/w/ping.
	 */
	/**
	 * Таблицы модуля, без которых он не работает (для самопроверки).
	 */
	public static function required_tables()
	{
		return [
			'nj_670_workspaces', 'nj_670_entities', 'nj_670_processes', 'nj_670_versions',
			'nj_670_nodes', 'nj_670_edges', 'nj_670_imports', 'nj_670_audits',
			'nj_670_reports', 'nj_670_events',
		];
	}

	/**
	 * Существующие таблицы nj_670_* в текущей схеме.
	 */
	public static function db_tables()
	{
		$rows = self::_all("SELECT table_name FROM information_schema.tables
			WHERE table_schema = current_schema() AND table_name LIKE 'nj\_670\_%'");

		return array_map(function ($row) {
			return (string) Arr::get($row, 'table_name', '');
		}, $rows);
	}

	/**
	 * Последние задачи каналов nj670.* с человеческими полями.
	 */
	public static function queue_snapshot($limit = 10)
	{
		$limit = max(1, min(100, (int) $limit));

		try
		{
			$rows = self::_all("SELECT id, channel, status, attempts, error, claimed_by, created_at
				FROM nj_120_jobs WHERE channel LIKE 'nj670.%'
				ORDER BY created_at DESC LIMIT ".$limit);
		}
		catch (Throwable $e)
		{
			return [];
		}

		return array_map(function ($row) {
			return [
				'id'         => (int) Arr::get($row, 'id', 0),
				'channel'    => (string) Arr::get($row, 'channel', ''),
				'status'     => (string) Arr::get($row, 'status', ''),
				'attempts'   => (int) Arr::get($row, 'attempts', 0),
				'error'      => (string) Arr::get($row, 'error', ''),
				'claimed_by' => (string) Arr::get($row, 'claimed_by', ''),
				'created_at' => (string) Arr::get($row, 'created_at', ''),
			];
		}, $rows);
	}

	public static function worker_ping(array $body)
	{
		$worker = UTF8::substr(trim((string) Arr::get($body, 'worker', '')), 0, 120);
		$version = UTF8::substr(trim((string) Arr::get($body, 'version', '')), 0, 40);
		$channels = [];

		foreach ((array) Arr::get($body, 'channels', []) as $channel)
		{
			$channel = trim((string) $channel);

			if ($channel !== '')
			{
				$channels[] = UTF8::substr($channel, 0, 80);
			}
		}

		self::_event(0, 0, 'worker_ping', 'Пульс воркера '.($worker !== '' ? $worker : 'без имени'), [
			'worker'   => $worker,
			'version'  => $version,
			'channels' => array_values(array_unique($channels)),
		]);

		// Старые пульсы не нужны: одного последнего достаточно. Чистим редко,
		// чтобы не делать DELETE на каждый пинг.
		if (mt_rand(1, 20) === 1)
		{
			try
			{
				self::_exec("DELETE FROM nj_670_events WHERE kind = 'worker_ping' AND created_at < now() - interval '7 days'");
			}
			catch (Throwable $e)
			{
				// уборка не критична
			}
		}

		$status = self::worker_status();

		return [
			'ok'          => TRUE,
			'server_time' => date('c'),
			'online'      => (bool) $status['online'],
			'presence_minutes' => (int) $status['presence_minutes'],
			'queued'      => (int) $status['queued'],
		];
	}

	protected static function _fail_version($version_id, $error)
	{
		self::_exec('UPDATE nj_670_versions SET status = \'error\',
				error = '.self::_q(UTF8::substr((string) $error, 0, 1000)).',
				updated_at = now()
			WHERE id = '.(int) $version_id);
	}

	public static function job_state($job_id)
	{
		$job_id = (int) $job_id;

		if ($job_id <= 0 OR ! class_exists('Service_Nj120_JobQueue'))
		{
			return [];
		}

		try
		{
			return (array) Service_Nj120_JobQueue::instance()->status_of($job_id);
		}
		catch (Throwable $e)
		{
			return [];
		}
	}

	public static function job_running(array $job)
	{
		$status = strtolower((string) Arr::get($job, 'status', ''));

		return in_array($status, ['queued', 'running', 'pending', 'processing'], TRUE);
	}

	/**
	 * Первая незавершённая задача из списка строк с job_id. Нужна страницам,
	 * где воркер работает не над текущей версией: история выпусков регламента,
	 * список версий, паспорт. Возвращает [] если всё уже сделано.
	 */
	public static function first_running_job(array $rows)
	{
		foreach ($rows as $row)
		{
			$job_id = (int) Arr::get((array) $row, 'job_id', 0);

			if ($job_id <= 0)
			{
				continue;
			}

			$job = self::job_state($job_id);

			if (self::job_running($job))
			{
				return $job;
			}
		}

		return [];
	}

	// ------------------------------------------------------------ импорт

	public static function storage_dir()
	{
		$dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670';

		if ( ! is_dir($dir))
		{
			@mkdir($dir, 0775, TRUE);
		}

		return $dir;
	}

	public static function store_upload($process_id, array $file)
	{
		$name = (string) Arr::get($file, 'name', '');
		$tmp  = (string) Arr::get($file, 'tmp_name', '');
		$size = (int) Arr::get($file, 'size', 0);
		$err  = (int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE);

		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$allowed = (array) Arr::get(self::branch('upload'), 'extensions', ['csv', 'xlsx', 'txt', 'md']);
		$max = (int) Arr::get(self::branch('upload'), 'max_bytes', self::limits('max_import_bytes', 8388608));

		if ($err !== UPLOAD_ERR_OK OR $tmp === '' OR ! is_uploaded_file($tmp))
		{
			return ['ok' => FALSE, 'error' => 'Файл не принят. Выберите CSV, XLSX или TXT до '.round($max / 1048576).' МБ.'];
		}

		if ( ! in_array($ext, $allowed, TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Формат .'.$ext.' не поддерживается. Нужен CSV, XLSX, TXT или MD.'];
		}

		if ($size <= 0 OR $size > $max)
		{
			return ['ok' => FALSE, 'error' => 'Размер файла должен быть от 1 байта до '.round($max / 1048576).' МБ.'];
		}

		$kind = $ext === 'xlsx' ? 'xlsx' : (in_array($ext, ['txt', 'md'], TRUE) ? 'text' : 'csv');
		$sub = date('Ym');
		$dir = self::storage_dir().DIRECTORY_SEPARATOR.$sub;

		if ( ! is_dir($dir))
		{
			@mkdir($dir, 0775, TRUE);
		}

		$stored = bin2hex(random_bytes(10)).'.'.$ext;
		$full = $dir.DIRECTORY_SEPARATOR.$stored;

		if ( ! move_uploaded_file($tmp, $full))
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить файл на сервере.'];
		}

		@chmod($full, 0664);

		$rows = 0;

		if ($kind === 'csv')
		{
			$rows = self::_count_csv_rows($full);
		}

		self::_event((int) $process_id, 0, 'import.uploaded', 'Загружен источник '.$name, [
			'size' => $size, 'kind' => $kind,
		]);

		return [
			'ok'          => TRUE,
			'kind'        => $kind,
			'filename'    => UTF8::substr($name, 0, 255),
			'stored_path' => $sub.'/'.$stored,
			'size_bytes'  => $size,
			'rows_count'  => $rows,
			'full_path'   => $full,
		];
	}

	/**
	 * Источник для синтеза: текст, PDF, скан/фото или аудио. Кладём рядом с
	 * импортами, но отдельной папкой: это вход модели, а не журнал событий.
	 */
	public static function store_source($process_id, array $file)
	{
		$name = (string) Arr::get($file, 'name', '');
		$tmp  = (string) Arr::get($file, 'tmp_name', '');
		$size = (int) Arr::get($file, 'size', 0);
		$err  = (int) Arr::get($file, 'error', UPLOAD_ERR_NO_FILE);
		$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$allowed = (array) Arr::get(self::branch('upload'), 'source_extensions',
			['txt', 'md', 'pdf', 'png', 'jpg', 'jpeg', 'webp', 'mp3', 'm4a', 'wav', 'ogg']);
		$max = (int) self::limits('max_source_bytes', 12582912);

		if ($err !== UPLOAD_ERR_OK OR $tmp === '' OR ! is_uploaded_file($tmp))
		{
			return ['ok' => FALSE, 'error' => 'Файл не принят. Подойдёт текст, PDF, фото или аудио до '.round($max / 1048576).' МБ.'];
		}

		if ( ! in_array($ext, $allowed, TRUE))
		{
			return ['ok' => FALSE, 'error' => 'Формат .'.$ext.' не поддерживается.'];
		}

		if ($size <= 0 OR $size > $max)
		{
			return ['ok' => FALSE, 'error' => 'Размер файла — от 1 байта до '.round($max / 1048576).' МБ.'];
		}

		$kind = in_array($ext, ['txt', 'md'], TRUE) ? 'text'
			: (in_array($ext, ['pdf'], TRUE) ? 'pdf'
			: (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], TRUE) ? 'image' : 'audio'));

		$sub = date('Ym');
		$dir = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR.'sources'
			.DIRECTORY_SEPARATOR.$sub;

		if ( ! is_dir($dir))
		{
			@mkdir($dir, 0775, TRUE);
		}

		$stored = bin2hex(random_bytes(10)).'.'.$ext;

		if ( ! move_uploaded_file($tmp, $dir.DIRECTORY_SEPARATOR.$stored))
		{
			return ['ok' => FALSE, 'error' => 'Не удалось сохранить источник.'];
		}

		@chmod($dir.DIRECTORY_SEPARATOR.$stored, 0664);

		$text = '';

		if ($kind === 'text')
		{
			$text = (string) file_get_contents($dir.DIRECTORY_SEPARATOR.$stored);
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8,Windows-1251');
			$text = UTF8::substr(trim($text), 0, (int) self::limits('max_text', 20000));
		}

		return [
			'ok'         => TRUE,
			'kind'       => $kind,
			'filename'   => UTF8::substr($name, 0, 255),
			'stored_path'=> 'sources/'.$sub.'/'.$stored,
			'size_bytes' => $size,
			'text'       => $text,
		];
	}

	public static function source_bytes($stored_path)
	{
		$stored_path = (string) $stored_path;

		if ($stored_path === '' OR strpos($stored_path, '..') !== FALSE)
		{
			return '';
		}

		$full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
			.str_replace('/', DIRECTORY_SEPARATOR, $stored_path);

		return is_file($full) ? (string) file_get_contents($full) : '';
	}

	protected static function _count_csv_rows($path)
	{
		$count = 0;
		$fp = @fopen($path, 'rb');

		if ( ! $fp)
		{
			return 0;
		}

		while (fgets($fp) !== FALSE)
		{
			$count++;
		}

		fclose($fp);

		return max(0, $count - 1);
	}

	/**
	 * Импорт, разобранный на сайте (без очереди): строка истории всё равно
	 * нужна, иначе человек не увидит, откуда взялся факт.
	 */
	public static function record_import($process_id, $user_id, array $data)
	{
		$id = self::_insert('nj_670_imports', [
			'process_id'  => (int) $process_id,
			'user_id'     => (int) $user_id,
			'kind'        => (string) Arr::get($data, 'kind', 'csv'),
			'filename'    => UTF8::substr((string) Arr::get($data, 'filename', ''), 0, 255),
			'stored_path' => (string) Arr::get($data, 'stored_path', ''),
			'size_bytes'  => (int) Arr::get($data, 'size_bytes', 0),
			'rows_count'  => (int) Arr::get($data, 'rows_count', 0),
			'status'      => 'done',
		]);

		return $id;
	}

	public static function import($id)
	{
		return self::_one('SELECT * FROM nj_670_imports WHERE id = '.(int) $id);
	}

	public static function imports($process_id, $limit = 20)
	{
		return self::_all('SELECT * FROM nj_670_imports WHERE process_id = '.(int) $process_id.'
			ORDER BY id DESC LIMIT '.max(1, min(100, (int) $limit)));
	}

	public static function import_bytes(array $import)
	{
		$path = (string) Arr::get($import, 'stored_path', '');

		if ($path === '' OR strpos($path, '..') !== FALSE)
		{
			return '';
		}

		$full = DOCROOT.'storage'.DIRECTORY_SEPARATOR.'nj670'.DIRECTORY_SEPARATOR
			.str_replace('/', DIRECTORY_SEPARATOR, $path);

		return is_file($full) ? (string) file_get_contents($full) : '';
	}

	// ------------------------------------------------------------ аудит и отчёты

	public static function audit($id)
	{
		return self::_one('SELECT * FROM nj_670_audits WHERE id = '.(int) $id);
	}

	public static function audits($process_id, $limit = 10)
	{
		return self::_all('SELECT * FROM nj_670_audits WHERE process_id = '.(int) $process_id.'
			ORDER BY id DESC LIMIT '.max(1, min(50, (int) $limit)));
	}

	public static function latest_audit($process_id, $only_done = FALSE)
	{
		return self::_one('SELECT * FROM nj_670_audits WHERE process_id = '.(int) $process_id
			.($only_done ? ' AND status = \'done\'' : '').'
			ORDER BY id DESC LIMIT 1');
	}

	public static function finding_severities(array $audit)
	{
		$findings = self::json_decode(Arr::get($audit, 'findings', []));
		$counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

		foreach ($findings as $finding)
		{
			$severity = (string) Arr::get($finding, 'severity', 'medium');

			if (isset($counts[$severity]))
			{
				$counts[$severity]++;
			}
		}

		return $counts;
	}

	public static function reports($process_id, $limit = 10)
	{
		return self::_all('SELECT * FROM nj_670_reports WHERE process_id = '.(int) $process_id.'
			ORDER BY id DESC LIMIT '.max(1, min(50, (int) $limit)));
	}

	public static function report($id)
	{
		return self::_one('SELECT * FROM nj_670_reports WHERE id = '.(int) $id);
	}

	// ------------------------------------------------------------ статус для UI

	public static function status_payload(array $process, $user_id = 0)
	{
		$plan = self::current_version($process, 'plan');
		$fact = self::current_version($process, 'fact');
		$audit = self::latest_audit((int) $process['id']);
		$reports = self::reports((int) $process['id'], 5);

		$jobs = [];

		foreach ([$plan, $fact] as $version)
		{
			if ($version AND (int) $version['job_id'] > 0)
			{
				$jobs[(int) $version['job_id']] = self::job_state((int) $version['job_id']);
			}
		}

		if ($audit AND (int) $audit['job_id'] > 0)
		{
			$jobs[(int) $audit['job_id']] = self::job_state((int) $audit['job_id']);
		}

		foreach ($reports as $report)
		{
			if ((int) $report['job_id'] > 0)
			{
				$jobs[(int) $report['job_id']] = self::job_state((int) $report['job_id']);
			}
		}

		return [
			'ok'      => TRUE,
			'process' => [
				'code'   => (string) $process['code'],
				'title'  => (string) $process['title'],
				'plan_version_id' => $plan ? (int) $plan['id'] : 0,
				'fact_version_id' => $fact ? (int) $fact['id'] : 0,
				'updated_at' => (string) $process['updated_at'],
			],
			'plan'    => $plan ? self::_version_brief($plan) : NULL,
			'fact'    => $fact ? self::_version_brief($fact) : NULL,
			'audit'   => $audit ? [
				'id'      => (int) $audit['id'],
				'status'  => (string) $audit['status'],
				'risk_level' => (string) $audit['risk_level'],
				'summary' => (string) $audit['summary'],
				'total_impact_rub' => (float) $audit['total_impact_rub'],
				'error'   => (string) $audit['error'],
				'updated_at' => (string) $audit['updated_at'],
			] : NULL,
			'reports' => array_map(function ($row) {
				return [
					'id'        => (int) $row['id'],
					'status'    => (string) $row['status'],
					'version_id'=> (int) $row['version_id'],
					'has_docx'  => (string) $row['docx_path'] !== '',
					'has_pdf'   => (string) $row['pdf_path'] !== '',
					'error'     => (string) $row['error'],
					'created_at'=> (string) $row['created_at'],
				];
			}, $reports),
			'jobs'    => $jobs,
			// Холст показывает, ждать ли модель или схема уже собрана правилами.
			'worker'  => self::worker_status(),
			'quota'   => [
				'synth'  => self::quota($user_id, 'synth'),
				'audit'  => self::quota($user_id, 'audit'),
				'report' => self::quota($user_id, 'report'),
			],
		];
	}

	protected static function _version_brief(array $version)
	{
		return [
			'id'           => (int) $version['id'],
			'kind'         => (string) $version['kind'],
			'status'       => (string) $version['status'],
			'version_tag'  => (string) $version['version_tag'],
			'job_id'       => (int) $version['job_id'],
			'error'        => (string) $version['error'],
			'duration'     => (int) $version['total_duration_minutes'],
			'cost'         => (float) $version['total_cost_rub'],
			'updated_at'   => (string) $version['updated_at'],
			'job'          => (int) $version['job_id'] > 0 ? self::job_state((int) $version['job_id']) : [],
		];
	}

	// ------------------------------------------------------------ приёмная воркера

	public static function worker(array $body, $action)
	{
		try
		{
			if ($action === 'context')
			{
				return self::worker_context($body);
			}

			if ($action === 'graph')
			{
				return self::worker_graph($body);
			}

			if ($action === 'audit')
			{
				return self::worker_audit($body);
			}

			if ($action === 'report')
			{
				return self::worker_report($body);
			}

			if ($action === 'fail')
			{
				return self::worker_fail($body);
			}

			if ($action === 'ping')
			{
				return self::worker_ping($body);
			}

			if ($action === 'ontology')
			{
				return Service_Nj670_Ontologyfeed::store_result($body);
			}

			if ($action === 'ontology_fail')
			{
				return Service_Nj670_Ontologyfeed::fail($body);
			}
		}
		catch (DomainException $e)
		{
			self::_log(Log::WARNING, 'воркер :action отклонил данные: :error', [
				':action' => $action, ':error' => $e->getMessage(),
			]);

			return ['ok' => FALSE, 'error' => $e->getMessage()];
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'воркер :action: :error', [
				':action' => $action, ':error' => $e->getMessage(),
			]);

			return ['ok' => FALSE, 'error' => 'Внутренняя ошибка приёмной; см. журнал сайта.'];
		}

		return ['ok' => FALSE, 'error' => 'Неизвестное действие.'];
	}

	protected static function worker_context(array $body)
	{
		// Разбор документа в онтологию идёт по тому же адресу: воркер не
		// должен знать два разных способа получать контекст.
		if ((int) Arr::get($body, 'ontology_job_id', 0) > 0)
		{
			return Service_Nj670_Ontologyfeed::context($body);
		}

		$version_id = (int) Arr::get($body, 'version_id', 0);
		$process_id = (int) Arr::get($body, 'process_id', 0);
		$report_id  = (int) Arr::get($body, 'report_id', 0);
		$audit_id   = (int) Arr::get($body, 'audit_id', 0);

		if ($report_id > 0)
		{
			$context = Service_Nj670_Report::worker_context($report_id);

			return $context;
		}

		if ($audit_id > 0)
		{
			$audit = self::audit($audit_id);

			if ( ! $audit)
			{
				throw new DomainException('Аудит не найден.');
			}

			$plan = self::version((int) $audit['plan_version_id']);
			$fact = self::version((int) $audit['fact_version_id']);

			if ( ! $plan OR ! $fact)
			{
				throw new DomainException('Версии аудита не найдены.');
			}

			$workspace_id = (int) $plan['workspace_id'];

			return [
				'ok'          => TRUE,
				'audit'       => [
					'id'          => (int) $audit['id'],
					'process_id'  => (int) $audit['process_id'],
				],
				'process'     => [
					'title' => (string) $plan['process_title'],
					'code'  => (string) $plan['process_code'],
					'owner_role_code' => (string) $plan['owner_role_code'],
				],
				'plan'        => self::graph_of($plan),
				'fact'        => self::graph_of($fact),
				'plan_version'=> ['id' => (int) $plan['id'], 'tag' => (string) $plan['version_tag']],
				'fact_version'=> ['id' => (int) $fact['id'], 'tag' => (string) $fact['version_tag']],
				'ontology'    => Service_Nj670_Ontology::prompt_dictionary($workspace_id),
				'economy'     => self::branch('economy'),
				'limits'      => [
					'max_findings' => 40,
					'out_tokens'   => 6000,
				],
				'model'       => self::model(),
			];
		}

		$version = $version_id > 0 ? self::version($version_id) : NULL;

		if ( ! $version AND $process_id > 0)
		{
			$process = self::process_by_id($process_id);
			$version = $process ? self::current_version($process, 'plan') : NULL;
		}

		if ( ! $version)
		{
			throw new DomainException('Версия не найдена.');
		}

		$workspace_id = (int) $version['workspace_id'];

		$context = [
			'ok'         => TRUE,
			'version'    => [
				'id'          => (int) $version['id'],
				'kind'        => (string) $version['kind'],
				'version_tag' => (string) $version['version_tag'],
				'source_text' => (string) $version['source_text'],
			],
			'process'    => [
				'id'          => (int) $version['process_id'],
				'title'       => (string) $version['process_title'],
				'code'        => (string) $version['process_code'],
				'description' => '',
				'owner_role_code' => (string) $version['owner_role_code'],
			],
			'current_graph' => self::graph_of($version),
			'ontology'   => Service_Nj670_Ontology::prompt_dictionary($workspace_id),
			'limits'     => [
				'max_nodes'  => (int) self::limits('max_nodes', 80),
				'max_edges'  => (int) self::limits('max_edges', 160),
				'max_text'   => (int) self::limits('max_text', 20000),
				'out_tokens' => 6000,
			],
			'model'      => self::model(),
			'allow_rule_fallback' => (bool) Arr::get(self::config(), 'allow_rule_fallback', TRUE),
			'mode'       => (string) Arr::get($body, 'mode', 'full'),
			'instruction'=> (string) Arr::get($body, 'instruction', ''),
			'node_id'    => (string) Arr::get($body, 'node_id', ''),
		];

		if ( ! empty($body['source']['stored_path']))
		{
			$bytes = self::source_bytes((string) $body['source']['stored_path']);

			if ($bytes === '')
			{
				throw new DomainException('Файл-источник не найден на сайте.');
			}

			$context['source'] = [
				'kind'        => (string) Arr::get($body['source'], 'kind', 'text'),
				'filename'    => (string) Arr::get($body['source'], 'filename', ''),
				'content_b64' => base64_encode($bytes),
			];
		}

		if ((int) Arr::get($body, 'import_id', 0) > 0)
		{
			$import = self::import((int) $body['import_id']);

			if ( ! $import OR (int) $import['process_id'] !== (int) $version['process_id'])
			{
				throw new DomainException('Импорт не найден.');
			}

			$bytes = self::import_bytes($import);

			if ($bytes === '')
			{
				throw new DomainException('Файл импорта не найден на сайте.');
			}

			$context['import'] = [
				'id'          => (int) $import['id'],
				'filename'    => (string) $import['filename'],
				'kind'        => (string) $import['kind'],
				'size_bytes'  => (int) $import['size_bytes'],
				'content_b64' => base64_encode($bytes),
			];
			$context['version']['kind'] = 'fact';
		}

		return $context;
	}

	protected static function worker_graph(array $body)
	{
		$version_id = (int) Arr::get($body, 'version_id', 0);
		$version = self::version($version_id);

		if ( ! $version)
		{
			throw new DomainException('Версия не найдена.');
		}

		if ( ! in_array((string) $version['status'], [self::S_QUEUED, self::S_DRAFT, self::S_ERROR], TRUE))
		{
			throw new DomainException('Версия уже обработана.');
		}

		$graph = Arr::get($body, 'graph', []);

		if ( ! is_array($graph))
		{
			throw new DomainException('В графе нет данных.');
		}

		$mode_in = (string) Arr::get($body, 'mode', 'full');

		// Copilot правит выбранный узел, а не перерисовывает холст: накладываем
		// ответ на текущую версию и сохраняем координаты ручной раскладки.
		if ($mode_in === 'copilot')
		{
			$ontology = Service_Nj670_Ontology::index((int) $version['workspace_id']);
			$graph = Service_Nj670_Synthesis::merge_copilot(
				self::graph_of($version),
				$graph,
				(string) Arr::get($body, 'node_id', ''),
				$ontology
			);
		}

		$saved = self::save_graph($version_id, $graph, 0, ['reflow' => $mode_in !== 'copilot']);

		if ( ! Arr::get($saved, 'ok'))
		{
			throw new DomainException((string) Arr::get($saved, 'error', 'Граф не сохранён.'));
		}

		$model = UTF8::substr(trim((string) Arr::get($body, 'model', '')), 0, 160);
		$note  = UTF8::substr(trim((string) Arr::get($body, 'note', '')), 0, 500);
		$mode  = (string) Arr::get($body, 'mode', 'full');

		$status = (string) $version['kind'] === 'fact' ? self::S_ACTIVE : self::S_IN_REVIEW;

		self::_exec('UPDATE nj_670_versions SET status = '.self::_q($status).',
				model = '.self::_q($model).',
				error = \'\',
				metrics = jsonb_set(COALESCE(metrics, \'{}\'::jsonb), \'{note}\', '.self::_q(self::json_encode($note)).'::jsonb),
				updated_at = now()
			WHERE id = '.$version_id);

		if ($status === self::S_ACTIVE)
		{
			self::activate_version((int) $version['process_id'], $version_id, 0);
		}
		else
		{
			// План пришёл из очереди «на рассмотрении»: холст уже показывает
			// его, а статус утверждения человек ставит кнопкой approve.
			self::point_to((int) $version['process_id'], $version_id, (string) $version['kind']);
		}

		self::touch_process((int) $version['process_id']);

		if ((string) $version['kind'] === 'fact')
		{
			self::_exec('UPDATE nj_670_imports SET status = \'done\', error = \'\',
					rows_count = COALESCE(NULLIF(rows_count, 0), '.(int) Arr::get($body, 'rows', 0).'),
					updated_at = now()
				WHERE process_id = '.(int) $version['process_id'].'
				  AND status IN (\'queued\', \'uploaded\')');
		}

		self::_event((int) $version['process_id'], 0, 'graph.ready',
			'Схема '.$version['version_tag'].' готова: '.count(Arr::path($saved, 'graph.nodes', [])).' шагов',
			['model' => $model, 'mode' => $mode]);

		return [
			'ok'         => TRUE,
			'version_id' => $version_id,
			'status'     => $status,
			'metrics'    => Arr::get($saved, 'metrics', []),
			'url'        => '/process/'.$version['process_code'],
		];
	}

	protected static function worker_audit(array $body)
	{
		$audit_id = (int) Arr::get($body, 'audit_id', 0);
		$audit = self::audit($audit_id);

		if ( ! $audit)
		{
			throw new DomainException('Аудит не найден.');
		}

		if ((string) $audit['status'] !== 'queued')
		{
			throw new DomainException('Аудит уже закрыт.');
		}

		$findings = self::_clean_findings(Arr::get($body, 'findings', []));
		$summary = UTF8::substr(trim((string) Arr::get($body, 'summary', '')), 0, 6000);
		$impact = max(0, (float) Arr::get($body, 'total_impact_rub', 0));
		$risk = (string) Arr::get($body, 'risk_level', '');

		if ( ! in_array($risk, ['critical', 'high', 'medium', 'low'], TRUE))
		{
			$risk = self::_risk_from_findings($findings);
		}

		$model = UTF8::substr(trim((string) Arr::get($body, 'model', '')), 0, 160);
		$mode = (string) Arr::get($body, 'mode', 'rules');
		$mode = in_array($mode, ['llm', 'rules', 'hybrid'], TRUE) ? $mode : 'rules';

		self::_exec('UPDATE nj_670_audits SET
				status = \'done\',
				summary = '.self::_q($summary).',
				findings = '.self::_q(self::json_encode($findings)).'::jsonb,
				total_impact_rub = '.number_format($impact, 2, '.', '').',
				risk_level = '.self::_q($risk).',
				model = '.self::_q($model).',
				mode = '.self::_q($mode).',
				error = \'\',
				updated_at = now()
			WHERE id = '.$audit_id);

		if ($impact <= 0)
		{
			$sum = 0.0;

			foreach ($findings as $finding)
			{
				$sum += (float) Arr::get($finding, 'impact_rub', 0);
			}

			self::_exec('UPDATE nj_670_audits SET total_impact_rub = '.number_format($sum, 2, '.', '').'
				WHERE id = '.$audit_id);
			$impact = $sum;
		}

		$process = self::process_by_id((int) $audit['process_id']);

		if ($process)
		{
			self::_event((int) $process['id'], 0, 'audit.done', 'AI-аудит завершён: '.count($findings).' находок',
				['impact' => $impact, 'risk' => $risk]);

			$user_id = (int) $process['workspace_user_id'];

			self::_notify('nj670.audit.done', [
				'user_id' => $user_id,
				'title'   => (string) $process['title'],
				'summary' => UTF8::substr($summary, 0, 300),
				'href'    => '/process/'.$process['code'].'/audit',
			], ['self' => TRUE]);
		}

		return ['ok' => TRUE, 'audit_id' => $audit_id, 'findings' => count($findings), 'impact' => $impact];
	}

	protected static function _clean_findings($raw)
	{
		if ( ! is_array($raw))
		{
			return [];
		}

		$out = [];
		$severities = array_keys(self::severities());
		$categories = [
			'shadow_it', 'missing_control', 'bypass', 'loop', 'bottleneck',
			'sla', 'unauthorized_system', 'extra_step', 'cost', 'other',
		];

		foreach (array_slice($raw, 0, 40) as $row)
		{
			if ( ! is_array($row))
			{
				continue;
			}

			$severity = (string) Arr::get($row, 'severity', 'medium');
			$severity = in_array($severity, $severities, TRUE) ? $severity : 'medium';
			$category = (string) Arr::get($row, 'category', 'other');
			$category = in_array($category, $categories, TRUE) ? $category : 'other';

			$title = trim((string) Arr::get($row, 'title', ''));

			if ($title === '')
			{
				continue;
			}

			$out[] = [
				'id'             => UTF8::substr(trim((string) Arr::get($row, 'id', 'f'.(count($out) + 1))), 0, 40),
				'severity'       => $severity,
				'category'       => $category,
				'title'          => UTF8::substr($title, 0, 255),
				'description'    => UTF8::substr(trim((string) Arr::get($row, 'description', '')), 0, 3000),
				'evidence'       => UTF8::substr(trim((string) Arr::get($row, 'evidence', '')), 0, 1000),
				'recommendation' => UTF8::substr(trim((string) Arr::get($row, 'recommendation', '')), 0, 1500),
				'impact_rub'     => max(0, round((float) Arr::get($row, 'impact_rub', 0), 2)),
				'node_id'        => UTF8::substr(trim((string) Arr::get($row, 'node_id', '')), 0, 60),
				'metric'         => UTF8::substr(trim((string) Arr::get($row, 'metric', '')), 0, 200),
			];
		}

		return $out;
	}

	protected static function _risk_from_findings(array $findings)
	{
		$rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
		$max = 0;

		foreach ($findings as $finding)
		{
			$max = max($max, (int) Arr::get($rank, (string) Arr::get($finding, 'severity', 'low'), 1));
		}

		foreach ($rank as $name => $value)
		{
			if ($value === $max)
			{
				return $name;
			}
		}

		return 'low';
	}

	protected static function worker_report(array $body)
	{
		$report_id = (int) Arr::get($body, 'report_id', 0);
		$report = self::report($report_id);

		if ( ! $report)
		{
			throw new DomainException('Отчёт не найден.');
		}

		if ((string) $report['status'] !== 'queued')
		{
			throw new DomainException('Отчёт уже обработан.');
		}

		$docx = base64_decode((string) Arr::get($body, 'docx_b64', ''), TRUE);
		$pdf  = base64_decode((string) Arr::get($body, 'pdf_b64', ''), TRUE);

		if ($docx === FALSE OR $docx === '')
		{
			throw new DomainException('Воркер не передал DOCX.');
		}

		$stored = Service_Nj670_Report::store_files($report_id, $docx, $pdf === FALSE ? '' : $pdf);

		if ( ! Arr::get($stored, 'ok'))
		{
			throw new DomainException((string) Arr::get($stored, 'error', 'Файлы не сохранены.'));
		}

		self::_exec('UPDATE nj_670_reports SET status = \'done\',
				model = '.self::_q(UTF8::substr((string) Arr::get($body, 'model', ''), 0, 160)).',
				error = \'\',
				updated_at = now()
			WHERE id = '.$report_id);

		$process = self::process_by_id((int) $report['process_id']);

		if ($process)
		{
			self::_event((int) $process['id'], 0, 'report.done', 'Сформирован регламент DOCX/PDF');

			self::_notify('nj670.report.done', [
				'user_id' => (int) $process['workspace_user_id'],
				'title'   => (string) $process['title'],
				'href'    => '/process/'.$process['code'].'/report',
			], ['self' => TRUE]);
		}

		return ['ok' => TRUE, 'report_id' => $report_id];
	}

	protected static function worker_fail(array $body)
	{
		$error = UTF8::substr(trim((string) Arr::get($body, 'error', '')), 0, 1500);

		if ($error === '')
		{
			$error = 'Воркер не сообщил причину.';
		}

		$version_id = (int) Arr::get($body, 'version_id', 0);
		$audit_id   = (int) Arr::get($body, 'audit_id', 0);
		$report_id  = (int) Arr::get($body, 'report_id', 0);

		if ($version_id > 0)
		{
			self::_fail_version($version_id, $error);
			self::_exec('UPDATE nj_670_imports SET status = \'error\',
					error = '.self::_q($error).', updated_at = now()
				WHERE process_id = (SELECT process_id FROM nj_670_versions WHERE id = '.$version_id.')
				  AND status IN (\'queued\', \'uploaded\')');
		}

		if ($audit_id > 0)
		{
			self::_exec('UPDATE nj_670_audits SET status = \'error\', error = '.self::_q($error).',
				updated_at = now() WHERE id = '.$audit_id);
		}

		if ($report_id > 0)
		{
			self::_exec('UPDATE nj_670_reports SET status = \'error\', error = '.self::_q($error).',
				updated_at = now() WHERE id = '.$report_id);
		}

		self::_log(Log::ERROR, 'задача воркера не выполнена: version=:version audit=:audit report=:report: :error', [
			':version' => $version_id,
			':audit'   => $audit_id,
			':report'  => $report_id,
			':error'   => $error,
		]);

		return ['ok' => TRUE];
	}

	// ------------------------------------------------------------- паспорт

	/**
	 * Архитектурный срез процесса: какие роли, системы и артефакты реально
	 * встречаются в план- и факт-схеме. Нужен паспорту процесса, чтобы
	 * «где мы зависим от 1С» отвечалось без чтения всей схемы.
	 */
	public static function architecture($plan_graph, $fact_graph, array $index)
	{
		$buckets = [
			'role'     => [],
			'system'   => [],
			'artifact' => [],
		];

		$sources = [
			'plan' => is_array($plan_graph) ? $plan_graph : [],
			'fact' => is_array($fact_graph) ? $fact_graph : [],
		];

		foreach ($sources as $kind => $graph)
		{
			foreach ((array) Arr::get($graph, 'nodes', []) as $node)
			{
				$type = (string) Arr::get($node, 'type', '');

				if (in_array($type, ['start', 'end', 'gateway_xor', 'gateway_and'], TRUE))
				{
					continue;
				}

				$keys = [
					'role'     => (string) Arr::get($node, 'role_code', ''),
					'system'   => (string) Arr::get($node, 'system_code', ''),
					'artifact' => (string) Arr::get($node, 'input_code', ''),
				];

				if ($keys['artifact'] !== '')
				{
					$buckets['artifact'][$keys['artifact']]['in_'.$kind] = (int) Arr::get(
						(array) Arr::get($buckets['artifact'], $keys['artifact'], []), 'in_'.$kind, 0) + 1;
				}

				$out = (string) Arr::get($node, 'output_code', '');

				if ($out !== '')
				{
					$buckets['artifact'][$out]['out_'.$kind] = (int) Arr::get(
						(array) Arr::get($buckets['artifact'], $out, []), 'out_'.$kind, 0) + 1;
				}

				foreach (['role', 'system'] as $bucket)
				{
					$code = $keys[$bucket];

					if ($code === '')
					{
						continue;
					}

					$buckets[$bucket][$code][$kind] = (int) Arr::get(
						(array) Arr::get($buckets[$bucket], $code, []), $kind, 0) + 1;
				}
			}
		}

		$types = ['role', 'system', 'artifact'];
		$out = [];

		foreach ($types as $type)
		{
			$rows = [];

			foreach ($buckets[$type] as $code => $counts)
			{
				// Arr::get не умеет точки: спускаемся по вложенным массивам.
				$by_code = (array) Arr::get($index, 'by_code', []);
				$by_type = (array) Arr::get($by_code, $type, []);
				$entity = (array) Arr::get($by_type, $code, []);

				$rows[] = [
					'code' => (string) $code,
					'name' => (string) Arr::get($entity, 'name', $code),
					'plan' => (int) Arr::get($counts, 'plan', 0),
					'fact' => (int) Arr::get($counts, 'fact', 0),
					'in_plan' => (int) Arr::get($counts, 'in_plan', 0),
					'in_fact' => (int) Arr::get($counts, 'in_fact', 0),
					'out_plan' => (int) Arr::get($counts, 'out_plan', 0),
					'out_fact' => (int) Arr::get($counts, 'out_fact', 0),
				];
			}

			usort($rows, function ($a, $b) {
				$left = ($a['plan'] + $a['fact'] + $a['in_plan'] + $a['in_fact'] + $a['out_plan'] + $a['out_fact']);
				$right = ($b['plan'] + $b['fact'] + $b['in_plan'] + $b['in_fact'] + $b['out_plan'] + $b['out_fact']);

				if ($left === $right)
				{
					return strcmp($a['name'], $b['name']);
				}

				return $right - $left;
			});

			$out[$type] = $rows;
		}

		return $out;
	}

	/**
	 * Покрытие схемы: доля шагов с ролью/системой и число «теневых» мест.
	 * Считаем по одной схеме — паспорт показывает план и факт отдельно.
	 */
	public static function coverage($graph)
	{
		$total = 0;
		$with_role = 0;
		$with_system = 0;
		$shadow = 0;
		$overlays = 0;
		$comments = 0;

		foreach ((array) Arr::get($graph, 'nodes', []) as $node)
		{
			$type = (string) Arr::get($node, 'type', '');

			if (in_array($type, ['start', 'end', 'gateway_xor', 'gateway_and'], TRUE))
			{
				continue;
			}

			$total++;

			$properties = (array) Arr::get($node, 'properties', []);

			if (trim((string) Arr::get($node, 'role_code', '')) !== ''
				OR trim((string) Arr::get($properties, 'role_label', '')) !== '')
			{
				$with_role++;
			}

			if (trim((string) Arr::get($node, 'system_code', '')) !== '')
			{
				$with_system++;
			}

			if ( ! empty($properties['shadow']))
			{
				$shadow++;
			}

			if (trim((string) Arr::get($properties, 'overlay', '')) !== '')
			{
				$overlays++;
			}

			if (trim((string) Arr::get($properties, 'comment', '')) !== '')
			{
				$comments++;
			}
		}

		return [
			'total'       => $total,
			'with_role'   => $with_role,
			'with_system' => $with_system,
			'shadow'      => $shadow,
			'overlays'    => $overlays,
			'comments'    => $comments,
			'role_share'  => $total > 0 ? round($with_role / $total, 3) : 0.0,
			'system_share'=> $total > 0 ? round($with_system / $total, 3) : 0.0,
		];
	}

	// ------------------------------------------------------------ обзор админа

	public static function admin_overview($limit = 100)
	{
		$workspaces = self::_all('SELECT w.*,
				(SELECT count(*) FROM nj_670_processes p WHERE p.workspace_id = w.id) AS processes_count,
				(SELECT count(*) FROM nj_670_versions v JOIN nj_670_processes p ON p.id = v.process_id
					WHERE p.workspace_id = w.id) AS versions_count,
				(SELECT count(*) FROM nj_670_audits a JOIN nj_670_processes p ON p.id = a.process_id
					WHERE p.workspace_id = w.id AND a.status = \'done\') AS audits_count
			FROM nj_670_workspaces w
			ORDER BY w.updated_at DESC LIMIT '.max(1, min(500, (int) $limit)));

		$processes = self::_all('SELECT p.*, w.user_id AS workspace_user_id, w.title AS workspace_title,
				e.name AS owner_role_name,
				(SELECT count(*) FROM nj_670_versions v WHERE v.process_id = p.id AND v.kind = \'plan\') AS plan_versions,
				(SELECT count(*) FROM nj_670_versions v WHERE v.process_id = p.id AND v.kind = \'fact\') AS fact_versions,
				(SELECT count(*) FROM nj_670_audits a WHERE a.process_id = p.id AND a.status = \'done\') AS audits_count,
				(SELECT count(*) FROM nj_670_reports r WHERE r.process_id = p.id AND r.status = \'done\') AS reports_count
			FROM nj_670_processes p
			JOIN nj_670_workspaces w ON w.id = p.workspace_id
			LEFT JOIN nj_670_entities e ON e.id = p.owner_role_id
			ORDER BY p.updated_at DESC LIMIT 60');

		// Не читаем чужую таблицу очереди: считаем собственные состояния.
		$jobs = self::_all('SELECT \'nj670.synthesize\' AS channel, status, count(*) AS n
				FROM nj_670_versions
				WHERE source_kind IN (\'text\', \'file\', \'copilot\')
				  AND created_at > now() - interval \'7 days\'
				GROUP BY status
			UNION ALL
			SELECT \'nj670.mine\' AS channel, status, count(*) AS n
				FROM nj_670_imports
				WHERE created_at > now() - interval \'7 days\'
				GROUP BY status
			UNION ALL
			SELECT \'nj670.diff\' AS channel, status, count(*) AS n
				FROM nj_670_audits
				WHERE created_at > now() - interval \'7 days\'
				GROUP BY status
			UNION ALL
			SELECT \'nj670.export\' AS channel, status, count(*) AS n
				FROM nj_670_reports
				WHERE created_at > now() - interval \'7 days\'
				GROUP BY status');

		$errors = self::_all('SELECT id, process_id, kind, message, created_at
			FROM nj_670_events
			WHERE created_at > now() - interval \'14 days\'
			  AND (kind LIKE \'%error%\' OR kind IN (\'worker.failed\'))
			ORDER BY id DESC LIMIT 20');

		return [
			'workspaces' => $workspaces,
			'processes'  => $processes,
			'jobs'       => $jobs,
			'errors'     => $errors,
		];
	}
}
