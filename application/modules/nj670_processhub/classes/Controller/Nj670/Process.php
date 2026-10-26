<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ProcessHub — страницы модуля (nj670).
 *
 * Логика в Service_Nj670_Process; здесь только разбор запроса, права и выбор
 * шаблона. Ни одного SQL-запроса: контроллер не знает структуру таблиц.
 */
class Controller_Nj670_Process extends Controller_Erp100_Base
{
	protected $_uid = 0;
	protected $_start = FALSE;
	protected $_admin = FALSE;
	protected $_notice = '';

	public function before()
	{
		parent::before();

		$this->_uid = $this->_current_user ? (int) $this->_current_user->id : 0;
		$this->_start = ($this->_uid > 0 AND (bool) $this->can('nj670.start'));
		$this->_admin = (bool) $this->can('nj670.admin');

		$nav = [
			['title' => 'Процессы', 'href' => '/process', 'icon' => 'fas fa-diagram-project'],
		];

		if ($this->_start)
		{
			$nav[] = ['title' => 'Новый процесс', 'href' => '/process/create', 'icon' => 'fas fa-wand-magic-sparkles'];
			$nav[] = ['title' => 'Онтология', 'href' => '/process/ontology', 'icon' => 'fas fa-sitemap'];
			$nav[] = ['title' => 'Карта', 'href' => '/process/map', 'icon' => 'fas fa-building'];
			$nav[] = ['title' => 'Демо', 'href' => '/process/demo', 'icon' => 'fas fa-circle-play'];
		}

		if ($this->_admin)
		{
			$nav[] = ['title' => 'Админ', 'href' => '/process/admin', 'icon' => 'fas fa-gauge-high'];
		}

		$this->set_module_nav($nav);
		$this->set_title('ProcessHub — онтология, схемы и аудит бизнес-процессов');
		$this->set_description('Опишите регламент словами — ProcessHub соберёт BPMN-схему, сравнит её с журналом событий, найдёт теневые шаги и обход контроля и выпустит регламент в DOCX/PDF.');
		$this->set_keywords('бизнес-процесс, bpmn, онтология, process mining, аудит, регламент, gost, docx, pdf');

		$this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
		$this->response->headers('Pragma', 'no-cache');

		$this->_notice = $this->_notice_from_query();
	}

	protected function _notice_from_query()
	{
		$map = [
			'created'  => 'Процесс создан. Схема собрана и ждёт проверки.',
			'queued'   => 'Задача поставлена в очередь. Схема обновится автоматически.',
			'imported' => 'BPMN-схема импортирована: дорожки, задачи и условия перенесены. Проверьте роли и утвердите план.',
			'saved'    => 'Схема сохранена.',
			'fact'     => 'Журнал разобран: схема «Факт» построена.',
			'audit'    => 'Аудит завершён, разрывы записаны в отчёт.',
			'report'   => 'Регламент выпущен: DOCX и PDF готовы к скачиванию.',
			'demo'     => 'Демо-сценарий загружен: план, факт и аудит готовы.',
			'deleted'  => 'Процесс удалён.',
			'approved' => 'План утверждён: версия активна, прежняя отправлена в архив.',
			'feed_queued' => 'Документ отправлен на разбор. Предложение появится здесь через минуту.',
			'accepted' => 'Предложение принято: справочник и процессы обновлены.',
			'approve_failed' => 'Утвердить не удалось: проверьте права и статус версии.',
		];

		$ok = (string) $this->request->query('ok');

		return isset($map[$ok]) ? $map[$ok] : '';
	}

	protected function page($view, array $data = [])
	{
		$this->template->content = View::factory('670/'.$view, $data + [
			'uid'     => $this->_uid,
			'can_start' => $this->_start,
			'is_admin'=> $this->_admin,
			'csrf'    => Security::token(),
			'notice'  => $this->_notice,
			'warn'    => UTF8::substr(trim((string) $this->request->query('warn')), 0, 300),
			'error'   => '',
		]);
	}

	protected function login_wall($title = 'ProcessHub — по аккаунту')
	{
		$this->template->content = Gate::wall('process', [
			'title'  => $title,
			'icon'   => 'fas fa-diagram-project',
			'text'   => 'ProcessHub хранит схемы, версии и аудиты в вашем рабочем пространстве. Войдите, чтобы собрать первый процесс или открыть готовое демо.',
			'points' => [
				'Текст регламента или PDF → схема BPMN с дорожками',
				'Журнал Case_ID / Activity / Timestamp → фактическая схема',
				'AI-аудит: теневые шаги, обход контроля, цена потерь',
				'Регламент DOCX/PDF по ГОСТ с матрицей RACI',
			],
			'cta'    => 'Войти и открыть ProcessHub',
		]);
	}

	protected function check_post()
	{
		if ($this->request->method() !== Request::POST)
		{
			throw HTTP_Exception::factory(405);
		}

		if ( ! $this->_uid)
		{
			throw HTTP_Exception::factory(401);
		}

		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			throw HTTP_Exception::factory(403, 'Обновите страницу: защитный токен устарел.');
		}

		if ( ! $this->_start)
		{
			throw HTTP_Exception::factory(403, 'Запуск сборок доступен привилегированным аккаунтам и администраторам.');
		}
	}

	protected function _process($code)
	{
		$process = Service_Nj670_Process::process((string) $code);

		if ( ! $process)
		{
			throw HTTP_Exception::factory(404, 'Процесс не найден.');
		}

		if ( ! Service_Nj670_Process::can_view($process, $this->_uid, $this->_admin))
		{
			throw HTTP_Exception::factory(403, 'Это чужое рабочее пространство.');
		}

		return $process;
	}

	// ------------------------------------------------------------------ витрина

	public function action_index()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall();
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);
		$all = $workspace ? Service_Nj670_Process::for_workspace((int) $workspace['id'], 100) : [];
		// Витрине нужны простые списки «код + имя»: grouped() завёрнут в
		// подписи разделов и годится только для страницы справочника.
		$ontology = $workspace ? Service_Nj670_Ontology::prompt_dictionary((int) $workspace['id']) : [];

		$query = [
			'q'          => UTF8::substr(trim((string) $this->request->query('q')), 0, 120),
			'department' => UTF8::substr(trim((string) $this->request->query('department')), 0, 255),
			'owner'      => (int) $this->request->query('owner'),
			'state'      => (string) $this->request->query('state'),
		];

		$processes = Service_Nj670_Process::filter($all, $query);
		$facets = Service_Nj670_Process::facets($all);

		$totals = ['processes' => count($processes), 'audits' => 0, 'impact' => 0.0, 'reports' => 0, 'demo' => NULL];

		foreach ($processes as $process)
		{
			$totals['audits'] += (int) $process['audits_count'];
			$totals['reports'] += (int) $process['reports_count'];
			$totals['impact'] += (float) $process['audit_impact'];

			if ( ! empty($process['is_demo']))
			{
				$totals['demo'] = $process;
			}
		}

		$this->page('index', [
			'workspace' => $workspace,
			'processes' => $processes,
			'query'     => $query,
			'facets'    => $facets,
			'all_count' => count($all),
			'ontology'  => $ontology,
			'totals'    => $totals,
			'limits'    => Service_Nj670_Process::limits(),
			'statuses'  => Service_Nj670_Process::statuses(),
			'worker'    => Service_Nj670_Process::worker_status(),
		]);
	}

	// ------------------------------------------------------------------ создание

	public function action_create()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Создать процесс — ProcessHub');
		}

		if ( ! $this->_start)
		{
			throw HTTP_Exception::factory(403, 'Создавать процессы могут привилегированные аккаунты и администраторы.');
		}

		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();
			$error = $this->_create($post);
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);

		$this->set_title('Новый процесс — ProcessHub');

		$this->page('create', [
			'parents'   => $workspace ? Service_Nj670_Process::for_workspace((int) $workspace['id'], 200) : [],
			'post'      => (array) $this->request->post(),
			'ontology'  => $workspace ? Service_Nj670_Ontology::prompt_dictionary((int) $workspace['id']) : [],
			'limits'    => Service_Nj670_Process::limits(),
			'allow_rules' => (bool) Arr::get(Service_Nj670_Process::config(), 'allow_rule_fallback', TRUE),
			'overlays' => Service_Nj670_Graph::overlays(),
			'worker'    => Service_Nj670_Process::worker_status(),
			'error'     => $error,
		]);
	}

	protected function _create(array $post)
	{
		if ( ! Security::check((string) Arr::get($post, 'csrf')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		// BPMN из другого редактора — отдельная ветка: модель не нужна,
		// схема уже нарисована, импорт только переводит её в наш граф.
		if ( ! empty($_FILES['bpmn']['name']))
		{
			$imported = Service_Nj670_Bpmn::import($this->_uid, $_FILES['bpmn']);

			if ( ! Arr::get($imported, 'ok'))
			{
				return (string) Arr::get($imported, 'error', 'Не удалось импортировать BPMN.');
			}

			$query = 'ok=imported';

			if ( ! empty($imported['warnings']))
			{
				$query .= '&warn='.rawurlencode(UTF8::substr(implode(' ', (array) $imported['warnings']), 0, 300));
			}

			HTTP::redirect('/process/'.$imported['process']['code'].'/editor?'.$query, 303);
		}

		$title = UTF8::substr(trim((string) Arr::get($post, 'title', '')), 0, 255);
		$text = trim((string) Arr::get($post, 'text', ''));
		$source = [];

		if ( ! empty($_FILES['source']['name']))
		{
			// Файл сохраняем ДО создания процесса: при ошибке не остаётся
			// пустой карточки, а источник можно переиспользовать.
			$source = Service_Nj670_Process::store_source(0, $_FILES['source']);

			if ( ! Arr::get($source, 'ok'))
			{
				return (string) Arr::get($source, 'error');
			}

			if ((string) $source['kind'] === 'text' AND $text === '')
			{
				$text = (string) $source['text'];
			}
		}

		// «Текст регламента» — единственное обязательное поле. Название не
		// требуем: придумываем его из первой осмысленной строки или из имени
		// файла. Пустая карточка «Новый процесс» в списке никому не нужна.
		if ($title === '')
		{
			$hint = $text;

			if ($hint === '' AND (string) Arr::get($source, 'kind', 'text') !== 'text')
			{
				$hint = (string) Arr::get($source, 'filename', '');
			}

			$title = Service_Nj670_Repo::title_from_text($hint);
		}

		if ($text === '' AND ! $source)
		{
			return 'Вставьте текст регламента или приложите файл.';
		}

		if ($text === '' AND (string) Arr::get($source, 'kind') !== 'text')
		{
			// Текст родится в воркере после OCR/транскрипции; заглушка нужна,
			// чтобы создать версию и поставить задачу.
			$text = 'Источник: '.(string) Arr::get($source, 'filename', 'файл');
		}

		// Без живого воркера бинарный источник разбирать некому: PDF/скан/аудио
		// не должны создавать процесс-пустышку, который останется «в работе».
		$worker = Service_Nj670_Process::worker_status();
		$online = (bool) $worker['online'];
		$source_kind = (string) Arr::get($source, 'kind', 'text');

		if ( ! $online AND $source_kind !== 'text')
		{
			return 'PDF, сканы и аудио разбирает воркер, а он сейчас не в сети. '
				.(string) $worker['hint'].' Вставьте текст регламента — схема соберётся по правилам сразу.';
		}

		$created = Service_Nj670_Process::create_process($this->_uid, [
			'title'       => $title,
			'description' => (string) Arr::get($post, 'description', ''),
			'department'  => (string) Arr::get($post, 'department', ''),
			'owner_role_code' => (string) Arr::get($post, 'owner_role_code', ''),
			'parent_code' => (string) Arr::get($post, 'parent_code', ''),
		]);

		if ( ! Arr::get($created, 'ok'))
		{
			return (string) Arr::get($created, 'error');
		}

		$process = $created['process'];

		$queued = $online
			? Service_Nj670_Process::queue_synthesize((int) $process['id'], $this->_uid, [
				'text'   => $text,
				'source' => $source,
				'mode'   => 'full',
			])
			: ['ok' => FALSE, 'error' => 'Воркер не в сети — собираю схему по правилам. '.$worker['hint']];

		if ( ! Arr::get($queued, 'ok'))
		{
			$allow = (bool) Arr::get(Service_Nj670_Process::config(), 'allow_rule_fallback', TRUE);

			if ($allow AND (string) Arr::get($source, 'kind', 'text') === 'text')
			{
				$workspace = Service_Nj670_Process::workspace_for($this->_uid);
				$ontology = Service_Nj670_Ontology::index((int) $workspace['id']);
				$build = Service_Nj670_Synthesis::from_text($text, $ontology, [
					'title' => $title,
					'mode'  => 'plan',
				]);

				if (Arr::get($build, 'ok'))
				{
					Service_Nj670_Process::create_version((int) $process['id'], 'plan', [
						'status'      => Service_Nj670_Process::S_IN_REVIEW,
						'graph'       => $build['graph'],
						'source_kind' => 'text',
						'source_text' => $text,
						'created_by'  => $this->_uid,
						'model'       => 'rules-engine',
						'activate'    => TRUE,
					]);

					HTTP::redirect('/process/'.$process['code'].'/editor?ok=created', 303);
				}
			}

			return (string) Arr::get($queued, 'error', 'Очередь недоступна.') .
				' Процесс создан — схему можно нарисовать вручную или повторить сборку позже.';
		}

		HTTP::redirect('/process/'.$process['code'].'/editor?ok=queued', 303);
	}

	public function action_demo()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Демо ProcessHub');
		}

		$error = '';

		if ($this->request->method() === Request::POST)
		{
			if ( ! Security::check((string) $this->request->post('csrf')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			elseif ( ! $this->_start)
			{
				$error = 'Загружать демо могут привилегированные аккаунты и администраторы.';
			}
			else
			{
				$done = Service_Nj670_Demo::seed($this->_uid, 'procurement', [
					'fresh' => (bool) $this->request->post('fresh'),
				]);

				if (Arr::get($done, 'ok'))
				{
					HTTP::redirect('/process/'.$done['process']['code'].'?ok=demo', 303);
				}

				$error = (string) Arr::get($done, 'error', 'Не удалось загрузить демо.');
			}
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);
		$existing = NULL;

		if ($workspace)
		{
			foreach (Service_Nj670_Process::for_workspace((int) $workspace['id'], 100) as $process)
			{
				if ( ! empty($process['is_demo']))
				{
					$existing = $process;
					break;
				}
			}
		}

		$this->set_title('Демо-сценарий — ProcessHub');

		$this->page('demo', [
			'existing' => $existing,
			'scenario' => (array) Arr::get(Service_Nj670_Process::config(), 'demo', []),
			'error'    => $error,
		]);
	}

	// ------------------------------------------------------------------ холст

	public function action_editor()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall();
		}

		$process = $this->_process($this->request->param('code'));
		$kind = (string) $this->request->query('kind') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);

		if ( ! $version AND $kind === 'fact')
		{
			$kind = 'plan';
			$version = Service_Nj670_Process::current_version($process, 'plan');
		}

		$workspace = Service_Nj670_Process::workspace((int) $process['workspace_id']);
		$ontology = Service_Nj670_Ontology::index((int) $process['workspace_id']);
		$graph = $version ? Service_Nj670_Process::graph_of($version) : Service_Nj670_Graph::empty_graph((string) $process['title']);

		$this->set_title((string) $process['title'].' — ProcessHub');

		$siblings = Service_Nj670_Process::for_workspace((int) $process['workspace_id'], 200);
		$parent_process = (int) Arr::get($process, 'parent_id', 0) > 0
			? Service_Nj670_Process::process_by_id((int) $process['parent_id'])
			: NULL;

		$this->page('editor', [
			'process'  => $process,
			'siblings' => $siblings,
			'parent_process' => $parent_process,
			'child_processes' => Service_Nj670_Process::children((int) $process['id'], (int) $process['workspace_id']),
			'version'  => $version,
			'job'      => $version ? Service_Nj670_Process::job_state((int) $version['job_id']) : [],
			'graph'    => $graph,
			'graph_json' => json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'metrics'  => Service_Nj670_Graph::metrics($graph, $ontology),
			'plan'     => Service_Nj670_Process::current_version($process, 'plan'),
			'fact'     => Service_Nj670_Process::current_version($process, 'fact'),
			'kind'     => $kind,
			'ontology' => $ontology,
			'grouped'  => Service_Nj670_Ontology::grouped((int) $process['workspace_id']),
			'statuses' => Service_Nj670_Process::statuses(),
			'versions' => Service_Nj670_Process::versions((int) $process['id'], NULL, 12),
			'imports'  => Service_Nj670_Process::imports((int) $process['id'], 5),
			'audit'    => Service_Nj670_Process::latest_audit((int) $process['id']),
			'reports_count' => count(Service_Nj670_Process::reports((int) $process['id'], 20)),
			'allow_rules' => (bool) Arr::get(Service_Nj670_Process::config(), 'allow_rule_fallback', TRUE),
		]);
	}

	public function action_simulate()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Симуляция процесса — ProcessHub');
		}

		$process = $this->_process($this->request->param('code'));
		$kind = (string) $this->request->query('kind') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);

		if ( ! $version AND $kind === 'fact')
		{
			$kind = 'plan';
			$version = Service_Nj670_Process::current_version($process, 'plan');
		}

		$graph = $version ? Service_Nj670_Process::graph_of($version) : Service_Nj670_Graph::empty_graph((string) $process['title']);
		$index = Service_Nj670_Ontology::index((int) $process['workspace_id']);

		$input = [
			'volume'   => (int) $this->request->query('volume'),
			'workdays' => (int) $this->request->query('workdays'),
			'workday_minutes' => (int) $this->request->query('workday_minutes'),
			'people'   => (array) $this->request->query('people'),
		];

		if ($input['volume'] <= 0)
		{
			$input['volume'] = 200;
		}

		if ($input['workdays'] <= 0)
		{
			$input['workdays'] = 21;
		}

		if ($input['workday_minutes'] <= 0)
		{
			$input['workday_minutes'] = 480;
		}

		$this->set_title('Что если — '.(string) $process['title']);

		$this->page('simulate', [
			'process' => $process,
			'version' => $version,
			'kind'    => $kind,
			'graph'   => $graph,
			'input'   => $input,
			'sim'     => Service_Nj670_Simulate::run($graph, $index, $input),
			'roles'   => (array) Arr::path($index, 'list.role', []),
			'metrics' => Service_Nj670_Graph::metrics($graph, $index),
		]);
	}

	public function action_passport()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Паспорт процесса — ProcessHub');
		}

		$process = $this->_process($this->request->param('code'));
		$workspace = Service_Nj670_Process::workspace((int) $process['workspace_id']);
		$index = Service_Nj670_Ontology::index((int) $process['workspace_id']);

		$plan = Service_Nj670_Process::current_version($process, 'plan');
		$fact = Service_Nj670_Process::current_version($process, 'fact');
		$plan_graph = $plan ? Service_Nj670_Process::graph_of($plan) : [];
		$fact_graph = $fact ? Service_Nj670_Process::graph_of($fact) : [];

		$versions = Service_Nj670_Process::versions((int) $process['id'], NULL, 30);
		$audits = Service_Nj670_Process::audits((int) $process['id'], 10);
		$latest_audit = $audits ? $audits[0] : NULL;

		$calls = [];
		foreach (Service_Nj670_Process::calls((int) $process['workspace_id']) as $call)
		{
			if ((string) $call['from_code'] === (string) $process['code'])
			{
				$calls[] = $call;
			}
		}

		$parent = (int) Arr::get($process, 'parent_id', 0) > 0
			? Service_Nj670_Process::process_by_id((int) $process['parent_id'])
			: NULL;

		// Комментарии ревьюера берём из актуальной план-схемы: именно её
		// согласуют перед публикацией регламента.
		$comments = [];

		foreach ((array) Arr::get($plan_graph, 'nodes', []) as $node)
		{
			$properties = (array) Arr::get($node, 'properties', []);
			$comment = trim((string) Arr::get($properties, 'comment', ''));
			$overlay = trim((string) Arr::get($properties, 'overlay', ''));

			if ($comment === '' AND $overlay === '')
			{
				continue;
			}

			$comments[] = [
				'section' => (string) Arr::get($node, 'title', ''),
				'overlay' => (string) Arr::get(Service_Nj670_Report::overlay_labels(), $overlay, ''),
				'comment' => $comment,
			];
		}

		$this->set_title('Паспорт — '.(string) $process['title']);

		$this->page('passport', [
			'process'      => $process,
			'workspace'    => $workspace,
			'job'          => Service_Nj670_Process::first_running_job($versions),
			'plan'         => $plan,
			'fact'         => $fact,
			'plan_graph'   => $plan_graph,
			'fact_graph'   => $fact_graph,
			'plan_metrics' => $plan ? Service_Nj670_Graph::metrics($plan_graph, $index) : NULL,
			'fact_metrics' => $fact ? Service_Nj670_Graph::metrics($fact_graph, $index) : NULL,
			'coverage_plan'=> Service_Nj670_Process::coverage($plan_graph),
			'coverage_fact'=> Service_Nj670_Process::coverage($fact_graph),
			'architecture' => Service_Nj670_Process::architecture($plan_graph, $fact_graph, $index),
			'raci'         => Service_Nj670_Graph::raci($plan_graph ?: $fact_graph,
				(string) Arr::get($process, 'owner_role_code', ''), $index),
			'versions'     => $versions,
			'audits'       => $audits,
			'latest_audit' => $latest_audit,
			'findings'     => $latest_audit ? Service_Nj670_Process::json_decode(Arr::get($latest_audit, 'findings', [])) : [],
			'reports'      => Service_Nj670_Process::reports((int) $process['id'], 20),
			'calls'        => $calls,
			'children'     => Service_Nj670_Process::children((int) $process['id'], (int) $process['workspace_id']),
			'parent'       => $parent,
			'comments'     => $comments,
			'statuses'     => Service_Nj670_Process::statuses(),
			'node_types'   => Service_Nj670_Process::node_types(),
		]);
	}

	public function action_fact()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Факт процесса — ProcessHub');
		}

		$process = $this->_process($this->request->param('code'));

		if ( ! $this->_start)
		{
			throw HTTP_Exception::factory(403, 'Загружать журнал могут привилегированные аккаунты и администраторы.');
		}

		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$error = $this->_mine($process);
		}

		$version = Service_Nj670_Process::current_version($process, 'fact');
		$workspace = Service_Nj670_Process::workspace((int) $process['workspace_id']);
		$ontology = Service_Nj670_Ontology::index((int) $process['workspace_id']);
		$graph = $version ? Service_Nj670_Process::graph_of($version) : NULL;

		$this->set_title('Факт: журнал событий — '.(string) $process['title']);

		$this->page('fact', [
			'process' => $process,
			'version' => $version,
			'job'     => $version ? Service_Nj670_Process::first_running_job([$version]) : [],
			'graph'   => $graph,
			'metrics' => $graph ? Service_Nj670_Graph::metrics($graph, $ontology) : NULL,
			'svg'     => $graph ? Service_Nj670_Graph::svg($graph, ['mode' => 'fact', 'ontology' => $ontology, 'compact' => TRUE]) : '',
			'imports' => Service_Nj670_Process::imports((int) $process['id'], 20),
			'plan'    => Service_Nj670_Process::current_version($process, 'plan'),
			'limits'  => Service_Nj670_Process::limits(),
			'error'   => $error,
		]);
	}

	protected function _mine(array $process)
	{
		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		$bytes = '';
		$kind = 'csv';
		$filename = 'journal.csv';
		$stored_path = '';
		$size = 0;

		if ( ! empty($_FILES['journal']['name']))
		{
			$stored = Service_Nj670_Process::store_upload((int) $process['id'], $_FILES['journal']);

			if ( ! Arr::get($stored, 'ok'))
			{
				return (string) Arr::get($stored, 'error');
			}

			$bytes = (string) file_get_contents($stored['full_path']);
			$kind = (string) $stored['kind'];
			$filename = (string) $stored['filename'];
			$stored_path = (string) $stored['stored_path'];
			$size = (int) $stored['size_bytes'];
		}
		else
		{
			$bytes = (string) $this->request->post('journal');
			$size = strlen($bytes);

			if (trim($bytes) === '')
			{
				return 'Приложите CSV/XLSX или вставьте журнал текстом.';
			}
		}

		if ($kind === 'text')
		{
			return 'Для факта нужен CSV или XLSX с колонками Case_ID, Activity, Timestamp, Resource.';
		}

		$workspace = Service_Nj670_Process::workspace((int) $process['workspace_id']);
		$ontology = Service_Nj670_Ontology::index((int) $process['workspace_id']);
		$mined = Service_Nj670_Mining::analyze($bytes, $kind, $ontology);

		if ( ! Arr::get($mined, 'ok'))
		{
			return (string) Arr::get($mined, 'error');
		}

		$graph = $mined['graph'];
		$graph['meta']['title'] = 'Факт: '.(string) $process['title'];
		$graph['meta']['source'] = 'process-mining';
		$graph['meta']['note'] = 'Кейсов: '.(int) $mined['stats']['cases'].', событий: '.(int) $mined['stats']['events'].'.';

		$version = Service_Nj670_Process::create_version((int) $process['id'], 'fact', [
			'status'      => Service_Nj670_Process::S_ACTIVE,
			'graph'       => $graph,
			'source_kind' => $kind === 'xlsx' ? 'xlsx' : 'csv',
			'source_text' => '',
			'created_by'  => $this->_uid,
			'model'       => 'process-mining',
			'activate'    => TRUE,
		]);

		if ( ! Arr::get($version, 'ok'))
		{
			return (string) Arr::get($version, 'error', 'Не удалось сохранить схему факта.');
		}

		Service_Nj670_Process::record_import((int) $process['id'], $this->_uid, [
			'kind'        => $kind === 'xlsx' ? 'xlsx' : 'csv',
			'filename'    => $filename,
			'stored_path' => $stored_path,
			'size_bytes'  => $size,
			'rows_count'  => (int) $mined['stats']['events'],
		]);

		HTTP::redirect('/process/'.$process['code'].'/fact?ok=fact', 303);
	}

	// ------------------------------------------------------------------ утверждение

	/**
	 * Утверждение версии плана: in_review|draft → active. Без этого шага
	 * схема, собранная моделью, навсегда оставалась «на рассмотрении»,
	 * хотя статус active уже был заложен в жизненный цикл версий.
	 */
	public function action_approve()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Утвердить план — ProcessHub');
		}

		$process = $this->_process($this->request->param('code'));

		if ($this->request->method() !== Request::POST)
		{
			HTTP::redirect('/process/'.$process['code'].'/editor', 303);
		}

		if ( ! $this->_start)
		{
			throw HTTP_Exception::factory(403, 'Утверждать версии могут привилегированные аккаунты и администраторы.');
		}

		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			HTTP::redirect('/process/'.$process['code'].'/editor?ok=approve_failed', 303);
		}

		$version_id = (int) $this->request->post('version_id');
		$version = $version_id > 0
			? Service_Nj670_Process::version($version_id)
			: Service_Nj670_Process::current_version($process, 'plan');

		if ( ! $version OR (int) $version['process_id'] !== (int) $process['id'])
		{
			HTTP::redirect('/process/'.$process['code'].'/editor?ok=approve_failed', 303);
		}

		if ((string) $version['kind'] !== 'plan')
		{
			// Факт активируется при загрузке журнала; утверждать его вручную
			// не нужно — иначе можно «утвердить» устаревший срез.
			HTTP::redirect('/process/'.$process['code'].'/editor?ok=approve_failed', 303);
		}

		if ( ! in_array((string) $version['status'], [
			Service_Nj670_Process::S_DRAFT,
			Service_Nj670_Process::S_IN_REVIEW,
		], TRUE))
		{
			HTTP::redirect('/process/'.$process['code'].'/editor?ok=approve_failed', 303);
		}

		$done = Service_Nj670_Process::activate_version((int) $process['id'], (int) $version['id'], $this->_uid);

		HTTP::redirect('/process/'.$process['code'].'/editor?ok='.(Arr::get($done, 'ok') ? 'approved' : 'approve_failed'), 303);
	}

	// ------------------------------------------------------------------ аудит

	public function action_audit()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('AI-аудит — ProcessHub');
		}

		$process = $this->_process($this->request->param('code'));
		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$error = $this->_audit($process);
		}

		$plan = Service_Nj670_Process::current_version($process, 'plan');
		$fact = Service_Nj670_Process::current_version($process, 'fact');
		$workspace = Service_Nj670_Process::workspace((int) $process['workspace_id']);
		$ontology = Service_Nj670_Ontology::index((int) $process['workspace_id']);
		$audit = Service_Nj670_Process::latest_audit((int) $process['id']);

		$plan_graph = $plan ? Service_Nj670_Process::graph_of($plan) : NULL;
		$fact_graph = $fact ? Service_Nj670_Process::graph_of($fact) : NULL;

		$this->set_title('AI-аудит — '.(string) $process['title']);

		$this->page('audit', [
			'process'   => $process,
			'plan'      => $plan,
			'fact'      => $fact,
			'audit'     => $audit,
			'findings'  => $audit ? Service_Nj670_Process::json_decode(Arr::get($audit, 'findings', [])) : [],
			'severities'=> Service_Nj670_Process::finding_severities($audit ?: []),
			'plan_metrics' => $plan_graph ? Service_Nj670_Graph::metrics($plan_graph, $ontology) : NULL,
			'fact_metrics' => $fact_graph ? Service_Nj670_Graph::metrics($fact_graph, $ontology) : NULL,
			'plan_graph' => $plan_graph,
			'fact_graph' => $fact_graph,
			'plan_svg'   => $plan_graph ? Service_Nj670_Graph::svg($plan_graph, ['mode' => 'plan', 'ontology' => $ontology, 'compact' => TRUE]) : '',
			'fact_svg'   => $fact_graph ? Service_Nj670_Graph::svg($fact_graph, ['mode' => 'fact', 'ontology' => $ontology, 'compact' => TRUE]) : '',
			'audits'    => Service_Nj670_Process::audits((int) $process['id'], 10),
			'job'       => $audit ? Service_Nj670_Process::job_state((int) $audit['job_id']) : [],
			'allow_rules' => (bool) Arr::get(Service_Nj670_Process::config(), 'allow_rule_fallback', TRUE),
			'error'     => $error,
		]);
	}

	protected function _audit(array $process)
	{
		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		if ( ! $this->_start)
		{
			return 'Запускать аудит могут привилегированные аккаунты и администраторы.';
		}

		// Request::post($key, $default) в этой кодовой базе возвращает Request,
		// а не значение по умолчанию: без формы запрос падал на приведении к строке.
		$engine = (string) Arr::get($_POST, 'engine', 'fast');

		// LLM-аудит живёт в очереди: без воркера он остался бы висеть «в работе».
		if ($engine === 'llm' AND Service_Nj670_Process::worker_online())
		{
			$queued = Service_Nj670_Process::queue_audit((int) $process['id'], $this->_uid);

			if (Arr::get($queued, 'ok'))
			{
				HTTP::redirect('/process/'.$process['code'].'/audit?ok=queued', 303);
			}

			$error = (string) Arr::get($queued, 'error', 'Очередь недоступна.');

			if ( ! Arr::get(Service_Nj670_Process::config(), 'allow_rule_fallback', TRUE))
			{
				return $error;
			}
		}

		$fast = Service_Nj670_Diff::run_fast((int) $process['id'], $this->_uid);

		if ( ! Arr::get($fast, 'ok'))
		{
			return (string) Arr::get($fast, 'error', 'Аудит не удался.');
		}

		HTTP::redirect('/process/'.$process['code'].'/audit?ok=audit', 303);
	}

	// ------------------------------------------------------------------ отчёт

	public function action_report()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Регламент процесса — ProcessHub');
		}

		$process = $this->_process($this->request->param('code'));
		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$error = $this->_report($process);
		}

		$kind = (string) $this->request->query('kind') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);
		$audit = Service_Nj670_Process::latest_audit((int) $process['id'], TRUE);
		$document = $version ? Service_Nj670_Report::document($process, $version, $audit) : NULL;

		$this->set_title('Регламент — '.(string) $process['title']);

		$reports = Service_Nj670_Process::reports((int) $process['id'], 20);

		$this->page('report', [
			'process'  => $process,
			'version'  => $version,
			'job'      => Service_Nj670_Process::first_running_job($reports),
			'kind'     => $kind,
			'audit'    => $audit,
			'document' => $document,
			'reports'  => $reports,
			'versions' => Service_Nj670_Process::versions((int) $process['id'], NULL, 12),
			'plan'     => Service_Nj670_Process::current_version($process, 'plan'),
			'fact'     => Service_Nj670_Process::current_version($process, 'fact'),
			'error'    => $error,
		]);
	}

	protected function _report(array $process)
	{
		if ( ! Security::check((string) $this->request->post('csrf')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		if ( ! $this->_start)
		{
			return 'Выпускать регламент могут привилегированные аккаунты и администраторы.';
		}

		$kind = (string) $this->request->post('kind') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);

		if ( ! $version)
		{
			return 'Схема «'.$kind.'» ещё не собрана.';
		}

		$audit = Service_Nj670_Process::latest_audit((int) $process['id'], TRUE);

		$queued = Service_Nj670_Process::worker_online()
			? Service_Nj670_Process::queue_report(
				(int) $process['id'],
				$this->_uid,
				(int) $version['id'],
				$audit ? (int) $audit['id'] : 0
			)
			: ['ok' => FALSE, 'error' => 'Воркер не в сети — собираю регламент на сайте. '.Service_Nj670_Process::worker_status()['hint']];

		if (Arr::get($queued, 'ok'))
		{
			HTTP::redirect('/process/'.$process['code'].'/report?ok=queued', 303);
		}

		// Очередь недоступна — собираем DOCX/PDF на сайте, чтобы кнопка
		// «Скачать» не врала и регламент не зависел от воркера.
		$local = Service_Nj670_Report::build_local_from(0, (int) $process['id'], (int) $version['id'], $audit ? (int) $audit['id'] : 0);

		if (Arr::get($local, 'ok'))
		{
			HTTP::redirect('/process/'.$process['code'].'/report?ok=report', 303);
		}

		return (string) Arr::get($local, 'error', Arr::get($queued, 'error', 'Не удалось выпустить регламент.'));
	}

	public function action_report_file()
	{
		if ( ! $this->_uid)
		{
			throw HTTP_Exception::factory(401);
		}

		$report = Service_Nj670_Process::report((int) $this->request->param('id'));

		if ( ! $report)
		{
			throw HTTP_Exception::factory(404, 'Отчёт не найден.');
		}

		$process = Service_Nj670_Process::process_by_id((int) $report['process_id']);

		if ( ! $process OR ! Service_Nj670_Process::can_view($process, $this->_uid, $this->_admin))
		{
			throw HTTP_Exception::factory(403);
		}

		$kind = (string) $this->request->param('kind');
		$file = Service_Nj670_Report::file((int) $report['id'], $kind);

		if ( ! $file)
		{
			throw HTTP_Exception::factory(404, 'Файл ещё не готов или удалён.');
		}

		$this->response->headers('Content-Type',
			$kind === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		$this->response->headers('Content-Disposition',
			'attachment; filename="report-'.(int) $report['id'].'.'.$kind.'"; filename*=UTF-8\'\''.rawurlencode($file['name']));
		$this->response->headers('Content-Length', (string) $file['size']);
		$this->response->body(file_get_contents($file['path']));
		$this->auto_render = FALSE;

		return $this->response;
	}

	// ------------------------------------------------------------------- версии

	public function action_versions()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Версии процесса — ProcessHub');
		}

		$process = $this->_process($this->request->param('code'));
		$versions = Service_Nj670_Process::versions((int) $process['id'], NULL, 50);

		$a = (int) $this->request->query('a');
		$b = (int) $this->request->query('b');
		$diff = NULL;
		$left = NULL;
		$right = NULL;

		if ($a > 0 AND $b > 0 AND $a !== $b)
		{
			foreach ($versions as $row)
			{
				if ((int) $row['id'] === $a)
				{
					$left = $row;
				}

				if ((int) $row['id'] === $b)
				{
					$right = $row;
				}
			}

			if ($left AND $right)
			{
				$diff = Service_Nj670_Compare::compare(
					Service_Nj670_Process::graph_of($left),
					Service_Nj670_Process::graph_of($right)
				);
			}
		}

		$this->set_title('Версии — '.$process['title']);

		$this->page('versions', [
			'process'  => $process,
			'versions' => $versions,
			'job'      => Service_Nj670_Process::first_running_job($versions),
			'left'     => $left,
			'right'    => $right,
			'diff'     => $diff,
			'statuses' => Service_Nj670_Process::statuses(),
		]);
	}

	// ---------------------------------------------------------------------- карта

	public function action_map()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Карта организации — ProcessHub');
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);

		$this->set_title('Карта организации — ProcessHub');
		$this->page('map', [
			'workspace' => $workspace,
			'overview'  => $workspace ? Service_Nj670_Map::overview((int) $workspace['id']) : [],
			'statuses'  => Service_Nj670_Process::statuses(),
		]);
	}

	// ---------------------------------------------------------------------- BPMN

	public function action_bpmn()
	{
		if ( ! $this->_uid)
		{
			throw HTTP_Exception::factory(401);
		}

		$process = Service_Nj670_Process::process((string) $this->request->param('code'));

		if ( ! $process OR ! Service_Nj670_Process::can_view($process, $this->_uid, $this->_admin))
		{
			throw HTTP_Exception::factory(404, 'Процесс не найден.');
		}

		$kind = (string) $this->request->query('kind') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);

		if ( ! $version)
		{
			throw HTTP_Exception::factory(404, 'У процесса нет версии для выгрузки.');
		}

		$graph = Service_Nj670_Process::graph_of($version);

		$this->response->headers('Content-Type', 'application/bpmn+xml; charset=utf-8');
		$this->response->headers('Content-Disposition',
			'attachment; filename="'.$process['code'].'-'.$kind.'.bpmn"');
		$this->response->body(Service_Nj670_Graph::bpmn_xml($process, $version, $graph));
		$this->auto_render = FALSE;

		return $this->response;
	}

	public function action_plantuml()
	{
		if ( ! $this->_uid)
		{
			throw HTTP_Exception::factory(401);
		}

		$process = Service_Nj670_Process::process((string) $this->request->param('code'));

		if ( ! $process OR ! Service_Nj670_Process::can_view($process, $this->_uid, $this->_admin))
		{
			throw HTTP_Exception::factory(404, 'Процесс не найден.');
		}

		$kind = (string) $this->request->query('kind') === 'fact' ? 'fact' : 'plan';
		$version = Service_Nj670_Process::current_version($process, $kind);

		if ( ! $version)
		{
			throw HTTP_Exception::factory(404, 'У процесса нет версии для выгрузки.');
		}

		$graph = Service_Nj670_Process::graph_of($version);

		$this->response->headers('Content-Type', 'text/plain; charset=utf-8');
		$this->response->headers('Content-Disposition',
			'attachment; filename="'.$process['code'].'-'.$kind.'.puml"');
		$this->response->body(Service_Nj670_Graph::plantuml($process, $graph, ['mode' => $kind]));
		$this->auto_render = FALSE;

		return $this->response;
	}

	// ------------------------------------------------------------------ онтология

	public function action_ontology()
	{
		if ( ! $this->_uid)
		{
			return $this->login_wall('Онтология — ProcessHub');
		}

		if ( ! $this->_start)
		{
			throw HTTP_Exception::factory(403);
		}

		$workspace = Service_Nj670_Process::workspace_for($this->_uid);
		$error = '';

		if ($this->request->method() === Request::POST)
		{
			if ( ! Security::check((string) $this->request->post('csrf')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			else
			{
				$action = (string) $this->request->post('action');
				$handled = FALSE;

				if ($action === 'create')
				{
					$handled = TRUE;
					$done = Service_Nj670_Ontology::create((int) $workspace['id'], (array) $this->request->post());

					if ( ! Arr::get($done, 'ok'))
					{
						$error = (string) Arr::get($done, 'error');
					}
				}
				elseif ($action === 'update')
				{
					$handled = TRUE;
					$done = Service_Nj670_Ontology::update((int) $workspace['id'],
						(int) $this->request->post('entity_id'), (array) $this->request->post());

					if ( ! Arr::get($done, 'ok'))
					{
						$error = (string) Arr::get($done, 'error');
					}
				}
				elseif ($action === 'delete')
				{
					$handled = TRUE;
					$done = Service_Nj670_Ontology::delete((int) $workspace['id'], (int) $this->request->post('entity_id'));

					if ( ! Arr::get($done, 'ok'))
					{
						$error = (string) Arr::get($done, 'error');
					}
				}
				elseif ($action === 'extract')
				{
					$handled = TRUE;
					$source = [];

					if ( ! empty($_FILES['source']['name']))
					{
						$source = Service_Nj670_Process::store_source(0, $_FILES['source']);

						if ( ! Arr::get($source, 'ok'))
						{
							$error = (string) Arr::get($source, 'error');
						}
					}

					if ($error === '')
					{
						$queued = Service_Nj670_Ontologyfeed::queue((int) $workspace['id'], $this->_uid, [
							'text'   => (string) $this->request->post('text'),
							'source' => $source,
						]);

						if (Arr::get($queued, 'ok'))
						{
							HTTP::redirect('/process/ontology?job='.(int) $queued['id'].'&ok=feed_queued', 303);
						}

						$error = (string) Arr::get($queued, 'error', 'Не удалось поставить разбор в очередь.');
					}
				}
				elseif ($action === 'accept')
				{
					$handled = TRUE;
					$done = Service_Nj670_Ontologyfeed::accept((int) $workspace['id'], $this->_uid,
						(int) $this->request->post('job_id'), (array) $this->request->post());

					if (Arr::get($done, 'ok'))
					{
						HTTP::redirect('/process/ontology?job='.(int) $this->request->post('job_id').'&ok=accepted', 303);
					}

					$error = (string) Arr::get($done, 'error', 'Не удалось принять предложение.');
				}
				else
				{
					// Пустая форма или неизвестная кнопка не должны выдавать
					// «сохранено»: иначе оператор верит зелёной плашке.
					$error = 'Неизвестное действие справочника.';
				}

				if ($handled AND $error === '')
				{
					HTTP::redirect('/process/ontology?ok=saved', 303);
				}
			}
		}

		$this->set_title('Онтология компании — ProcessHub');

		$workspace_id = (int) $workspace['id'];
		$job_id = (int) $this->request->query('job');
		$feed = $job_id > 0
			? Service_Nj670_Ontologyfeed::job($job_id, $workspace_id)
			: Service_Nj670_Ontologyfeed::latest($workspace_id);

		$this->page('ontology', [
			'workspace'     => $workspace,
			'grouped'       => Service_Nj670_Ontology::grouped($workspace_id),
			'types'         => Service_Nj670_Ontology::entity_types(),
			'feed'          => $feed,
			'feed_view'     => ($feed AND (string) $feed['status'] === Service_Nj670_Ontologyfeed::S_DONE)
				? Service_Nj670_Ontologyfeed::view($feed) : [],
			'feed_statuses' => Service_Nj670_Ontologyfeed::statuses(),
			'feed_jobs'     => Service_Nj670_Ontologyfeed::jobs($workspace_id, 5),
			'worker'        => Service_Nj670_Process::worker_status(),
			'error'         => $error,
		]);
	}

	// ------------------------------------------------------------------ админ

	public function action_admin()
	{
		if ( ! $this->_admin)
		{
			throw HTTP_Exception::factory(403);
		}

		$this->set_title('Админ-панель ProcessHub');

		$this->page('admin', [
			'overview' => Service_Nj670_Process::admin_overview(100),
			'config'   => Service_Nj670_Process::config(),
			'worker'   => Service_Nj670_Process::worker_status(),
		]);
	}
}
