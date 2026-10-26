<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-667 «Графовый 3D-конструктор» — страницы.
 *
 *   GET      /graph3d            — витрина: описание сервиса и лучшие published;
 *   GET|POST /graph3d/create     — новая сборка;
 *   GET|POST /graph3d/projects   — мои проекты;
 *   GET|POST /graph3d/catalog    — публичный каталог;
 *   GET      /graph3d/p/<code>   — рабочее место: 3D-холст, правка, история;
 *   GET      /graph3d/c/<code>   — публичная страница графа (каталог);
 *   GET|POST /graph3d/d/<code>   — чертёж с размерами (единица, масштаб, PDF);
 *   GET|POST /graph3d/settings   — модель генерации (право nj667.moderate);
 *   GET|POST /graph3d/ideas      — пресеты идей для «Создать» (nj667.moderate);
 *   GET      /graph3d/moderate   — панель модератора (право nj667.moderate);
 *   GET      /graph3d/usage      — генерации: превью, промпт, токены.
 *
 * Сборка графа и достройка идут в воркере (очередь nj120), страница только
 * поллит состояние: вызов модели — это минуты, веб-процесс столько ждать не
 * может. Холст (three.js) получает граф из jsonb и сам считает вершинный
 * буфер — картинка всегда выводимая, а источник правды — топология.
 *
 * Начальный LLM-запуск — по ACL nj667.start (login, vip или admin): каждый вызов
 * модели стоит денег. Читать каталог и чужие опубликованные графы можно всем.
 */
class Controller_Nj667_Graph3d extends Controller_Erp100_Base
{
	/** id владельца сессии (0 — гость). */
	protected $_user_id = 0;

	/** Право запуска по ACL `nj667.start` (login, vip или admin). */
	protected $_can_start = FALSE;

	/** Право модерации по ACL `nj667.moderate`; администратор получает все права. */
	protected $_can_moderate = FALSE;

	public function before()
	{
		parent::before();

		$this->_user_id      = $this->_current_user ? (int) $this->_current_user->id : 0;
		$this->_can_start    = (bool) $this->can('nj667.start');
		$this->_can_moderate = (bool) $this->can('nj667.moderate');

		$nav = [
			['title' => 'Создать', 'href' => '/graph3d/create', 'icon' => 'fas fa-wand-magic-sparkles'],
			['title' => 'Каталог', 'href' => '/graph3d/catalog', 'icon' => 'fas fa-book-open'],
		];

		if (Gate::logged_in())
		{
			$nav[] = ['title' => 'Проекты', 'href' => '/graph3d/projects', 'icon' => 'fas fa-folder-open'];
		}

		if ($this->_can_moderate)
		{
			$nav[] = ['title' => 'Модерация', 'href' => '/graph3d/moderate', 'icon' => 'fas fa-gavel'];
			$nav[] = ['title' => 'Генерации', 'href' => '/graph3d/usage', 'icon' => 'fas fa-coins'];
			$nav[] = ['title' => 'Идеи',      'href' => '/graph3d/ideas', 'icon' => 'fas fa-lightbulb'];
			$nav[] = ['title' => 'Лимиты',   'href' => '/graph3d/limits', 'icon' => 'fas fa-gauge-high'];
			$nav[] = ['title' => 'Настройки', 'href' => '/graph3d/settings', 'icon' => 'fas fa-sliders'];
		}

		$this->set_module_nav($nav);

		$this->set_title('Графовый 3D-конструктор: описание изделия — в пространственный граф');
		$this->set_description('Опишите изделие словами — модель построит пространственный граф: узлы-детали и рёбра-связи. Холст на WebGL даёт тягать детали, достраивать выделенное промптом, откатывать правки по истории и публиковать граф в каталог.');
		$this->set_keywords('3d конструктор граф пространственный узел связь three js json patch история правок каталог модерация remix');

		// Живой холст и поллинг кэшем ломаются: не кэшируем.
		$this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
		$this->response->headers('Pragma', 'no-cache');
	}

	/** GET /graph3d — витрина. Старый ?tab=catalog уводим на /graph3d/catalog. */
	public function action_index()
	{
		if ((string) $this->request->query('tab') === 'catalog')
		{
			$q = (string) $this->request->query('q');
			HTTP::redirect('/graph3d/catalog'.($q !== '' ? '?q='.rawurlencode($q) : ''), 303);
		}

		$this->template->content = View::factory('nj667/index', [
			'logged'        => Gate::logged_in(),
			'can_start'     => $this->_can_start,
			'latest_builds' => Service_Nj667_Graph3d::latest_published_builds(3),
			'showcase'      => Service_Nj667_Graph3d::showcase(9),
			'csrf'      => Security::token(),
		]);
	}

	/** GET|POST /graph3d/create — новая сборка. */
	public function action_create()
	{
		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$error = $this->_create_project((array) $this->request->post());
		}

		$this->set_title('Создать граф — Графовый 3D-конструктор');

		$this->template->content = View::factory('nj667/create', [
			'logged'       => Gate::logged_in(),
			'can_start'    => $this->_can_start,
			'can_moderate' => $this->_can_moderate,
			'creation_mode' => $this->request->method() === Request::POST
				? (string) Arr::get((array) $this->request->post(), 'creation_mode', 'simple')
				: ((string) $this->request->query('mode') !== ''
					? (string) $this->request->query('mode')
					: ($this->_can_start ? 'simple' : 'template')),
			'simple_templates' => Service_Nj667_Graph3d::simple_templates(),
			'error'        => $error,
			'rate_text'    => Service_Nj667_Graph3d::rate_text(),
			'limits'       => Service_Nj667_Graph3d::limits(),
			'quota_limits' => Service_Nj667_Graph3d::quota_limits($this->_user_id),
			'project_count' => Gate::logged_in()
				? Service_Nj667_Graph3d::active_project_count($this->_user_id) : 0,
			'daily_quota'  => Gate::logged_in()
				? Service_Nj667_Graph3d::llm_quota_status($this->_user_id) : [],
			'model'        => Service_Nj667_Graph3d::model(),
			'rag'          => $this->request->method() === Request::POST
				? Service_Nj667_Graph3d::rag_from((array) $this->request->post())
				: Service_Nj667_Graph3d::rag_default(),
			'csrf'         => Security::token(),
			'post'         => (array) $this->request->post(),
			'ideas'        => Service_Nj667_Graph3d::idea_presets_pick(3),
		]);
	}

	/** GET|POST /graph3d/projects — мои проекты. */
	public function action_projects()
	{
		$uid   = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$error = $this->_delete_project((array) $this->request->post(), $uid);
		}

		$this->set_title('Проекты — Графовый 3D-конструктор');

		$query = trim((string) $this->request->query('q'));
		$query = UTF8::substr($query, 0, 200);

		$this->template->content = View::factory('nj667/projects', [
			'logged'     => Gate::logged_in(),
			'query'      => $query,
			'mine'       => Service_Nj667_Graph3d::for_user($this->_user_id, $uid, $query !== '' ? 200 : 80, $query),
			'error'      => $error,
			'statuses'   => Service_Nj667_Graph3d::statuses(),
			'csrf'       => Security::token(),
			'rate_text'  => Service_Nj667_Graph3d::rate_text(),
			'limits'     => Service_Nj667_Graph3d::limits(),
			'quota_limits' => Service_Nj667_Graph3d::quota_limits($this->_user_id),
			'project_count' => Gate::logged_in()
				? Service_Nj667_Graph3d::active_project_count($this->_user_id) : 0,
		]);
	}

	/** GET|POST /graph3d/catalog — витрина опубликованных. Открыта всем. */
	public function action_catalog()
	{
		$error = '';
		$query = (string) $this->request->query('q');

		if ($this->request->method() === Request::POST)
		{
			$error = $this->_remix_project((array) $this->request->post());
		}

		$shown = $query !== ''
			? Service_Nj667_Graph3d::search_text($query)
			: Service_Nj667_Graph3d::catalog();

		$this->set_title('Каталог — Графовый 3D-конструктор');

		$this->template->content = View::factory('nj667/catalog', [
			'logged'     => Gate::logged_in(),
			'can_start'  => $this->_can_start,
			'catalog'    => $shown,
			'query'      => $query,
			'error'      => $error,
			'csrf'       => Security::token(),
		]);
	}

	/** POST новой сборки. Пустая строка — успех (уже редирект). Иначе ошибка. */
	protected function _create_project(array $post)
	{
		if ( ! Gate::logged_in())
		{
			return 'Конструктор доступен по аккаунту — войдите на сайт.';
		}

		if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		$mode = strtolower(trim((string) Arr::get($post, 'creation_mode', 'simple')));

		if ( ! in_array($mode, ['llm', 'template', 'simple'], TRUE))
		{
			$mode = 'simple';
		}

		// Шаблоны — локальная математика, они доступны любому вошедшему.
		// Обычная генерация и полный маршрут вызывают LLM/OpenSCAD и остаются
		// под ACL nj667.start (login/vip/admin).
		if ($mode !== 'template' AND ! $this->_can_start)
		{
			return 'Для генерации по описанию нужны роль login, vip или admin.';
		}

		if ( ! Service_Nj667_Graph3d::rate_ok())
		{
			return 'Слишком часто. Лимит: '.Service_Nj667_Graph3d::rate_text().'.';
		}

		if ($mode === 'template')
		{
			$template = strtolower(trim((string) Arr::get($post, 'template', '')));
			$catalog  = Service_Nj667_Graph3d::simple_templates();

			if ( ! isset($catalog[$template]))
			{
				return 'Выберите один из готовых каркасов.';
			}

			$title = UTF8::substr(trim((string) Arr::get($post, 'template_title', '')), 0, 200);
			$graph = Service_Nj667_Graph3d::simple_graph($template, $title);

			if ( ! is_array($graph))
			{
				return 'Не удалось собрать выбранный каркас.';
			}

			$title = (string) Arr::get((array) Arr::get($graph, 'meta', []), 'title', $catalog[$template]['title']);
			$done  = Service_Nj667_Graph3d::create($this->_user_id, [
				'title' => $title,
				'tags'  => (string) Arr::get($post, 'tags', ''),
			]);

			if ( ! Arr::get($done, 'ok'))
			{
				return (string) Arr::get($done, 'error', 'Не удалось создать проект.');
			}

			$applied = Service_Nj667_Graph3d::apply_graph((int) Arr::get($done, 'id'),
				$graph, FALSE, 'user', 'шаблон без LLM: '.$template);

			if ( ! Arr::get($applied, 'ok'))
			{
				return (string) Arr::get($applied, 'error', 'Каркас не сохранился.');
			}

			HTTP::redirect('/graph3d/p/'.Arr::get($done, 'code'), 303);
			return '';
		}

		$prompt_key = $mode === 'simple' ? 'simple_prompt' : 'prompt';
		$prompt = UTF8::substr(trim((string) Arr::get($post, $prompt_key, '')), 0,
			(int) Service_Nj667_Graph3d::limits('max_prompt', 1000));
		$files       = ($mode !== 'template' AND isset($_FILES['photos'])) ? $_FILES['photos'] : [];
		$photo_limit = $this->_can_moderate ? Service_Nj667_Graph3d::PHOTO_MAX : 1;
		$photo_count = Service_Nj667_Graph3d::uploads_count($files);

		if ($photo_count > $photo_limit)
		{
			return 'Можно прикрепить не больше '.$photo_limit.($photo_limit === 1 ? ' изображения.' : ' изображений.');
		}

		$jpegs = $mode !== 'template'
			? Service_Nj667_Graph3d::prepare_uploads($files, $photo_limit)
			: [];

		if ($prompt === '' AND ! $jpegs)
		{
			return ($mode !== 'template' AND $photo_count > 0)
				? 'Фото не принялись: нужен jpeg, png, webp или gif до 5 МБ.'
				: 'Опишите изделие словами или приложите фото.';
		}

		if ($mode !== 'template')
		{
			$quota = Service_Nj667_Graph3d::llm_quota_status($this->_user_id);

			if (empty($quota['ready']))
			{
				return (string) Arr::get($quota, 'error', 'Дневной учёт LLM-операций недоступен.');
			}

			if (empty($quota['allowed']))
			{
				return 'Дневной лимит LLM-операций исчерпан: '
					.(int) Arr::get($quota, 'used', 0).' из '.(int) Arr::get($quota, 'limit', 5)
					.'. Счётчик обновится после полуночи UTC.';
			}
		}

		$done = Service_Nj667_Graph3d::create($this->_user_id, array_merge($post, [
			'prompt' => $prompt,
			'has_photos' => (bool) $jpegs,
		]));

		if ( ! Arr::get($done, 'ok'))
		{
			return (string) Arr::get($done, 'error', 'Не удалось создать проект.');
		}

		$project_id = (int) Arr::get($done, 'id');
		$code       = (string) Arr::get($done, 'code', '');

		if ($jpegs)
		{
			Service_Nj667_Graph3d::write_photos($code, $jpegs);
		}

		if ($mode === 'simple' AND ! Service_Nj667_Graph3d::simple_flow_arm($project_id))
		{
			return 'Проект создан, но не удалось включить автоматическую сборку: /graph3d/p/'.$code;
		}

		$payload = [
			'prompt' => $prompt,
			'rag'    => Service_Nj667_Graph3d::rag_from($post),
		];

		if ($mode === 'simple')
		{
			$payload['simple_pipeline'] = TRUE;
		}

		$start = Service_Nj667_Graph3d::queue(Service_Nj667_Graph3d::CH_GENERATE,
			Service_Nj667_Graph3d::project_by_id($project_id), $payload);

		if ( ! Arr::get($start, 'ok'))
		{
			$error = (string) Arr::get($start, 'error', 'Задачу в очередь поставить не вышло.');

			if (Arr::get($start, 'quota_rejected'))
			{
				Service_Nj667_Graph3d::delete($code, $this->_user_id,
					class_exists('Visitor') ? (string) Visitor::uid() : '');

				return $error;
			}

			if ($mode === 'simple')
			{
				Service_Nj667_Graph3d::simple_flow_fail($project_id, $error);
			}

			return $error.' Проект: /graph3d/p/'.$code;
		}

		$href = '/graph3d/p/'.$code.($mode === 'simple' ? '?simple=1' : '');
		HTTP::redirect($href, 303);
		return '';
	}

	/** POST мягкого удаления. Пустая строка — успех (уже редирект). */
	protected function _delete_project(array $post, $uid)
	{
		$delete = (string) Arr::get($post, 'delete', '');

		if ($delete === '')
		{
			return '';
		}

		if ( ! Gate::logged_in())
		{
			return 'Удалять графы можно по аккаунту — войдите на сайт.';
		}

		if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		$done = Service_Nj667_Graph3d::delete($delete, $this->_user_id, $uid);

		if (Arr::get($done, 'ok'))
		{
			HTTP::redirect('/graph3d/projects', 303);
			return '';
		}

		return (string) Arr::get($done, 'error', 'Удалить не вышло.');
	}

	/** POST «как шаблон». Пустая строка — успех (уже редирект). */
	protected function _remix_project(array $post)
	{
		$remix = (string) Arr::get($post, 'remix', '');

		if ($remix === '')
		{
			return '';
		}

		if ( ! Gate::logged_in())
		{
			return 'Копировать графы можно по аккаунту — войдите на сайт.';
		}

		if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		if ( ! $this->_can_start)
		{
			return 'Копировать графы могут аккаунты с правом nj667.start '
				.'(роль login, vip или admin).';
		}

		$done = Service_Nj667_Graph3d::remix($remix, $this->_user_id,
			(int) Arr::get($post, 'r', 0));

		if (Arr::get($done, 'ok'))
		{
			HTTP::redirect('/graph3d/p/'.Arr::get($done, 'code'), 303);
			return '';
		}

		return (string) Arr::get($done, 'error', 'Скопировать не вышло.');
	}

	/** GET /graph3d/p/<code> — рабочее место: холст, правка, история. */
	public function action_project()
	{
		$code    = (string) $this->request->param('code', '');
		$project = Service_Nj667_Graph3d::project($code);

		if ($project === NULL)
		{
			throw HTTP_Exception::factory(404, 'Проект не найден или удалён.');
		}

		$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$is_owner = Service_Nj667_Graph3d::owns($project, $this->_user_id, $uid);

		// 25.10.2026 (жалоба владельца): администратор/модератор (право
		// nj667.moderate) обязан видеть чужой проект — проконтролировать, что
		// там было. Режим только чтения: правки (откат ревизий, LLM-операции,
		// сохранение) остаются за владельцем — API проверяет owns() сам.
		if ( ! $is_owner AND ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Это чужой проект.');
		}

		// Откат и мягкое удаление ревизии — обычный POST, без JS.
		if ($this->request->method() === Request::POST)
		{
			if ( ! $is_owner)
			{
				throw HTTP_Exception::factory(403, 'Чужой проект доступен только для просмотра.');
			}

			$post = (array) $this->request->post();

			if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу.');
			}

			$restore = (int) Arr::get($post, 'restore', 0);
			$forget  = (int) Arr::get($post, 'forget_revision', 0);

			if ($restore > 0)
			{
				Service_Nj667_Graph3d::restore((int) Arr::get($project, 'id'), $restore);
			}
			elseif ($forget > 0)
			{
				Service_Nj667_Graph3d::delete_revision((int) Arr::get($project, 'id'), $forget);
			}

			HTTP::redirect('/graph3d/p/'.$code, 303);
		}

		$this->set_title('Граф: '.(string) Arr::get($project, 'title'));

		$pid   = (int) Arr::get($project, 'id');
		$graph = Service_Nj667_Graph3d::graph_of($project);

		$this->template->content = View::factory('nj667/project', [
			'project'    => $project,
			'is_owner'   => $is_owner,
			'graph'      => $graph,
			'revisions'  => Service_Nj667_Graph3d::revisions($pid),
			'pending'    => Service_Nj667_Graph3d::pending_for_graph($pid, $graph),
			'published'  => Service_Nj667_Graph3d::published_revision($pid),
			'premoderate' => Service_Nj667_Graph3d::premoderate(),
			'events'     => Service_Nj667_Graph3d::events($pid),
			'can_start'  => $this->_can_start,
			'can_moderate' => $this->_can_moderate,
			'quota_limits' => Service_Nj667_Graph3d::quota_limits($this->_user_id),
			'daily_quota'  => Gate::logged_in()
				? Service_Nj667_Graph3d::llm_quota_status($this->_user_id) : [],
			'node_types' => Service_Nj667_Graph3d::node_types(),
			'edge_types' => Service_Nj667_Graph3d::edge_types(),
			'limits'     => Service_Nj667_Graph3d::limits(),
			'canvas'     => Service_Nj667_Graph3d::canvas(),
			'defaults'   => Service_Nj667_Graph3d::defaults(),
			'rate_text'  => Service_Nj667_Graph3d::rate_text(),
			'simple_flow' => Service_Nj667_Graph3d::simple_flow_state($project),
			'auto_simple_flow' => (string) $this->request->query('simple') === '1',
			'model'      => Service_Nj667_Graph3d::model(),
			'rag'        => Service_Nj667_Graph3d::rag_default(),
			'csrf'       => Security::token(),
		]);
	}

	/** GET /graph3d/c/<code> — публичная страница графа из каталога. */
	public function action_view()
	{
		$code    = (string) $this->request->param('code', '');
		$project = Service_Nj667_Graph3d::project($code);

		if ($project === NULL)
		{
			throw HTTP_Exception::factory(404, 'Граф не найден или удалён.');
		}

		$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj667_Graph3d::can_read($project, $this->_user_id, $uid))
		{
			throw HTTP_Exception::factory(403, 'Этот граф ещё не опубликован.');
		}

		$no  = (int) $this->request->query('r');
		$rev = Service_Nj667_Graph3d::published_revision((int) Arr::get($project, 'id'), $no);

		if ($rev !== NULL)
		{
			foreach (['title', 'summary', 'tags'] as $field)
			{
				$val = trim((string) Arr::get($rev, $field, ''));

				if ($val !== '')
				{
					$project[$field] = $val;
				}
			}

			$project['revision_no'] = (int) Arr::get($rev, 'no', 0);
		}

		Service_Nj667_Graph3d::bump_views((int) Arr::get($project, 'id'));

		$this->set_title((string) Arr::get($project, 'title').' — пространственный граф');
		$this->set_description(UTF8::substr((string) Arr::get($project, 'summary'), 0, 250)
			?: 'Пространственный граф изделия: узлы-детали и рёбра-связи, построенные моделью по описанию.');

		$this->template->content = View::factory('nj667/view', [
			'project'    => $project,
			'owner'      => Service_Nj667_Graph3d::owns($project, $this->_user_id, $uid),
			'pub_build'  => Service_Nj667_Graph3d::published_build_of((int) Arr::get($project, 'id')),
			'graph'      => Service_Nj667_Graph3d::public_graph($project, $no),
			'logged'     => Gate::logged_in(),
			'can_start'  => $this->_can_start,
			'can_moderate' => $this->_can_moderate,
			'node_types' => Service_Nj667_Graph3d::node_types(),
			'edge_types' => Service_Nj667_Graph3d::edge_types(),
			'csrf'       => Security::token(),
		]);
	}

	/**
	 * GET|POST /graph3d/d/<code> — чертёж с размерами.
	 *
	 * Единица и масштаб — от человека: граф в клетках, изделие в мм или м.
	 * Размеры считает сайт. ?format=svg|csv|print — файл или лист на PDF
	 * (владелец, 11.10.2026).
	 */
	public function action_drawing()
	{
		$code    = (string) $this->request->param('code', '');
		$project = Service_Nj667_Graph3d::project($code);

		if ($project === NULL)
		{
			throw HTTP_Exception::factory(404, 'Граф не найден или удалён.');
		}

		$uid   = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$owner = Service_Nj667_Graph3d::owns($project, $this->_user_id, $uid);

		if ( ! Service_Nj667_Graph3d::can_read($project, $this->_user_id, $uid))
		{
			throw HTTP_Exception::factory(403, 'Этот граф ещё не опубликован.');
		}

		$saved = Service_Nj667_Graph3d::drawing_prefs($project);
		$src   = $this->request->method() === Request::POST
			? (array) $this->request->post()
			: (array) $this->request->query();
		$opt   = Service_Nj667_Graph3d::drawing_opt([
			'unit'      => Arr::get($src, 'unit', Arr::get($saved, 'unit')),
			'unit_size' => Arr::get($src, 'unit_size', Arr::get($saved, 'unit_size')),
			'scale'     => Arr::get($src, 'scale', Arr::get($saved, 'scale')),
			'paper'     => Arr::get($src, 'paper', Arr::get($saved, 'paper')),
			'ids'       => Arr::get($src, 'ids', ''),
			'title'     => (string) Arr::get($project, 'title'),
			'code'      => $code,
		]);

		if ($this->request->method() === Request::POST)
		{
			if ( ! $owner)
			{
				throw HTTP_Exception::factory(403, 'Масштаб может запомнить только владелец.');
			}

			if ( ! Security::check((string) Arr::get($src, 'csrf', '')))
			{
				throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу.');
			}

			Service_Nj667_Graph3d::set_drawing((int) Arr::get($project, 'id'), $opt);
			HTTP::redirect('/graph3d/d/'.$code.'?'.http_build_query([
				'unit'      => $opt['unit'],
				'unit_size' => $opt['unit_size'],
				'scale'     => $opt['scale'],
				'paper'     => $opt['paper'],
			]), 303);
		}

		$graph  = $owner
			? Service_Nj667_Graph3d::graph_of($project)
			: Service_Nj667_Graph3d::public_graph($project, (int) $this->request->query('r'));
		$plan   = Service_Nj667_Graph3d::drawing_plan($graph, $opt);
		$svg    = Service_Nj667_Graph3d::drawing_svg($graph, $opt);
		$views  = Service_Nj667_Graph3d::drawing_view_svgs($graph, $opt);
		$format = strtolower(trim((string) Arr::get($this->request->query(), 'format', '')));
		$file   = preg_replace('~[^a-z0-9]+~', '-', $code).'-chertezh';

		if ($format === 'svg')
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'image/svg+xml; charset='.Kohana::$charset)
				->headers('Content-Disposition', 'attachment; filename="'.$file.'.svg"')
				->headers('X-Content-Type-Options', 'nosniff')
				->body($svg);

			return;
		}

		if ($format === 'csv')
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'text/csv; charset='.Kohana::$charset)
				->headers('Content-Disposition', 'attachment; filename="'.$file.'.csv"')
				->headers('X-Content-Type-Options', 'nosniff')
				->body(Service_Nj667_Graph3d::drawing_csv($graph, $opt));

			return;
		}

		if ($format === 'print')
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'text/html; charset='.Kohana::$charset)
				->body(View::factory('nj667/drawing_print', [
					'plan' => $plan,
					'svg'  => $svg,
					'opt'  => $opt,
				])->render());

			return;
		}

		$this->set_title('Чертёж: '.(string) Arr::get($project, 'title'));
		$this->template->content = View::factory('nj667/drawing', [
			'project' => $project,
			'graph'   => $graph,
			'plan'    => $plan,
			'svg'     => $svg,
			'views'   => $views,
			'opt'     => $opt,
			'owner'   => $owner,
			'units'   => Service_Nj667_Graph3d::drawing_units(),
			'scales'  => Service_Nj667_Graph3d::drawing_scales(),
			'csrf'    => Security::token(),
			'error'   => '',
		]);
	}

	/**
	 * GET|POST /graph3d/b/<code> — изделие: STL, спецификация, чертежи узлов.
	 *
	 * Спецификацию считает PHP на месте: это миллисекунды и ни одного денежного
	 * вызова. STL считает воркер в OpenSCAD — это минуты, поэтому POST всегда
	 * кончается redirect-ом: человек видит раскрой и узлы сразу, модель
	 * дорисовывается в просмотрщике сама.
	 *
	 *   ?format=scad|csv        — файл (что ушло воркеру / что везти в цех);
	 *   ?part=N&format=svg      — чертёж N-го уникального типа узлов;
	 *   ?file=stl|glb           — байты модели: glb читает three.js, stl —
	 *                             слайсер; у узла glb = stl;
	 *   ?build=ID               — посмотреть предыдущую сборку из истории.
	 */
	public function action_build()
	{
		$code    = (string) $this->request->param('code', '');
		$project = Service_Nj667_Graph3d::project($code);

		if ($project === NULL)
		{
			throw HTTP_Exception::factory(404, 'Граф не найден или удалён.');
		}

		$uid   = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$owner = Service_Nj667_Graph3d::owns($project, $this->_user_id, $uid);

		if ( ! Service_Nj667_Graph3d::can_read($project, $this->_user_id, $uid))
		{
			throw HTTP_Exception::factory(403, 'Этот граф ещё не опубликован.');
		}

		// file=card — карточка 128 px опубликованной сборки для витрин. Отдаём только
		// сборку этого графа и только опубликованную; геометрию не считаем.
		if (strtolower(trim((string) $this->request->query('file'))) === 'card')
		{
			$card_build = Service_Nj667_Graph3d::build_get((int) $this->request->query('build'));
			$card_path  = (is_array($card_build) AND (int) $card_build['project_id'] === (int) $project['id'])
				? Service_Nj667_Graph3d::build_card_path((int) $card_build['id'])
				: '';

			if ($card_path === '')
			{
				throw HTTP_Exception::factory(404, 'Карточки сборки нет.');
			}

			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'image/png')
				->headers('Cache-Control', 'public, max-age=3600')
				->body((string) file_get_contents($card_path));

			return;
		}

		// модератор (режим контроля) видит живой граф, как владелец; гости — публичный
		$graph = ($owner OR $this->_can_moderate)
			? Service_Nj667_Graph3d::graph_of($project)
			: Service_Nj667_Graph3d::public_graph($project, (int) $this->request->query('r'));

		$src = $this->request->method() === Request::POST
			? (array) $this->request->post()
			: (array) $this->request->query();

		// id сборки: форма несёт его в теле (кнопка должна работать и на
		// просмотре истории по ?build=), GET — в query.
		$build_id = (int) Arr::get($src, 'build', 0);
		// Сборка «queued» без живой задачи — залипший статус, а не обещание:
		// страницу отдаём по факту файлов.
		$build = $build_id > 0
			? Service_Nj667_Graph3d::build_get($build_id)
			: Service_Nj667_Graph3d::build_current((int) Arr::get($project, 'id'), $this->_user_id);

		// Сборка обязана принадлежать этому проекту: иначе ?build= подсмотрит
		// чужую модель по чужому коду. ACL проверяем до reconciliation, который
		// может обновить статус строки, чтобы URL не давал даже косвенный доступ.
		if ( ! is_array($build) OR (int) Arr::get($build, 'project_id', 0) !== (int) Arr::get($project, 'id', 0))
		{
			$build = NULL;
		}
		elseif ( ! Service_Nj667_Graph3d::can_read_build($project, $build, $this->_user_id,
			$uid, Gate::logged_in()))
		{
			throw HTTP_Exception::factory(403, 'Эта сборка ещё не опубликована. Войдите в аккаунт, чтобы открыть её.');
		}
		elseif ($build_id > 0)
		{
			$build = Service_Nj667_Graph3d::build_reconcile($build_id);
		}

		$has_build_opts = (array_key_exists('type', $src) OR array_key_exists('quality', $src)
			OR isset($src['params']) OR isset($src['flags']));
		$opt = Service_Nj667_Graph3d::build_opt($src, Service_Nj667_Graph3d::build_prefs($project));

		// GET истории и кнопки запросов наследуют параметры выбранной сборки,
		// а не последние настройки владельца проекта.
		if ($build !== NULL AND ! $has_build_opts)
		{
			$saved_opt = json_decode((string) Arr::get($build, 'params_json', ''), TRUE);

			if (is_array($saved_opt))
			{
				$opt = Service_Nj667_Graph3d::build_opt([], $saved_opt);
			}
		}

		$geom = Service_Nj667_Graph3d::build_geometry($graph, $opt);
		$slug = preg_replace('~[^a-z0-9]+~', '-', $code);

		$query  = (array) $this->request->query();
		// Тип узла нумеруется с нуля (первый уникальный — #0), поэтому «узла
		// нет» = -1, а не 0: иначе самый левый тип нельзя было бы скачать.
		$part   = (int) Arr::get($query, 'part', -1);
		$row_of = NULL;

		// file=glb — то, что читает three.js; file=stl — то, что несут в
		// слайсер. У узла всегда STL, какой бы из двух ни попросили.
		$want = strtolower(trim((string) Arr::get($query, 'file', '')));

		// Превью узла — картинка 128 px для строки деталей. Доступ тот же, что к
		// странице сборки (проверено выше); файла нет — 404, страница его не ждёт.
		if ($part >= 0 AND $want === 'preview')
		{
			$path = $build !== NULL
				? Service_Nj667_Graph3d::build_part_preview_path((int) $build['id'], $part)
				: '';

			if ($path === '')
			{
				throw HTTP_Exception::factory(404, 'Превью узла нет.');
			}

			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'image/png')
				->headers('Cache-Control', 'private, max-age=60')
				->body((string) file_get_contents($path));

			return;
		}

		if ($want === 'stl' AND $part < 0 AND ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'STL всего изделия доступен только модераторам (право nj667.moderate).');
		}

		if ($want === 'stl' OR $want === 'glb')
		{
			if ($part >= 0)
			{
				// Деталь к печати — тот же контроль пути и тот же поток, но
				// файл и имя у узла свои.
				$row_of = Service_Nj667_Graph3d::build_part_get((int) Arr::get($build, 'id', 0), $part);

				return $this->_build_stream(is_array($row_of) ? $row_of : NULL, 'stl',
					'nj667-'.(int) Arr::get($build, 'id', 0).'-part-'.$part.'.stl');
			}

			return $this->_build_stream($build, $want);
		}

		$format = strtolower(trim((string) Arr::get($this->request->query(), 'format', '')));

		if ($format === 'scad')
		{
			// .scad — исходник изделия: ссылки прячем, и выдачу тоже только админу.
			if ( ! $this->_can_moderate)
			{
				throw HTTP_Exception::factory(403, 'Файл .scad доступен только администратору.');
			}

			if ($part >= 0)
			{
				$text = $build !== NULL
					? (string) Arr::get(Service_Nj667_Graph3d::build_part_scad_text((int) $build['id'], $part), 'scad', '')
					: '';

				return $this->_build_text($text !== '' ? $text
						: Service_Nj667_Build::scad_for_part($geom, $opt, $part, $code),
					$slug.'-uzel-'.$part.'.scad', 'text/plain; charset='.Kohana::$charset);
			}

			$text = $build !== NULL
				? (string) Arr::get(Service_Nj667_Graph3d::build_scad_text((int) $build['id']), 'scad', '')
				: '';

			// Файл с диска — ровно то, что видел OpenSCAD; если его нет, отдаём
			// сгенерированный сейчас.
			return $this->_build_text($text !== '' ? $text : Service_Nj667_Graph3d::build_scad($geom, $opt, $code),
				$slug.'-model.scad', 'text/plain; charset='.Kohana::$charset);
		}

		if ($format === 'csv')
		{
			return $this->_build_text(Service_Nj667_Graph3d::build_spec_csv($geom, $opt),
				$slug.'-specifikaciya.csv', 'text/csv; charset='.Kohana::$charset);
		}

		// ZIP всей сборки: детали на печать (STL), GLB и CSV спецификации. Видимость как у файлов.
		if ($format === 'zip')
		{
			if ($build === NULL)
			{
				throw HTTP_Exception::factory(404, 'Сборки ещё нет — соберите изделие, тогда появится архив.');
			}

			$made = Service_Nj667_Graph3d::build_zip($build, Service_Nj667_Graph3d::build_spec_csv($geom, $opt));

			if ( ! Arr::get($made, 'ok'))
			{
				throw HTTP_Exception::factory(503, (string) Arr::get($made, 'error', 'Архив не собрать.'));
			}

			return $this->_build_file((string) $made['path'], $slug.'-build-'.(int) $build['id'].'.zip', 'application/zip', TRUE);
		}

		if ($format === 'svg' AND $this->request->query('part') !== NULL)
		{
			$part = (int) $this->request->query('part');
			$svg  = Service_Nj667_Graph3d::build_part_svg($geom, $opt, $part);

			if ($svg === '')
			{
				throw HTTP_Exception::factory(404, 'Такого типа узлов нет.');
			}

			return $this->_build_text($svg, $slug.'-uzel-'.$part.'.svg', 'image/svg+xml; charset='.Kohana::$charset);
		}

		// Публикация сборки — только админ, владелец тут не нужен.
		if ($this->request->method() === Request::POST
			AND (array_key_exists('publish_build', $src) OR array_key_exists('unpublish_build', $src)))
		{
			return $this->_build_publish($src, $project, $code);
		}

		$error = '';
		$is_file_request = ($this->request->method() === Request::POST
			AND (array_key_exists('request_glb', $src) OR array_key_exists('render_stl', $src)));

		if ($is_file_request)
		{
			$file_kind = array_key_exists('render_stl', $src) ? 'stl' : 'glb';

			if ($file_kind === 'glb' AND ! Gate::logged_in())
			{
				throw HTTP_Exception::factory(401, 'Для запроса GLB войдите в аккаунт.');
			}

			if ($file_kind === 'stl' AND ! $this->_can_moderate)
			{
				throw HTTP_Exception::factory(403, 'STL всего изделия доступен только модераторам (право nj667.moderate).');
			}

			if ( ! Security::check((string) Arr::get($src, 'csrf', '')))
			{
				throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу.');
			}

			$source_build_id = (int) Arr::get($build, 'id', 0);

			if ($file_kind === 'stl' AND $source_build_id <= 0)
			{
				throw HTTP_Exception::factory(404, 'Для STL-запроса нужна существующая сборка.');
			}

			// Повторная отправка формы не плодит задачи, если нужный формат уже есть.
			$ready_kind = $source_build_id > 0
				? Service_Nj667_Graph3d::build_file_path($build, $file_kind) !== '' : FALSE;

			if ($ready_kind)
			{
				HTTP::redirect('/graph3d/b/'.rawurlencode($code).'?'.http_build_query([
					'build' => $source_build_id,
					'file'  => $file_kind,
				]), 303);

				return;
			}

			if ( ! Service_Nj667_Graph3d::rate_ok())
			{
				$error = 'Слишком часто. Лимит: '.Service_Nj667_Graph3d::rate_text().'.';
			}
			elseif ($file_kind === 'glb' AND ! Service_Nj667_Graph3d::build_glb_ready())
			{
				$error = 'Генерация GLB недоступна: сначала примените миграцию NJ-667/010.';
			}
			else
			{
				$request_opt = $opt;
				$made = Service_Nj667_Graph3d::build_create((int) Arr::get($project, 'id'),
					(int) $this->_user_id, $graph, $request_opt,
					'запрос '.strtoupper($file_kind).' по сборке #'.$source_build_id);

				if ( ! Arr::get($made, 'ok'))
				{
					$error = (string) Arr::get($made, 'error', 'Не удалось создать вашу сборку.');
				}
				else
				{
					$new_build_id = (int) Arr::get($made, 'build_id', 0);
					$queue_opt = $request_opt;
					$queue_opt['kind'] = $file_kind;
					$queued = Service_Nj667_Graph3d::build_queue($new_build_id, $project, $queue_opt);

					if ( ! Arr::get($queued, 'ok'))
					{
						$error = (string) Arr::get($queued, 'error', 'Файл не встал в очередь.');
						Service_Nj667_Graph3d::build_mark($new_build_id, 'error', $error);
						$build = Service_Nj667_Graph3d::build_get($new_build_id);
						$build_id = $new_build_id;
						$opt = $request_opt;
						$geom = Service_Nj667_Graph3d::build_geometry($graph, $opt);
					}
					else
					{
						$new_job_id = (int) Arr::get($queued, 'job_id', 0);
						$logged = Service_Nj667_Graph3d::log_build_file_request(
							(int) $this->_user_id, (int) Arr::get($project, 'id'),
							$source_build_id, $new_build_id, $file_kind, $new_job_id);
					$query = ['build' => $new_build_id, 'job' => $new_job_id];

						if (empty($logged['ok']))
						{
						$query['request_error'] = (string) Arr::get($logged, 'error', 'Запрос не записался в журнал.');
						}

						HTTP::redirect('/graph3d/b/'.rawurlencode($code).'?'.http_build_query($query), 303);

						return;
					}
				}
			}
		}

		if ($this->request->method() === Request::POST AND ! $is_file_request)
		{
			$parts_ask = ! empty($src['parts']);
			$can_prepare_selected_parts = (is_array($build)
				AND Service_Nj667_Graph3d::can_prepare_build_parts($project, $build,
					(int) $this->_user_id, $uid, Gate::logged_in()));

			if ( ! $owner AND ! $parts_ask)
			{
				throw HTTP_Exception::factory(403, 'Собирать изделие может только владелец.');
			}

			if ( ! $owner AND ! $can_prepare_selected_parts)
			{
				throw HTTP_Exception::factory(403, 'Узлы можно готовить только в своей пользовательской сборке.');
			}

			if ( ! Security::check((string) Arr::get($src, 'csrf', '')))
			{
				throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу.');
			}

			// Параметры проекта — настройки владельца. Автор чужой публичной
			// сборки готовит детали по снимку своего build.params_json и не меняет их.
			if ($owner)
			{
				Service_Nj667_Graph3d::set_build_prefs((int) Arr::get($project, 'id'), $opt);
			}

			// «Узлы к печати» — про ЭТУ сборку, а не про новую: пересчитывать
			// изделие ради пачки деталей значило бы обнулить уже готовые STL.
			// id сборки — из её строки: форма несёт build в теле, а на GET
			// просмотре истории он в query. По одному только query ветка пачки
			// молчала, создавалась пустая сборка, и просмотрщик ловил 404.
			$parts_note = '';
			$parts_n    = 0;
			$here       = (int) Arr::get($build, 'id', 0);

			if ($parts_ask AND $here > 0)
			{
				// Посадки берём из самой сборки, а не из формы: иначе рядом со
				// старой ведомостью висел бы STL, собранный по новым числам, и
				// расхождение «30 шт. или 26?» было бы не найти.
				$opt_parts = Service_Nj667_Graph3d::build_opt([],
					(array) json_decode((string) Arr::get($build, 'params_json', ''), TRUE));
				$opt_parts['request_user_id'] = (int) $this->_user_id;
				// На $here, а не на $build_id из формы: форма может не нести build,
				// тогда пачка молча уходила бы не туда (и страница перезагружалась).
				$parts_job = Service_Nj667_Graph3d::build_parts_render($here, $project, $graph, $opt_parts);

				// Кнопка «узлы» из страницы: отвечаем JSON и остаёмся на месте.
				// Переход 303 перезагружал страницу целиком; ход задачи страница
				// теперь сама опрашивает по job_id.
				if ($this->request->is_ajax())
				{
					$this->_parts_reply(Arr::get($parts_job, 'ok'), Arr::get($parts_job, 'job_id', 0),
						Arr::get($parts_job, 'parts', 0), Arr::get($parts_job, 'error', ''), $here, FALSE);

					return;
				}

				if ( ! Arr::get($parts_job, 'ok'))
				{
					// Сборка сейчас пересчитается, и пачку мы поставим уже на
					// неё — второй раз жать не нужно. Молчать об этом
					// нельзя: без слова человек увидел бы «узлов: 0».
					$parts_note = (string) Arr::get($parts_job, 'error', 'Узлы не встали в очередь.');
				}
				else
				{
					HTTP::redirect('/graph3d/b/'.$code.'?'.http_build_query(array_filter([
						'build' => $here,
						'job'   => (int) Arr::get($parts_job, 'job_id', 0) ?: NULL,
						'parts' => (int) Arr::get($parts_job, 'parts', 0) ?: NULL,
					])), 303);

					return;
				}
			}

			$made = Service_Nj667_Graph3d::build_create((int) Arr::get($project, 'id'),
				(int) $this->_user_id, $graph, $opt, 'страница сборки');

			if ( ! Arr::get($made, 'ok'))
			{
				$error = (string) Arr::get($made, 'error', 'Сборка не удалась.');
			}
			else
			{
				$build_id = (int) Arr::get($made, 'build_id', 0);
				$job_id   = 0;

				// Узлы просили, а пачка не встала (сборки не было либо она
				// устарела) — ставим её сразу на свежую строку, иначе кнопка
				// выглядела бы не нажатой. Успешный путь выше уже ушёл в 303.
				if ($parts_ask)
				{
					$parts_opt = $opt;
					$parts_opt['request_user_id'] = (int) $this->_user_id;
					$again = Service_Nj667_Graph3d::build_parts_render($build_id, $project, $graph, $parts_opt);

					if (Arr::get($again, 'ok'))
					{
						$parts_n    = (int) Arr::get($again, 'parts', 0);
						$parts_note = '';
					}
					else
					{
						$parts_note = (string) Arr::get($again, 'error', 'Узлы не встали в очередь.');
					}
				}

				// Пачка на свежей сборке тянет за собой и рендер изделия: без
				// модели блок просмотра остался бы пустым, а человек — с
				// ощущением, что кнопка ничего не сделала.
				if ( ! empty($src['render']) OR $parts_ask)
				{
					$queue_opt = $opt;
					$queue_opt['kind'] = 'glb';
					$queued = Service_Nj667_Graph3d::build_queue($build_id, $project, $queue_opt);

					if ( ! Arr::get($queued, 'ok'))
					{
						// Спецификация уже посчитана и лежит в сборке — рендер
						// просто не встал. Говорим об этом на месте, без redirect.
						$error = (string) Arr::get($queued, 'error', 'Рендер не встал в очередь.');
					}
					else
					{
						$job_id = (int) Arr::get($queued, 'job_id', 0);
					}
				}

				if ($parts_note !== '')
				{
					$error = $parts_note;
				}

				// AJAX с той же кнопкой: раньше этот путь всегда отвечал страницей
				// (POST → 200), и кнопка перезагружала её целиком.
				if ($parts_ask AND $this->request->is_ajax())
				{
					// Сборки при загрузке страницы могло не быть: страница узнаёт её id отсюда
					// и сама опрашивает. $job_id здесь — рендер, поставленный вместе с ней.
					$this->_parts_reply($error === '', $job_id, $parts_n, $error, $build_id, $job_id > 0);

					return;
				}

				if ($error === '')
				{
					HTTP::redirect('/graph3d/b/'.$code.'?'.http_build_query(array_filter([
						'build' => $build_id,
						'job'   => $job_id > 0 ? $job_id : NULL,
						'parts' => $parts_n > 0 ? $parts_n : NULL,
					])), 303);

					return;
				}

				$build = Service_Nj667_Graph3d::build_reconcile($build_id);
			}
		}

		$parts_user_id = (int) Arr::get((array) $build, 'user_id', 0);

		if ($parts_user_id <= 0)
		{
			$parts_user_id = (int) Arr::get($project, 'user_id', 0);
		}

		// AND/OR слабее присваивания: скобки охватывают всё право, а не только сборку.
		$can_prepare_parts = ($owner OR (is_array($build)
			AND Service_Nj667_Graph3d::can_prepare_build_parts($project, $build,
				(int) $this->_user_id, $uid, Gate::logged_in())));

		$this->set_title('Сборка: '.(string) Arr::get($project, 'title'));
		$this->template->content = View::factory('nj667/build', [
			'project'   => $project,
			'graph'     => $graph,
			'geom'      => $geom,
			'opt'       => $opt,
			'build'     => $build,
			'simple_flow' => Service_Nj667_Graph3d::simple_flow_state($project),
			'history'   => Service_Nj667_Graph3d::build_history((int) Arr::get($project, 'id')),
			'stale'     => Service_Nj667_Graph3d::build_stale((array) $build, $graph),
			'types'     => Service_Nj667_Graph3d::build_types(),
			'qualities' => Service_Nj667_Graph3d::build_qualities(),
			'lib'       => Service_Nj667_Graph3d::build_lib_missing($opt['type']),
			'engine'    => Service_Nj667_Graph3d::build_engine_hint(),
			'schema'    => Service_Nj667_Graph3d::build_schema_state(),
			'part_rows' => $build !== NULL ? Service_Nj667_Graph3d::build_parts((int) $build['id']) : [],
			// План пачки — ЭТОЙ сборки: без build_id кнопка не знала, сколько
			// типов уже на диске, и обещала «осталось N» там, где делать нечего.
			'plan'      => Service_Nj667_Graph3d::build_parts_plan($geom, $opt,
				$build !== NULL ? (int) $build['id'] : 0, $parts_user_id),
			'parts_run_limit' => Service_Nj667_Graph3d::build_parts_run_limit($parts_user_id),
			'owner'     => $owner,
			'can_prepare_parts' => $can_prepare_parts,
			'logged'    => Gate::logged_in(),
			'can_moderate' => $this->_can_moderate,
			'glb_ready' => ($build !== NULL
				AND Service_Nj667_Graph3d::build_file_path($build, 'glb') !== ''),
			'stl_ready' => ($this->_can_moderate AND $build !== NULL
				AND Service_Nj667_Graph3d::build_file_path($build, 'stl') !== ''),
			'pub'       => (string) $this->request->query('pub'),
			'pub_error' => (string) $this->request->query('pub_error'),
			'request_error' => (string) $this->request->query('request_error'),
			// Какие узлы уже с превью на диске: строку рисуем картинкой сразу.
			'preview_of' => $build !== NULL ? Service_Nj667_Graph3d::build_parts_previews((int) $build['id']) : [],
			'csrf'      => Security::token(),
			'error'     => $error,
		]);
	}

	/** Ответ файлом: текст (scad/csv/svg) — он маленький, кладём в тело. */
	/**
	 * Файл с диска потоком; $remove — удалить временный файл после отправки (архив).
	 */
	protected function _build_file($path, $name, $mime, $remove = FALSE)
	{
		$this->auto_render = FALSE;

		if (ob_get_level())
		{
			ob_end_clean();
		}

		$this->response->headers('Content-Type', $mime)
			->headers('Content-Length', (string) filesize($path))
			->headers('Content-Disposition', 'attachment; filename="'.$name.'"')
			->headers('X-Content-Type-Options', 'nosniff')
			->headers('Cache-Control', 'private, max-age=0, must-revalidate')
			->send_headers();

		readfile($path);

		if ($remove)
		{
			@unlink($path);
		}
	}

	/**
	 * POST /graph3d/b/<code>: опубликовать или снять сборку (только админ).
	 * Итог — редирект на страницу сборки с pub=on|off либо pub_error.
	 */
	protected function _build_publish(array $src, array $project, $code)
	{
		if ( ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Публиковать сборку может только модератор (право nj667.moderate).');
		}

		if ( ! Security::check((string) Arr::get($src, 'csrf', '')))
		{
			throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу.');
		}

		$build_id  = (int) Arr::get($src, 'build', 0);
		$unpublish = array_key_exists('unpublish_build', $src);
		$uid       = (int) $this->_user_id;
		$done      = $unpublish
			? Service_Nj667_Graph3d::build_unpublish($build_id, (int) $project['id'], $uid)
			: Service_Nj667_Graph3d::build_publish($build_id, $uid, (int) $project['id']);

		$query = ['build' => $build_id];

		if (Arr::get($done, 'ok'))
		{
			$query['pub'] = $unpublish ? 'off' : 'on';
		}
		else
		{
			$query['pub_error'] = (string) Arr::get($done, 'error', 'Не вышло.');
		}

		HTTP::redirect('/graph3d/b/'.rawurlencode((string) $code).'?'.http_build_query($query), 303);

		return NULL;
	}

	protected function _build_text($body, $name, $mime)
	{
		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', $mime)
			->headers('Content-Disposition', 'attachment; filename="'.$name.'"')
			->headers('X-Content-Type-Options', 'nosniff')
			->headers('Cache-Control', 'private, max-age=0, must-revalidate')
			->body((string) $body);
	}

	/**
	 * STL/GLB — потоком. Файл весит до десятков мегабайт, в буфер ответа его
	 * не тащим (урок erp212: «upstream sent too big file» на fastcgi).
	 */
	protected function _build_stream(array $build = NULL, $kind = 'stl', $name = '')
	{
		$path = Service_Nj667_Graph3d::build_file_path($build, $kind);

		if ($path === '')
		{
			throw HTTP_Exception::factory(404, 'Модели на диске нет — пересоберите изделие.');
		}

		$this->auto_render = FALSE;

		if (ob_get_level())
		{
			ob_end_clean();
		}

		$this->response->headers('Content-Type', $kind === 'glb' ? 'model/gltf-binary' : 'model/stl')
			->headers('Content-Length', (string) filesize($path))
			->headers('Content-Disposition', 'inline; filename="'.($name !== '' ? $name
				: 'nj667-'.(int) Arr::get($build, 'id', 0).'.'.$kind).'"')
			->headers('X-Content-Type-Options', 'nosniff')
			->headers('X-Accel-Buffering', 'no')
			->headers('Cache-Control', 'private, max-age=3600')
			->send_headers();

		readfile($path);
	}

	/** GET|POST /graph3d/limits — индивидуальные лимиты модуля (ACL nj667.moderate). */
	public function action_limits()
	{
		if ( ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Лимиты доступны только модераторам (право nj667.moderate).');
		}

		$error = '';
		$query = UTF8::substr(trim((string) ($this->request->method() === Request::POST
			? Arr::get((array) $this->request->post(), 'q', '')
			: $this->request->query('q'))), 0, 120);

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			else
			{
				$done = Service_Nj667_Graph3d::quota_override_set(
					(int) Arr::get($post, 'user_id', 0),
					[
						'active_projects' => Arr::get($post, 'active_projects', NULL),
						'llm_operations_per_day' => Arr::get($post, 'llm_operations_per_day', NULL),
					],
					(int) $this->_user_id);

				if (Arr::get($done, 'ok'))
				{
					// Возвращаемся в тот же срез списка: страница, поиск,
					// порядок и размер страницы — как их видел модератор.
					HTTP::redirect('/graph3d/limits?'.http_build_query(array_filter([
						'q'    => $query !== '' ? $query : NULL,
						'page' => max(1, (int) Arr::get($post, 'page', 1)),
						'sort' => (string) Arr::get($post, 'sort', '') !== '' ? (string) $post['sort'] : NULL,
						'per'  => (int) Arr::get($post, 'per', 0) > 0 ? (int) $post['per'] : NULL,
						'saved' => 1,
					])), 303);

					return;
				}

				$error = (string) Arr::get($done, 'error', 'Не удалось сохранить лимиты.');
			}
		}

		$page  = max(1, (int) $this->request->query('page'));
		$per   = (int) $this->request->query('per');
		$limit = in_array($per, [30, 50, 100, 200], TRUE) ? $per : 30;
		$sorts = Service_Nj667_Graph3d::quota_sorts();
		$sort  = (string) $this->request->query('sort');
		$sort  = isset($sorts[$sort]) ? $sort : 'new';
		$data  = Service_Nj667_Graph3d::quota_override_users($query, $limit, ($page - 1) * $limit, $sort);
		$users = (array) Arr::get($data, 'rows', []);

		// Расход по всей странице считаем двумя запросами, а не двумя на
		// строку: страница рассчитана на сотни людей (владелец, 16.10.2026).
		$ids = [];

		foreach ($users as $user)
		{
			$ids[] = (int) Arr::get((array) $user, 'id', 0);
		}

		$counts = Service_Nj667_Graph3d::active_project_counts($ids);
		$usage  = Service_Nj667_Graph3d::llm_usage_counts($ids);

		foreach ($users as &$user)
		{
			$user = (array) $user;
			$id   = (int) Arr::get($user, 'id', 0);
			$overrides = [];

			foreach (['active_projects', 'llm_operations_per_day'] as $key)
			{
				if (Arr::get($user, $key) !== NULL)
				{
					$overrides[$key] = (int) $user[$key];
				}
			}

			$user['effective_limits'] = Service_Nj667_Graph3d::quota_limits_resolve($id, $overrides);

			// Массовое чтение не удалось — падаем на поштучное, но не показываем
			// выдуманные нули.
			$user['active_count'] = $counts === FALSE
				? Service_Nj667_Graph3d::active_project_count($id)
				: (int) Arr::get((array) $counts, (string) $id, Arr::get((array) $counts, $id, 0));

			if ($usage === FALSE)
			{
				$user['daily_usage'] = Service_Nj667_Graph3d::llm_quota_status($id);
			}
			else
			{
				$by_used = (array) $usage;
				$used    = (int) Arr::get($by_used, (string) $id, Arr::get($by_used, $id, 0));
				$user['daily_usage'] = Service_Nj667_Graph3d::llm_quota_state(
					$used, (int) Arr::get($user['effective_limits'], 'llm_operations_per_day', 5));
			}
		}
		unset($user);

		$this->set_title('Лимиты пользователей — Графовый 3D-конструктор');
		$this->template->content = View::factory('nj667/limits', [
			'users' => $users,
			'query' => $query,
			'page' => $page,
			'limit' => $limit,
			'sorts' => $sorts,
			'sort' => $sort,
			'total' => (int) Arr::get($data, 'total', 0),
			'error' => $error !== '' ? $error : (string) Arr::get($data, 'error', ''),
			'saved' => (string) $this->request->query('saved') === '1',
			'csrf' => Security::token(),
		]);
	}

	/**
	 * GET|POST /graph3d/settings — модель, кадр, RAG и хранилище (ACL nj667.moderate).
	 *
	 * Список моделей — config('models'); выбор пишется в nj_667_settings
	 * и уезжает в задачу воркера полем model. Пусто — модель воркера.
	 * photo_max_px — до скольки px уменьшать кадр для vision (дефолт 512).
	 * rag — дефолт чекбокса на странице сборки.
	 */
	public function action_settings()
	{
		if ( ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Настройки конструктора доступны модераторам.');
		}

		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			// Очистка файлов — отдельная форма: без неё сохранение модели стёрло бы настройки.
			if (array_key_exists('storage_purge', $post))
			{
				$error = $this->_storage_purge($post);
			}
			elseif (array_key_exists('storage_clean', $post))
			{
				$error = $this->_storage_clean($post);
			}
			elseif (array_key_exists('storage_delete', $post))
			{
				$error = $this->_storage_delete($post);
			}
			elseif ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			else
			{
				$spec   = UTF8::substr(trim((string) Arr::get($post, 'model', '')), 0, 160);
				$custom = UTF8::substr(trim((string) Arr::get($post, 'model_custom', '')), 0, 160);

				if ($custom !== '')
				{
					$spec = $custom;
				}

				$done = Service_Nj667_Graph3d::set_generation_model($spec);

				if ( ! Arr::get($done, 'ok'))
				{
					$error = (string) Arr::get($done, 'error', 'Не удалось сохранить модель.');
				}
				else
				{
					$px = (int) Arr::get($post, 'photo_max_px', 512);
					$px = max(128, min(2048, $px > 0 ? $px : 512));
					$pic = Service_Nj667_Graph3d::set_setting('photo_max_px', (string) $px);

					if ( ! Arr::get($pic, 'ok'))
					{
						$error = (string) Arr::get($pic, 'error', 'Не удалось сохранить размер кадра.');
					}
					else
					{
						$rag = Service_Nj667_Graph3d::rag_from($post);
						$rag_done = Service_Nj667_Graph3d::set_setting('rag', $rag ? '1' : '0');

						if ( ! Arr::get($rag_done, 'ok'))
						{
							$error = (string) Arr::get($rag_done, 'error', 'Не удалось сохранить RAG.');
						}
					}
				}

				if ($error === '')
				{
					// Флаг в адресе: человек должен видеть, что настройки легли,
					// а не гадать, применилось ли (владелец, 16.10.2026).
					HTTP::redirect('/graph3d/settings?saved=1', 303);
				}
			}
		}

		$this->set_title('Настройки — Графовый 3D-конструктор');

		$sort = $this->request->query('sort');
		$sort = is_string($sort) ? $sort : 'size_desc';
		$type = $this->request->query('type');
		$type = is_string($type) ? $type : 'all';
		$group_by = $this->request->query('group_by');
		$group_by = is_string($group_by) ? $group_by : 'build';

		$this->template->content = View::factory('nj667/settings', [
			'models'        => Service_Nj667_Graph3d::models(),
			'model'         => Service_Nj667_Graph3d::model(),
			'photo_max_px'  => Service_Nj667_Graph3d::photo_max_px(),
			'rag'           => Service_Nj667_Graph3d::rag_default(),
			'storage'       => Service_Nj667_Storage::report(
				$sort,
				$this->request->query('published') === '1',
				$type,
				$group_by
			),
			'cleaned'       => $this->_storage_cleaned_flash(),
			'deleted'       => $this->_storage_deleted_flash(),
			'purged'        => $this->_storage_purged_flash(),
			'error'         => $error,
			'saved'         => (string) $this->request->query('saved') === '1',
			'csrf'          => Security::token(),
		]);
	}

	/**
	 * Одна порция очистки файлов (владелец: отчёт и очистка на /graph3d/settings).
	 * Успех — редирект с итогами в query; возвращает текст ошибки.
	 */
	protected function _storage_clean(array $post)
	{
		if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		$cat = (string) Arr::get($post, 'storage_clean', '');
		$r   = Service_Nj667_Storage::clean($cat);

		if ( ! Arr::get($r, 'ok'))
		{
			return (string) Arr::get($r, 'error', 'Очистка не выполнена.');
		}

		HTTP::redirect('/graph3d/settings?'.http_build_query([
			'cleaned' => $cat,
			'removed' => (int) $r['removed'],
			'bytes'   => (int) $r['bytes'],
			'left'    => (int) $r['left'],
			'failed'  => (int) $r['failed'],
		]), 303);

		return '';
	}

	/** Полная очистка с allowlist опубликованных GLB/превью и файлов деталей. */
	protected function _storage_purge(array $post)
	{
		if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		$r = Service_Nj667_Storage::purge_unpublished();

		if ( ! Arr::get($r, 'ok'))
		{
			return (string) Arr::get($r, 'error', 'Полная очистка не выполнена.');
		}

		HTTP::redirect('/graph3d/settings?'.http_build_query([
			'purged'      => 1,
			'removed'     => (int) $r['removed'],
			'bytes'       => (int) $r['bytes'],
			'kept'        => (int) $r['kept'],
			'kept_bytes'  => (int) $r['kept_bytes'],
			'failed'      => (int) $r['failed'],
			'missing'     => (int) $r['missing'],
			'left'        => (int) $r['left'],
		]).'#nj667-storage', 303);

		return '';
	}

	/**
	 * Ручное удаление файлов, отмеченных в списке (владелец: сортировка по размеру и дате).
	 * Успех — редирект на список с итогами; возвращает текст ошибки.
	 */
	protected function _storage_delete(array $post)
	{
		if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		$files = Arr::get($post, 'files', []);
		$files = is_array($files) ? $files : [];

		// Опубликованные сборки уходят только при показанном фильтре (передаёт форма).
		$show_published = (string) Arr::get($post, 'published', '') === '1';
		$r              = Service_Nj667_Storage::delete_files($files, $show_published);

		if ( ! Arr::get($r, 'ok'))
		{
			return (string) Arr::get($r, 'error', 'Удаление не выполнено.');
		}

		$sort = (string) Arr::get($post, 'sort', '');

		HTTP::redirect('/graph3d/settings?'.http_build_query([
			'sort'      => $sort,
			'type'      => (string) Arr::get($post, 'type', 'all'),
			'group_by'  => (string) Arr::get($post, 'group_by', 'build'),
			'published' => $show_published ? '1' : '',
			'deleted'   => (int) $r['removed'],
			'bytes'     => (int) $r['bytes'],
			'failed'    => (int) $r['failed'],
			'missing'   => (int) $r['missing'],
			'protected' => (int) $r['protected'],
		]).'#nj667-files', 303);

		return '';
	}

	/** Итоги ручного удаления из query; NULL, если удаления не было. */
	protected function _storage_deleted_flash()
	{
		if ($this->request->query('deleted') === NULL)
		{
			return NULL;
		}

		return [
			'removed' => (int) $this->request->query('deleted'),
			'bytes'   => (int) $this->request->query('bytes'),
			'failed'    => (int) $this->request->query('failed'),
			'missing'   => (int) $this->request->query('missing'),
			'protected' => (int) $this->request->query('protected'),
		];
	}

	/** Итоги полной очистки из query (после редиректа). */
	protected function _storage_purged_flash()
	{
		if ((string) $this->request->query('purged') !== '1')
		{
			return NULL;
		}

		return [
			'removed'    => (int) $this->request->query('removed'),
			'bytes'      => (int) $this->request->query('bytes'),
			'kept'       => (int) $this->request->query('kept'),
			'kept_bytes' => (int) $this->request->query('kept_bytes'),
			'failed'     => (int) $this->request->query('failed'),
			'missing'    => (int) $this->request->query('missing'),
			'left'       => (int) $this->request->query('left'),
		];
	}

	/** Итоги последней порции из query (после редиректа); NULL, если порции не было. */
	protected function _storage_cleaned_flash()
	{
		$cat = (string) $this->request->query('cleaned');

		if ( ! in_array($cat, Service_Nj667_Storage::CLEANABLE, TRUE))
		{
			return NULL;
		}

		return [
			'category' => $cat,
			'removed'  => (int) $this->request->query('removed'),
			'bytes'    => (int) $this->request->query('bytes'),
			'left'     => (int) $this->request->query('left'),
			'failed'   => (int) $this->request->query('failed'),
		];
	}

	/**
	 * GET|POST /graph3d/ideas — копилка идей для страницы «Создать».
	 *
	 * Тема → воркер nj667.ideas → 30 пресетов. Удаление иконкой, вторым
	 * нажатием. Только админ (nj667.moderate). Владелец, 11.10.2026.
	 */
	public function action_ideas()
	{
		if ( ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Идеи для сборки настраивают администраторы.');
		}

		$error = '';
		$theme = UTF8::substr(trim((string) $this->request->post('theme')), 0, 200);

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			elseif ((int) Arr::get($post, 'delete', 0) > 0)
			{
				if ( ! Service_Nj667_Graph3d::idea_preset_delete((int) Arr::get($post, 'delete')))
				{
					$error = 'Не удалось удалить идею.';
				}
				else
				{
					HTTP::redirect('/graph3d/ideas', 303);
				}
			}
			elseif ((string) Arr::get($post, 'generate', '') !== '')
			{
				$done = Service_Nj667_Graph3d::queue_ideas($theme, $this->_user_id);

				if ( ! Arr::get($done, 'ok'))
				{
					$error = (string) Arr::get($done, 'error', 'Не удалось поставить генерацию.');
				}
				else
				{
					HTTP::redirect('/graph3d/ideas', 303);
				}
			}
		}

		$job_id  = (int) Service_Nj667_Graph3d::setting('ideas_job', '0');
		$job     = $job_id > 0 ? Service_Nj667_Graph3d::job_state($job_id) : [];
		$st      = strtolower((string) Arr::get($job, 'status', Arr::get($job, 'state', '')));
		$waiting = ($job
			AND ! Service_Nj667_Graph3d::job_failed($job)
			AND ! in_array($st, ['done', 'finished', 'complete', 'success'], TRUE));
		$job_note = '';

		if ($job AND Service_Nj667_Graph3d::job_failed($job))
		{
			$job_note = Service_Nj667_Graph3d::job_error_text($job) ?: 'Генерация идей не удалась.';
		}

		if ($theme === '')
		{
			$theme = (string) Service_Nj667_Graph3d::setting('ideas_theme', '');
		}

		$this->set_title('Идеи для сборки — Графовый 3D-конструктор');

		$this->template->content = View::factory('nj667/ideas', [
			'presets'  => Service_Nj667_Graph3d::idea_presets(300),
			'job'      => $job,
			'waiting'  => $waiting,
			'job_note' => $job_note,
			'error'    => $error,
			'csrf'     => Security::token(),
			'theme'    => $theme,
		]);
	}

	/**
	 * GET /graph3d/moderate — панель модератора.
	 *
	 * Слева — граф заявки (живой, без снимка-ревизии), справа — вердикт:
	 * одобрить, отклонить (причина по желанию) или отметить как образец.
	 */
	public function action_moderate()
	{
		if ( ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Модерировать каталог могут администраторы.');
		}

		$status      = (string) $this->request->query('status');
		$queue       = Service_Nj667_Graph3d::moderation_queue($status !== '' ? $status : NULL);
		$code        = (string) $this->request->query('code');
		$revision_id = (int) $this->request->query('revision_id');
		$current     = NULL;

		foreach ($queue as $row)
		{
			if ($revision_id > 0 AND (int) Arr::get($row, 'revision_id') === $revision_id)
			{
				$current = $row;
				break;
			}
		}

		if ($current === NULL AND $code !== '')
		{
			foreach ($queue as $row)
			{
				if ((string) Arr::get($row, 'code') === $code)
				{
					$current = $row;
					break;
				}
			}
		}

		if ($current === NULL AND $queue)
		{
			$current = $queue[0];
		}

		if ($current === NULL AND $code !== '')
		{
			$project = Service_Nj667_Graph3d::project($code);

			if ($project !== NULL)
			{
				$pid = (int) Arr::get($project, 'id');
				$rev = $revision_id > 0
					? Service_Nj667_Graph3d::revision_of($pid, $revision_id)
					: (Service_Nj667_Graph3d::pending_revision($pid)
						?: Service_Nj667_Graph3d::published_revision($pid));

				if ($rev !== NULL)
				{
					$current = $project;
					$current['revision_id'] = (int) Arr::get($rev, 'id');
					$current['revision_no'] = (int) Arr::get($rev, 'no');
					$current['status']      = (string) Arr::get($rev, 'status');
					$current['graph_json']  = Arr::get($rev, 'graph_json');
					$current['moderation_note'] = (string) Arr::get($rev, 'moderation_note');

					foreach (['title', 'summary', 'tags'] as $field)
					{
						$val = trim((string) Arr::get($rev, $field, ''));

						if ($val !== '')
						{
							$current[$field] = $val;
						}
					}
				}
				else
				{
					$current = $project;
				}
			}
		}

		$graph = $current === NULL
			? Service_Nj667_Graph3d::graph_new('')
			: Service_Nj667_Graph3d::graph_of($current);

		$this->set_title('Модерация графов — Графовый 3D-конструктор');

		$this->template->content = View::factory('nj667/moderate', [
			'queue'      => $queue,
			'current'    => $current,
			'graph'      => $graph,
			'guard'      => $current === NULL ? [] : Service_Nj667_Graph3d::graph_validate($graph),
			'events'     => $current === NULL ? [] : Service_Nj667_Graph3d::events((int) Arr::get($current, 'id')),
			'status'     => $status,
			'statuses'   => Service_Nj667_Graph3d::statuses(),
			'node_types' => Service_Nj667_Graph3d::node_types(),
			'edge_types' => Service_Nj667_Graph3d::edge_types(),
			'csrf'       => Security::token(),
		]);
	}

	/**
	 * GET /graph3d/usage — перечень генераций: превью, промпт, токены.
	 *
	 * Право nj667.moderate (админ). Глобальный /nj120/usage — агрегаты по всем
	 * каналам; здесь — карточки именно nj667 с картинкой графа.
	 */
	public function action_usage()
	{
		if ( ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Учёт генераций доступен администраторам.');
		}

		$query = UTF8::substr(trim((string) $this->request->query('q')), 0, 200);
		// day=YYYY-MM-DD — клик по строке истории: карточки только за этот день
		$day   = (string) $this->request->query('day');
		$day   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : '';
		$days  = max(7, min(366, (int) ($this->request->query('days') ?: 30)));
		$per   = 24;
		$total = Service_Nj667_Graph3d::generations_count($query, $day);
		$pages = max(1, (int) ceil($total / $per));
		$page  = max(1, min($pages, (int) $this->request->query('page')));

		$this->set_title('Генерации — Графовый 3D-конструктор');

		$this->template->content = View::factory('nj667/usage', [
			'rows'    => Service_Nj667_Graph3d::generations($per, ($page - 1) * $per, $query, $day),
			'spend'   => Service_Nj667_Graph3d::generation_spend(20),
			'history' => Service_Nj667_Graph3d::generations_by_day($days, $query),
			'days'    => $days,
			'day'     => $day,
			'query'   => $query,
			'page'    => $page,
			'pages'   => $pages,
			'total'   => $total,
		]);
	}

	/**
	 * Ответ кнопки «Собрать узлы» на AJAX: страница сама ведёт ход по job_id,
	 * переход не нужен. Поля фиксированы — их читает nj667-stl.js (sendParts).
	 */
	protected function _parts_reply($ok, $job_id, $parts, $error, $build_id = 0, $render = FALSE)
	{
		// Без этого базовый after() подменит тело шаблоном страницы: ответ 200 с
		// заголовком JSON и HTML внутри. Страница такого ответа не читает, кнопка
		// писала «не поставились», хотя узлы уже в очереди (жалоба 13.10, curl).
		$this->auto_render = FALSE;

		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset)
			->body(json_encode([
				'ok'       => (bool) $ok,
				'job_id'   => (int) $job_id,
				'parts'    => (int) $parts,
				'error'    => (string) $error,
				'build_id' => (int) $build_id,
				'render'   => (bool) $render,
			], JSON_UNESCAPED_UNICODE));
	}
}
