<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API ProcessHub: холст, статусы, быстрый аудит, выпуск отчёта
 * и заполнение модуля агентом (create/graph/dictionary/ontology_save).
 *
 * Мутации — POST. Браузерная сессия подтверждает их CSRF-токеном,
 * персональный ключ (Authorization: Bearer n2jmcp_…) — нет: он не уезжает
 * в cookie. Права проверяются на процесс, а не на «код в запросе».
 */
class Controller_Nj670_Api extends Controller_Erp100_Base
{
	public $auto_render = FALSE;

	protected $_uid = 0;
	protected $_start = FALSE;
	protected $_admin = FALSE;
	protected $_body = [];
	/** Запрос пришёл с персональным Bearer-ключом, а не из сессии. */
	protected $_token = FALSE;

	public function before()
	{
		parent::before();

		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
		$this->response->headers('Cache-Control', 'private, no-store');

		$this->_uid = $this->_current_user ? (int) $this->_current_user->id : 0;
		$this->_start = ($this->_uid > 0 AND (bool) $this->can('nj670.start'));
		$this->_admin = (bool) $this->can('nj670.admin');

		// Агент ходит без сессии и cookie: тот же персональный ключ, что у
		// MCP-хаба (/mcp/api/rpc). Владелец ключа получает свои права —
		// запись по-прежнему требует nj670.start, CSRF для ключа не нужен.
		$token_uid = $this->_token_uid();

		if ($token_uid > 0)
		{
			$this->_token = TRUE;
			$this->_uid = $token_uid;
			$this->_start = Service_Nj670_Access::can_start($token_uid);
			// Скобки: `OR` слабее `=`, без них в $_admin попадало только первое
			// условие, и админ по токену N2J не считался админом.
			$this->_admin = (Service_Nj670_Access::can($token_uid, 'nj670.admin')
				OR (class_exists('Service_Nj160_Tokens') AND Service_Nj160_Tokens::is_admin($token_uid)));
		}

		$raw = $this->request->body();
		$decoded = $raw ? json_decode($raw, TRUE) : NULL;
		$this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();

		if ( ! $this->_body AND $this->request->query())
		{
			$this->_body = (array) $this->request->query();
		}
	}

	/**
	 * Действия объявлены методами, а не разбираются из <action> в маршруте:
	 * параметр с именем «action» в Kohana подменяет вызываемый метод, и
	 * /process/api/save отвечал 404 вместо JSON. Метод index — вежливый ответ
	 * на голый /process/api.
	 */
	public function action_index()
	{
		return $this->_respond(['ok' => FALSE, 'error' => 'Укажите действие: status, save, synth, audit, report, delete, ontology, create, graph, dictionary или ontology_save.'], 404);
	}

	public function action_status()
	{
		return $this->_dispatch('status');
	}

	public function action_save()
	{
		return $this->_dispatch('save');
	}

	public function action_synth()
	{
		return $this->_dispatch('synth');
	}

	public function action_audit()
	{
		return $this->_dispatch('audit');
	}

	public function action_report()
	{
		return $this->_dispatch('report');
	}

	public function action_delete()
	{
		return $this->_dispatch('delete');
	}

	public function action_ontology()
	{
		return $this->_dispatch('ontology');
	}

	public function action_create()
	{
		return $this->_dispatch('create');
	}

	public function action_graph()
	{
		return $this->_dispatch('graph');
	}

	public function action_dictionary()
	{
		return $this->_dispatch('dictionary');
	}

	public function action_ontology_save()
	{
		return $this->_dispatch('ontology_save');
	}

	protected function _dispatch($action)
	{
		try
		{
			if ($action === 'status')
			{
				return $this->_status();
			}

			if ($action === 'save')
			{
				return $this->_save();
			}

			if ($action === 'synth')
			{
				return $this->_synth();
			}

			if ($action === 'audit')
			{
				return $this->_audit();
			}

			if ($action === 'report')
			{
				return $this->_report();
			}

			if ($action === 'delete')
			{
				return $this->_delete();
			}

			if ($action === 'ontology')
			{
				return $this->_ontology();
			}

			if ($action === 'create')
			{
				return $this->_create();
			}

			if ($action === 'graph')
			{
				return $this->_graph();
			}

			if ($action === 'dictionary')
			{
				return $this->_dictionary();
			}

			if ($action === 'ontology_save')
			{
				return $this->_ontology_save();
			}

			return $this->_respond(['ok' => FALSE, 'error' => 'Неизвестное действие.'], 404);
		}
		catch (Throwable $e)
		{
			Log_Plain::event(Log::ERROR, 'NJ670 API :action: :error', [
				':action' => $action,
				':error'  => $e->getMessage(),
			]);

			return $this->_respond([
				'ok'    => FALSE,
				'error' => $e instanceof DomainException ? $e->getMessage() : 'Ошибка сервера; подробности в журнале сайта.',
			], $e instanceof DomainException ? 409 : 500);
		}
	}

	// ---------------------------------------------------------------- действия

	protected function _status()
	{
		if ( ! $this->_uid)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Войдите на сайт.'], 401);
		}

		$process = $this->_process();

		if ( ! $process)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Процесс не найден.'], 404);
		}

		// Лёгкий режим для индикатора работы: только задачи и состояние
		// воркера. Полный статус тянет граф, метрики и словарь онтологии —
		// на страницах, где схема не меняется, это лишние запросы каждые 5 с.
		if ( ! empty($this->_body['jobs_only']))
		{
			$live = Service_Nj670_Process::status_payload($process, $this->_uid);

			return $this->_respond([
				'ok'     => TRUE,
				'jobs'   => $live['jobs'],
				'worker' => $live['worker'],
				'audit'  => $live['audit'],
			]);
		}

		$payload = Service_Nj670_Process::status_payload($process, $this->_uid);
		$kind = (string) Arr::get($this->_body, 'kind', 'plan') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);

		$payload['kind'] = $kind;
		$payload['version_id'] = $version ? (int) $version['id'] : 0;
		$payload['version_status'] = $version ? (string) $version['status'] : '';
		$payload['graph'] = $version ? Service_Nj670_Process::graph_of($version) : NULL;
		$payload['metrics'] = $payload['graph']
			? Service_Nj670_Graph::metrics($payload['graph'], Service_Nj670_Ontology::index((int) $process['workspace_id']))
			: NULL;
		$payload['changed'] = (string) Arr::get($this->_body, 'updated_at', '') !== (string) $process['updated_at'];

		return $this->_respond($payload);
	}

	protected function _save()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$process = $this->_process();

		if ( ! $process)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Процесс не найден.'], 404);
		}

		$version = $this->_version_of($process);

		if ( ! $version)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Версия не найдена.'], 404);
		}

		if (in_array((string) $version['status'], [Service_Nj670_Process::S_QUEUED, Service_Nj670_Process::S_ARCHIVED], TRUE))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Версия в очереди или архиве — правка недоступна.'], 409);
		}

		$graph = Arr::get($this->_body, 'graph', []);

		if ( ! is_array($graph))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Граф не передан.'], 422);
		}

		$saved = Service_Nj670_Process::save_graph((int) $version['id'], $graph, $this->_uid, ['reflow' => FALSE]);

		if ( ! Arr::get($saved, 'ok'))
		{
			return $this->_respond($saved, 422);
		}

		return $this->_respond([
			'ok'      => TRUE,
			'graph'   => $saved['graph'],
			'metrics' => $saved['metrics'],
			'version' => [
				'id'     => (int) $saved['version']['id'],
				'status' => (string) $saved['version']['status'],
				'updated_at' => (string) $saved['version']['updated_at'],
			],
		]);
	}

	protected function _synth()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$process = $this->_process();

		if ( ! $process)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Процесс не найден.'], 404);
		}

		$kind = (string) Arr::get($this->_body, 'kind', 'plan') === 'fact' ? 'fact' : 'plan';

		if ($kind === 'fact')
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Факт строится из журнала, а не из описания.'], 422);
		}

		if ( ! Service_Nj670_Process::worker_online())
		{
			$worker = Service_Nj670_Process::worker_status();

			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'AI-достройка требует запущенного воркера. '.$worker['hint']
					.' Схему можно править вручную на холсте.',
				'worker' => $worker,
			], 503);
		}

		$instruction = trim((string) Arr::get($this->_body, 'instruction', ''));

		if ($instruction === '')
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Опишите, что изменить. Например: «добавь шаг проверки СБ после юриста».'], 422);
		}

		$queued = Service_Nj670_Process::queue_synthesize((int) $process['id'], $this->_uid, [
			'mode'        => 'copilot',
			'instruction' => $instruction,
			'node_id'     => (string) Arr::get($this->_body, 'node_id', ''),
			'text'        => (string) Arr::get($this->_body, 'text', ''),
		]);

		return Arr::get($queued, 'ok')
			? $this->_respond($queued)
			: $this->_respond($queued, 502);
	}

	protected function _audit()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$process = $this->_process();

		if ( ! $process)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Процесс не найден.'], 404);
		}

		$engine = (string) Arr::get($this->_body, 'engine', 'fast');

		if ($engine === 'llm' AND Service_Nj670_Process::worker_online())
		{
			$queued = Service_Nj670_Process::queue_audit((int) $process['id'], $this->_uid);

			if (Arr::get($queued, 'ok'))
			{
				return $this->_respond($queued);
			}
		}

		$fast = Service_Nj670_Diff::run_fast((int) $process['id'], $this->_uid);

		return Arr::get($fast, 'ok')
			? $this->_respond($fast)
			: $this->_respond($fast, 422);
	}

	protected function _report()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$process = $this->_process();

		if ( ! $process)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Процесс не найден.'], 404);
		}

		$kind = (string) Arr::get($this->_body, 'kind', 'plan') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);

		if ( ! $version)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Схема ещё не собрана.'], 422);
		}

		$audit = Service_Nj670_Process::latest_audit((int) $process['id'], TRUE);

		$queued = Service_Nj670_Process::worker_online()
			? Service_Nj670_Process::queue_report((int) $process['id'], $this->_uid,
				(int) $version['id'], $audit ? (int) $audit['id'] : 0)
			: ['ok' => FALSE, 'error' => 'Воркер не в сети — собираю регламент на сайте. '.Service_Nj670_Process::worker_status()['hint']];

		if (Arr::get($queued, 'ok'))
		{
			return $this->_respond($queued);
		}

		$local = Service_Nj670_Report::build_local_from(0, (int) $process['id'],
			(int) $version['id'], $audit ? (int) $audit['id'] : 0);

		return Arr::get($local, 'ok')
			? $this->_respond($local)
			: $this->_respond($local, 500);
	}

	protected function _delete()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$process = $this->_process();

		if ( ! $process)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Процесс не найден.'], 404);
		}

		$done = Service_Nj670_Process::delete_process((int) $process['id'], $this->_uid);

		return Arr::get($done, 'ok') ? $this->_respond($done) : $this->_respond($done, 422);
	}

	// ---------------------------------------------------------------- помощники

	/**
	 * Статус разбора документа в онтологию: страница опрашивает его, пока
	 * воркер не ответит. Это чтение — CSRF не требуется, аккаунт обязателен.
	 */
	protected function _ontology()
	{
		if ( ! $this->_uid)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Войдите на сайт.'], 401);
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);

		if ( ! $workspace)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Рабочее пространство не найдено.'], 404);
		}

		$id = (int) Arr::get($this->_body, 'id', 0);
		$job = $id > 0
			? Service_Nj670_Ontologyfeed::job($id, (int) $workspace['id'])
			: Service_Nj670_Ontologyfeed::latest((int) $workspace['id']);

		if ( ! $job)
		{
			return $this->_respond(['ok' => TRUE, 'job' => NULL]);
		}

		// Этап и секундомер берём из журнала очереди: страница показывает,
		// что именно делает воркер, а не только «в работе».
		$live = (int) Arr::get($job, 'job_id', 0) > 0
			? Service_Nj670_Process::job_state((int) $job['job_id'])
			: [];

		return $this->_respond([
			'ok'  => TRUE,
			'job' => [
				'id'          => (int) $job['id'],
				'status'      => (string) $job['status'],
				'error'       => (string) $job['error'],
				'source_name' => (string) $job['source_name'],
				'model'       => (string) $job['model'],
				'created_at'  => (string) $job['created_at'],
				'accepted_at' => (string) $job['accepted_at'],
				'stage'       => (string) Arr::get($live, 'stage', ''),
				'stages'      => (array) Arr::get($live, 'stages', []),
				'elapsed_sec' => (int) Arr::get($live, 'elapsed_sec', 0),
				'job_status'  => (string) Arr::get($live, 'status', ''),
			],
			'view' => (string) $job['status'] === Service_Nj670_Ontologyfeed::S_DONE
				? Service_Nj670_Ontologyfeed::view($job) : NULL,
		]);
	}

	/**
	 * Создать процесс (и по желанию сразу собрать схему из текста).
	 * Нужен агентам: тот же путь, что у страницы /process/create, но без
	 * формы, файлов и сессии.
	 */
	protected function _create()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$text = trim((string) Arr::get($this->_body, 'text', ''));
		$title = trim((string) Arr::get($this->_body, 'title', ''));

		if ($text === '' AND $title === '')
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Передайте title или text.'], 422);
		}

		if ($title === '' AND $text !== '')
		{
			$title = Service_Nj670_Repo::title_from_text($text);
		}

		$created = Service_Nj670_Process::create_with_graph($this->_uid, [
			'title'           => $title,
			'description'     => (string) Arr::get($this->_body, 'description', ''),
			'department'      => (string) Arr::get($this->_body, 'department', ''),
			'owner_role_code' => (string) Arr::get($this->_body, 'owner_role_code', ''),
			'parent_code'     => (string) Arr::get($this->_body, 'parent_code', ''),
			'text'            => $text,
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return $this->_respond($created, 422);
		}

		return $this->_respond([
			'ok'         => TRUE,
			'id'         => (int) $created['id'],
			'code'       => (string) $created['code'],
			'title'      => (string) $created['process']['title'],
			'queued'     => ! empty($created['queued']),
			'job_id'     => (int) Arr::get($created, 'job_id', 0),
			'fallback'   => ! empty($created['fallback']),
			'version_id' => (int) Arr::get($created, 'version_id', 0),
			'warning'    => (string) Arr::get($created, 'warning', ''),
			'error'      => (string) Arr::get($created, 'error', ''),
		], 201);
	}

	/**
	 * Сохранить схему. version_id — правка существующей версии; без него
	 * создаётся новая версия (её и вернёт холст).
	 */
	protected function _graph()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$process = $this->_process();

		if ( ! $process)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Процесс не найден.'], 404);
		}

		$graph = Arr::get($this->_body, 'graph', NULL);

		if ( ! is_array($graph))
		{
			$graph = [
				'nodes' => Arr::get($this->_body, 'nodes', []),
				'edges' => Arr::get($this->_body, 'edges', []),
				'lanes' => Arr::get($this->_body, 'lanes', []),
				'meta'  => (array) Arr::get($this->_body, 'meta', []),
			];
		}

		if (empty($graph['nodes']) OR ! is_array($graph['nodes']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'В графе нет шагов (nodes).'], 422);
		}

		$version_id = (int) Arr::get($this->_body, 'version_id', 0);

		if ($version_id > 0)
		{
			$version = Service_Nj670_Process::version($version_id);

			if ( ! $version OR (int) $version['process_id'] !== (int) $process['id'])
			{
				return $this->_respond(['ok' => FALSE, 'error' => 'Версия не найдена у этого процесса.'], 404);
			}

			if (in_array((string) $version['status'], [Service_Nj670_Process::S_QUEUED, Service_Nj670_Process::S_ARCHIVED], TRUE))
			{
				return $this->_respond(['ok' => FALSE, 'error' => 'Версия в очереди или архиве — правка недоступна.'], 409);
			}

			$saved = Service_Nj670_Process::save_graph($version_id, $graph, $this->_uid, ['reflow' => FALSE]);

			if ( ! Arr::get($saved, 'ok'))
			{
				return $this->_respond($saved, 422);
			}

			return $this->_respond([
				'ok'         => TRUE,
				'code'       => (string) $process['code'],
				'version_id' => $version_id,
				'updated'    => TRUE,
				'graph'      => $saved['graph'],
				'metrics'    => $saved['metrics'],
			]);
		}

		$kind = (string) Arr::get($this->_body, 'kind', 'plan') === 'fact' ? 'fact' : 'plan';
		$status = (string) Arr::get($this->_body, 'status', Service_Nj670_Process::S_DRAFT);

		if ( ! in_array($status, [Service_Nj670_Process::S_DRAFT, Service_Nj670_Process::S_IN_REVIEW], TRUE))
		{
			$status = Service_Nj670_Process::S_DRAFT;
		}

		$created = Service_Nj670_Process::create_version((int) $process['id'], $kind, [
			'status'      => $status,
			'graph'       => $graph,
			'version_tag' => (string) Arr::get($this->_body, 'version_tag', ''),
			'source_kind' => 'api',
			'created_by'  => $this->_uid,
			'model'       => 'json-api',
			'activate'    => (bool) Arr::get($this->_body, 'activate', FALSE),
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return $this->_respond($created, 422);
		}

		$version = $created['version'];
		$saved = Service_Nj670_Process::graph_of($version);

		return $this->_respond([
			'ok'         => TRUE,
			'code'       => (string) $process['code'],
			'kind'       => $kind,
			'version_id' => (int) $created['id'],
			'updated'    => FALSE,
			'graph'      => $saved,
			'metrics'    => Service_Nj670_Graph::metrics($saved,
				Service_Nj670_Ontology::index((int) $process['workspace_id'])),
		], 201);
	}

	/** Справочник компании для агента: коды ролей, систем, артефактов. */
	protected function _dictionary()
	{
		if ( ! $this->_uid)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Войдите на сайт или передайте Bearer-ключ API.'], 401);
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);

		if ( ! $workspace)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Рабочее пространство не найдено.'], 404);
		}

		$grouped = Service_Nj670_Ontology::grouped((int) $workspace['id']);
		$out = ['ok' => TRUE, 'kinds' => [], 'entities' => []];

		foreach ($grouped as $kind => $group)
		{
			$items = [];

			foreach ((array) Arr::get($group, 'items', []) as $item)
			{
				$items[] = [
					'id'         => (int) $item['id'],
					'code'       => (string) $item['code'],
					'name'       => (string) $item['name'],
					'attributes' => (array) Arr::get($item, 'attributes', []),
				];
			}

			$out['kinds'][$kind] = (string) Arr::get($group, 'label', $kind);
			$out['entities'][$kind] = $items;
		}

		return $this->_respond($out);
	}

	/** Upsert сущности справочника (kind + name + code). */
	protected function _ontology_save()
	{
		$auth = $this->_auth(TRUE);

		if ($auth !== NULL)
		{
			return $auth;
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);

		if ( ! $workspace)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Рабочее пространство не найдено.'], 404);
		}

		$data = $this->_body;
		$data['class_type'] = (string) Arr::get($data, 'kind', Arr::get($data, 'class_type', ''));
		$done = Service_Nj670_Ontology::upsert((int) $workspace['id'], $data);

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
		}

		return $this->_respond([
			'ok'      => TRUE,
			'id'      => (int) $done['id'],
			'updated' => ! empty($done['updated']),
			'kind'    => (string) $data['class_type'],
			'code'    => (string) Arr::get($done, 'code', Arr::get($data, 'code', '')),
		], ! empty($done['updated']) ? 200 : 201);
	}

	/** Bearer-ключ n2jmcp_… → user_id (0 — ключа нет или он неверный). */
	protected function _token_uid()
	{
		$header = (string) $this->request->headers('authorization');

		if ($header === '' AND isset($_SERVER['HTTP_AUTHORIZATION']))
		{
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		}

		if ($header === '' AND isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
		{
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if ( ! preg_match('~^Bearer\s+(n2jmcp_[a-f0-9]+)$~i', trim($header), $m))
		{
			return 0;
		}

		if ( ! class_exists('Service_Nj160_Tokens'))
		{
			return 0;
		}

		list($ok, $uid) = Service_Nj160_Tokens::authenticate($m[1]);

		return $ok ? (int) $uid : 0;
	}

	protected function _auth($mutate = FALSE)
	{
		if ( ! $this->_uid)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Войдите на сайт или передайте Bearer-ключ API.'], 401);
		}

		// Bearer-ключ не уязвим к CSRF: он не отправляется браузером
		// автоматически. Для сессии защита остаётся обязательной.
		if ($mutate AND ! $this->_token AND ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		if ($mutate AND ! $this->_start)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Нужны права на запуск сборок.'], 403);
		}

		return NULL;
	}

	protected function _process()
	{
		$process = Service_Nj670_Process::process((string) Arr::get($this->_body, 'code', ''));

		if ( ! $process)
		{
			return NULL;
		}

		if ( ! Service_Nj670_Process::can_view($process, $this->_uid, $this->_admin))
		{
			return NULL;
		}

		return $process;
	}

	protected function _version_of(array $process)
	{
		$version_id = (int) Arr::get($this->_body, 'version_id', 0);

		if ($version_id > 0)
		{
			$version = Service_Nj670_Process::version($version_id);

			if ($version AND (int) $version['process_id'] === (int) $process['id'])
			{
				return $version;
			}

			return NULL;
		}

		$kind = (string) Arr::get($this->_body, 'kind', 'plan') === 'fact' ? 'fact' : 'plan';

		return Service_Nj670_Process::current_version($process, $kind);
	}

	protected function _respond(array $data, $status = 200)
	{
		return $this->response->status((int) $status)
			->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
