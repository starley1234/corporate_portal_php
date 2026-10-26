<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj669_Solid3d extends Controller_Erp100_Base
{
	protected $_uid = 0;
	protected $_start = FALSE;
	protected $_moderator = FALSE;
	protected $_error = '';

	public function before()
	{
		parent::before();
		$this->_uid = $this->_current_user ? (int) $this->_current_user->id : 0;
		$this->_start = ($this->_uid > 0 AND (bool) $this->can('nj669.start'));
		$this->_moderator = (bool) $this->can('nj669.moderate');
		$this->response->headers('Cache-Control', 'private, no-store');
		$this->set_title('3D-детали — параметрическая модель по описанию и фото');
		$this->set_description('Одна параметрическая деталь по описанию или фото: просмотр в браузере, STL для печати и история версий.');
		$nav = [['title' => 'Создать', 'href' => '/solid3d/create', 'icon' => 'fas fa-wand-magic-sparkles'],
			['title' => 'Каталог', 'href' => '/solid3d/catalog', 'icon' => 'fas fa-book-open']];
		if ($this->_uid)
		{
			$nav[] = ['title' => 'Проекты', 'href' => '/solid3d/projects', 'icon' => 'fas fa-folder-open'];
			$nav[] = ['title' => 'Лимиты', 'href' => '/solid3d/limits', 'icon' => 'fas fa-gauge-high'];
			$nav[] = ['title' => 'Генерации', 'href' => '/solid3d/usage', 'icon' => 'fas fa-coins'];
		}
		if ($this->_moderator)
		{
			$nav[] = ['title' => 'Модерация', 'href' => '/solid3d/moderate', 'icon' => 'fas fa-gavel'];
			$nav[] = ['title' => 'Идеи', 'href' => '/solid3d/ideas', 'icon' => 'fas fa-lightbulb'];
			$nav[] = ['title' => 'Настройки', 'href' => '/solid3d/settings', 'icon' => 'fas fa-sliders'];
		}
		$this->set_module_nav($nav);
	}

	protected function page($view, array $data = [])
	{
		$this->template->content = View::factory('nj669/'.$view, $data + [
			'uid' => $this->_uid, 'can_start' => $this->_start, 'moderator' => $this->_moderator,
			'csrf' => Security::token(), 'error' => $this->_error,
		]);
	}

	protected function login_wall()
	{
		$this->template->content = Gate::wall('solid3d', ['title' => '3D-детали — по аккаунту',
			'icon' => 'fas fa-cube', 'text' => 'Войдите, чтобы создавать детали, хранить версии и скачивать STL. Опубликованные модели открыты всем.']);
	}

	protected function check_post()
	{
		if ($this->request->method() !== Request::POST) { throw HTTP_Exception::factory(405); }
		if ( ! $this->_uid) { throw HTTP_Exception::factory(401); }
		if ( ! Security::check((string) $this->request->post('csrf'))) { throw HTTP_Exception::factory(403, 'Обновите страницу: защитный токен устарел.'); }
	}

	protected function report(Throwable $e)
	{
		Log_Plain::event(Log::ERROR, 'NJ669 запрос отклонён: :type :reason', [':type' => get_class($e), ':reason' => $e->getMessage()]);
		$this->_error = $e instanceof DomainException ? $e->getMessage() : 'Не удалось выполнить операцию. Причина записана в журнал сайта.';
		$this->response->status($e instanceof DomainException ? 422 : 500);
	}

	public function action_index()
	{
		$this->page('index', ['items' => Service_Nj669_Solid3d::listing('catalog'), 'totals' => $this->_uid ? Service_Nj669_Solid3d::usage_totals($this->_uid) : NULL]);
	}

	/** Каталог и модерация — один экран: модератор переключает вид ?view=moderate. */
	public function action_catalog()
	{
		$moderate = ($this->_moderator && (string) $this->request->query('view') === 'moderate');
		$this->moderation($moderate ? '/solid3d/catalog?view=moderate' : '/solid3d/catalog');
		$this->listing($moderate ? 'moderate' : 'catalog');
	}
	public function action_projects() { if ( ! $this->_uid) { return $this->login_wall(); } $this->listing('projects'); }
	public function action_moderate()
	{
		if ( ! $this->_moderator) { throw HTTP_Exception::factory(403); }
		$this->moderation('/solid3d/catalog?view=moderate');
		$this->listing('moderate');
	}

	/** Снятие с каталога и решения модератора из списка. Ошибки показываются на той же странице. */
	protected function moderation($back)
	{
		if ($this->request->method() !== Request::POST) { return; }
		$this->check_post();
		if ( ! $this->_moderator) { throw HTTP_Exception::factory(403); }
		$op = (string) $this->request->post('op');
		if ( ! in_array($op, ['publish', 'reject', 'unpublish'], TRUE)) { throw HTTP_Exception::factory(422); }
		try
		{
			Service_Nj669_Solid3d::change((string) $this->request->post('code'), $this->_uid, TRUE, $op, ['note' => (string) $this->request->post('note')]);
			HTTP::redirect($back, 303);
		}
		catch (HTTP_Exception $e) { throw $e; }
		catch (Throwable $e) { $this->report($e); }
	}

	protected function listing($mode)
	{
		$q = mb_substr(trim((string) $this->request->query('q')), 0, 100);
		$page = max(1, min(10001, (int) $this->request->query('page')));
		$this->page('list', ['mode' => $mode, 'q' => $q, 'page' => $page,
			'items' => Service_Nj669_Solid3d::listing($mode, $this->_uid, $q, $page)]);
	}

	public function action_create()
	{
		if ( ! $this->_uid) { return $this->login_wall(); }
		if ( ! $this->_start) { throw HTTP_Exception::factory(403); }
		$data = ['title' => '', 'prompt' => '', 'remix' => ''];
		if ($this->request->method() !== Request::POST AND $this->request->query('from'))
		{
			$from = Service_Nj669_Solid3d::project((string) $this->request->query('from'));
			if ( ! $from OR ! Service_Nj669_Solid3d::can_read($from, 0, FALSE, TRUE)) { throw HTTP_Exception::factory(404); }
			$from = Service_Nj669_Workspace::public_project($from);
			$data = ['title' => mb_substr($from['title'].' — копия', 0, 200), 'prompt' => $from['summary'], 'remix' => $from['code']];
		}
		if ($this->request->method() === Request::POST)
		{
			$this->check_post(); $data = (array) $this->request->post();
			try
			{
				$photo = Service_Nj669_Solid3d::photo_upload((array) Arr::get($_FILES, 'photo', []));
				$remix = NULL;
				if ((string) Arr::get($data, 'remix', '') !== '')
				{
					$from = Service_Nj669_Solid3d::project((string) $data['remix']);
					if ( ! $from OR ! Service_Nj669_Solid3d::can_read($from, 0, FALSE, TRUE)) { throw new DomainException('Шаблон больше не опубликован.'); }
					$remix = Service_Nj669_Solid3d::revision($from['published_revision_id'], $from['id']);
				}
				$p = Service_Nj669_Solid3d::create($this->_uid, (string) Arr::get($data, 'title', ''), (string) Arr::get($data, 'prompt', ''), $photo, $remix);
				if ( ! $remix)
				{
					try { Service_Nj669_Solid3d::queue($p['code'], $this->_uid, trim((string) Arr::get($data, 'prompt', '')) ?: 'Создай параметрическую деталь по фотографии.'); }
					catch (Throwable $e)
					{
						$this->report($e);
						HTTP::redirect('/solid3d/p/'.$p['code'].'?notice='.rawurlencode($this->_error), 303);
					}
				}
				HTTP::redirect('/solid3d/p/'.$p['code'], 303);
			}
			catch (HTTP_Exception $e) { throw $e; }
			catch (Throwable $e) { $this->report($e); }
		}
		$this->page('create', ['data' => $data, 'limits' => Service_Nj669_Solid3d::limits($this->_uid), 'ideas' => array_merge(array_column(Service_Nj669_Assist::ideas(40), 'body'), Service_Nj669_Solid3d::config('ideas'))]);
	}

	public function action_project()
	{
		$code = (string) $this->request->param('code');
		$public = $this->request->param('mode') === 'c';
		$p = Service_Nj669_Solid3d::project($code);
		if ( ! $p OR ! Service_Nj669_Solid3d::can_read($p, $this->_uid, $this->_moderator, $public)) { throw HTTP_Exception::factory(404); }
		if ($public) { $p = Service_Nj669_Workspace::public_project($p); }
		$owner = ($this->_uid > 0 AND (int) $p['user_id'] === $this->_uid AND ! $public);
		if ($this->request->method() === Request::POST)
		{
			$this->check_post();
			$action = (string) $this->request->post('op');
			// С публичной страницы разрешена только сборка STL: версию и каталог она не меняет.
			if ($public AND $action !== 'export') { throw HTTP_Exception::factory(405); }
			try
			{
				if ($action === 'generate')
				{
					if ( ! $owner OR ! $this->_start) { throw HTTP_Exception::factory(403); }
					Service_Nj669_Solid3d::queue($code, $this->_uid, (string) $this->request->post('prompt'));
				}
				elseif ($action === 'render')
				{
					// Ручная правка кода — только администратору: код модели пользователю не показываем.
					if ( ! $this->_moderator OR ! $this->_start) { throw HTTP_Exception::factory(403); }
					Service_Nj669_Solid3d::queue($code, $this->_uid, 'Пересчитать код (администратор)', (string) $this->request->post('source'));
				}
				elseif ($action === 'checkpoint') { Service_Nj669_Workspace::checkpoint($code, $this->_uid, (int) $this->request->post('revision'), (string) $this->request->post('label'), $this->request->post('milestone') === '1'); }
				elseif ($action === 'cancel') { Service_Nj669_Workspace::close_revision($code, (int) $this->request->post('revision'), 'Остановлено владельцем. Квота не возвращается.', $this->_uid); }
				elseif ($action === 'vlm') { Service_Nj669_Solid3d::queue_verify($code, $this->_uid, (int) $this->request->post('revision'), $this->_moderator); }
				elseif ($action === 'caption')
				{
					// Перегенерация карточки — только администратор; владелец карточку не редактирует.
					if ( ! $this->_moderator) { throw HTTP_Exception::factory(403); }
					Service_Nj669_Assist::queue('caption', $this->_uid, $code, '', TRUE);
				}
				elseif ($action === 'export')
				{
					// Временный STL под свои размеры: любой вошедший; версию, каталог, квоту и LLM не трогает.
					Service_Nj669_Export::queue($code, $this->_uid, (int) $this->request->post('revision'), (array) $this->request->post('params'), $this->_moderator);
				}
				elseif ($action === 'parameters')
				{
					if ( ! $owner OR ! $this->_start) { throw HTTP_Exception::factory(403); }
					Service_Nj669_Solid3d::resize($code, $this->_uid, (int) $this->request->post('revision'), (array) $this->request->post('params'));
				}
				else { Service_Nj669_Solid3d::change($code, $this->_uid, $this->_moderator, $action, (array) $this->request->post()); }
				$back = $public ? '/solid3d/c/'.$code : '/solid3d/p/'.$code;
				HTTP::redirect($action === 'delete' ? ($owner ? '/solid3d/projects' : '/solid3d/moderate') : $back.(in_array($action, ['vlm', 'export'], TRUE) ? '?revision='.(int) $this->request->post('revision') : ''), 303);
			}
			catch (HTTP_Exception $e) { throw $e; }
			catch (Throwable $e) { $this->report($e); }
			$p = Service_Nj669_Solid3d::project($code);
		}
		$history = $public ? [] : Service_Nj669_Solid3d::revisions($p['id']);
		$was_busy = count(array_filter($history, function ($r) { return in_array($r['status'], ['queued','running'], TRUE); })) > 0;
		$history_page = max(1, min(1001, (int) $this->request->query('history_page')));
		$live = $public ? ['busy' => FALSE, 'stage' => '', 'revision_id' => 0, 'elapsed_sec' => 0] : Service_Nj669_Workspace::live($p, $history);
		if ($was_busy AND ! $live['busy']) { $history = Service_Nj669_Solid3d::revisions($p['id']); }
		$verify_busy = self::verifying($history);
		$captions = ( ! $public AND ($owner OR $this->_moderator)) ? Service_Nj669_Assist::recent('caption', $p['id']) : [];
		// Временные STL: последняя сборка этого пользователя в проекте (статус, срок файла).
		Service_Nj669_Export::purge();
		$export = Service_Nj669_Export::latest($p['id'], $this->_uid);
		$export_busy = is_array($export) && in_array($export['status'], ['queued', 'running'], TRUE);
		// Публичная страница опрашивает только свою сборку STL. Сигнатура та же, что у страницы: иначе будет перезагрузка по кругу.
		if ($public AND $this->request->query('state') === '1')
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'application/json; charset=utf-8');
			$this->response->body(json_encode(['ok' => TRUE, 'signature' => self::signature($p, [], [], $export), 'busy' => $export_busy]));
			return;
		}
		if ( ! $public AND $this->request->query('state') === '1')
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'application/json; charset=utf-8');
			$this->response->body(json_encode(['ok' => TRUE, 'signature' => self::signature($p, $history, $captions, $export), 'busy' => ($live['busy'] OR $verify_busy OR $export_busy OR count(array_filter($captions, function ($t) { return in_array($t['status'], ['queued','running'], TRUE); })) > 0), 'live' => $live]));
			return;
		}
		$rid = $public ? (int) $p['published_revision_id'] : (int) $this->request->query('revision');
		if ( ! $rid) { $rid = (int) $p['current_revision_id']; }
		$r = Service_Nj669_Solid3d::revision($rid, $p['id']);
		// Значения ползунков: сохранённый JSON версии, а для старых версий — литералы из кода.
		// default — значения из исходника версии (слот «по умолчанию»); value — сохранённые в JSON.
		$parameters = $r ? Service_Nj669_Parameters::parse($r['source']) : [];
		foreach ($parameters as $name => $field) { $parameters[$name]['default'] = $field['value']; }
		$saved = $r ? (array) json_decode((string) $r['params'], TRUE) : [];
		$parameters = Service_Nj669_Parameters::restore($parameters, $saved);
		$this->page('project', ['p' => $p, 'revision' => $r, 'history' => $history, 'owner' => $owner, 'public' => $public,
			'notice' => mb_substr((string) $this->request->query('notice'), 0, 500),
			'signature' => self::signature($p, $history, $captions, $export), 'live' => $live, 'captions' => $captions, 'verify_busy' => $verify_busy,
			'export' => $export, 'export_busy' => $export_busy,
			'history_page' => $history_page,
			'history_rows' => $history_page === 1 ? $history : ($public ? [] : Service_Nj669_Solid3d::revisions($p['id'], $history_page)),
			'parameters' => $parameters,
			'limits' => $owner ? Service_Nj669_Solid3d::limits($this->_uid) : []]);
	}

	public static function signature(array $p, array $history, array $tasks = [], $export = NULL)
	{
		return hash('sha256', json_encode([$p['current_revision_id'], array_map(function ($r) { return [$r['id'], $r['status'], Arr::get($r, 'vlm_status', 'none')]; }, $history), array_map(function ($t) { return [$t['id'], $t['status']]; }, $tasks), is_array($export) ? [$export['id'], $export['status']] : []]));
	}

	/** Идёт ли ИИ-проверка ракурсов хоть у одной версии на странице истории. */
	public static function verifying(array $history)
	{
		return count(array_filter($history, function ($r) { return in_array(Arr::get($r, 'vlm_status', 'none'), ['queued', 'running'], TRUE); })) > 0;
	}

	public function action_file()
	{
		$p = Service_Nj669_Solid3d::project((string) $this->request->param('code'));
		$rid = (int) $this->request->param('revision'); $kind = (string) $this->request->param('kind');
		if ( ! $p OR ! Service_Nj669_Solid3d::can_read_revision($p, $rid, $this->_uid, $this->_moderator)) { throw HTTP_Exception::factory(404); }
		$r = Service_Nj669_Solid3d::revision($rid, $p['id']);
		$file = Service_Nj669_Solid3d::file($p['code'], $rid, $kind);
		if ( ! $r OR $r['status'] !== 'done' OR ! is_file($file)) { throw HTTP_Exception::factory(404); }
		// Исходный код модели — только администратору.
		if ($kind === 'scad' AND ! $this->_moderator) { throw HTTP_Exception::factory(404); }
		$this->auto_render = FALSE;
		$types = ['glb' => 'model/gltf-binary', 'stl' => 'application/octet-stream', 'scad' => 'text/plain; charset=utf-8', 'png' => 'image/png', 'png2' => 'image/png', 'png3' => 'image/png'];
		$this->response->headers('Content-Type', $types[$kind]);
		$this->response->headers('X-Content-Type-Options', 'nosniff');
		$this->response->headers('Content-Length', (string) filesize($file));
		$this->response->headers('Content-Disposition', (in_array($kind, ['scad','stl'], TRUE) ? 'attachment' : 'inline').'; filename="solid3d-'.$p['code'].'-r'.$rid.'.'.$kind.'"');
		$this->response->body(file_get_contents($file));
	}

	/** Временный STL под свои размеры: доступен создателю сборки и модератору, час после готовности. */
	public function action_export_file()
	{
		$path = Service_Nj669_Export::download((string) $this->request->param('code'), (int) $this->request->param('export'), $this->_uid, $this->_moderator);
		if ( ! $path) { throw HTTP_Exception::factory(404); }
		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/octet-stream');
		$this->response->headers('X-Content-Type-Options', 'nosniff');
		$this->response->headers('Content-Length', (string) filesize($path));
		$this->response->headers('Content-Disposition', 'attachment; filename="solid3d-'.$this->request->param('code').'-'.(int) $this->request->param('export').'.stl"');
		$this->response->body(file_get_contents($path));
	}

	public function action_ideas()
	{
		if ( ! $this->_moderator) { throw HTTP_Exception::factory(403); }
		if ($this->request->method() === Request::POST)
		{
			$this->check_post();
			try
			{
				$op = (string) $this->request->post('op');
				if ($op === 'generate') { Service_Nj669_Assist::queue('ideas', $this->_uid, '', (string) $this->request->post('theme')); }
				elseif ($op === 'save') { Service_Nj669_Assist::idea_save((int) $this->request->post('idea'), (string) $this->request->post('body'), (string) $this->request->post('theme')); }
				elseif ($op === 'delete')
				{
					if ($this->request->post('armed') !== '1') { throw new DomainException('Подтвердите удаление идеи.'); }
					Service_Nj669_Solid3d::exec('DELETE FROM nj_669_solid3d_ideas WHERE id=:id', [':id' => (int) $this->request->post('idea')]);
				}
				else { throw new DomainException('Неизвестная операция.'); }
				HTTP::redirect('/solid3d/ideas', 303);
			}
			catch (HTTP_Exception $e) { throw $e; }
			catch (Throwable $e) { $this->report($e); }
		}
		$tasks = Service_Nj669_Assist::recent('ideas');
		if ($this->request->query('state') === '1')
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'application/json; charset=utf-8');
			$this->response->body(json_encode(['ok' => TRUE, 'signature' => hash('sha256', json_encode(array_column($tasks, 'status', 'id'))),
				'busy' => count(array_filter($tasks, function ($t) { return in_array($t['status'], ['queued','running'], TRUE); })) > 0]));
			return;
		}
		$this->page('ideas', ['ideas' => Service_Nj669_Assist::ideas(), 'tasks' => $tasks]);
	}

	public function action_usage()
	{
		if ( ! $this->_uid) { return $this->login_wall(); }
		$q = mb_substr(trim((string) $this->request->query('q')), 0, 100);
		$status = (string) $this->request->query('status');
		$page = max(1, min(10001, (int) $this->request->query('page')));
		$scope = $this->_moderator ? NULL : $this->_uid;
		$this->page('usage', ['items' => Service_Nj669_Solid3d::usage($scope, $q, $status, $page), 'totals' => Service_Nj669_Solid3d::usage_totals($scope),
			'q' => $q, 'status' => $status, 'page' => $page]);
	}

	public function action_settings()
	{
		if ( ! $this->_moderator) { throw HTTP_Exception::factory(403); }
		if ($this->request->method() === Request::POST)
		{
			$this->check_post();
			try { Service_Nj669_Solid3d::save_settings((array) $this->request->post()); HTTP::redirect('/solid3d/settings?saved=1', 303); }
			catch (HTTP_Exception $e) { throw $e; }
			catch (Throwable $e) { $this->report($e); }
		}
		$this->page('settings', ['settings' => Service_Nj669_Solid3d::settings(), 'saved' => $this->request->query('saved') === '1']);
	}

	public function action_limits()
	{
		if ( ! $this->_uid) { return $this->login_wall(); }
		$target = $this->_moderator ? (int) ($this->request->query('user') ?: $this->_uid) : $this->_uid;
		if ($target <= 0) { throw HTTP_Exception::factory(422); }
		if ($this->request->method() === Request::POST)
		{
			$this->check_post();
			if ( ! $this->_moderator) { throw HTTP_Exception::factory(403); }
			try { Service_Nj669_Solid3d::save_limits($target, (array) $this->request->post()); HTTP::redirect('/solid3d/limits?user='.$target, 303); }
			catch (HTTP_Exception $e) { throw $e; }
			catch (Throwable $e) { $this->report($e); }
		}
		$this->page('limits', ['limits' => Service_Nj669_Solid3d::limits($target), 'target' => $target]);
	}
}
