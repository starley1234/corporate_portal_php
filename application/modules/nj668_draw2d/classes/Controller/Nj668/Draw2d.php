<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-668 «Плоские иллюстрации» — страницы.
 *
 *   GET      /draw2d            — витрина;
 *   GET|POST /draw2d/create     — новая сборка (текст и/или фото);
 *   GET      /draw2d/projects   — мои работы;
 *   GET      /draw2d/catalog    — каталог;
 *   GET|POST /draw2d/ideas      — пресеты идей (nj668.moderate);
 *   GET|POST /draw2d/moderate   — панель модератора (nj668.moderate);
 *   GET      /draw2d/p/<code>   — холст;
 *   GET      /draw2d/c/<code>   — страница просмотра.
 *
 * Сборка идёт в воркере. Запуск — право nj668.start (vip или админ).
 */
class Controller_Nj668_Draw2d extends Controller_Erp100_Base
{
	protected $_user_id = 0;
	protected $_can_start = FALSE;
	protected $_can_moderate = FALSE;

	public function before()
	{
		parent::before();

		$this->_user_id      = $this->_current_user ? (int) $this->_current_user->id : 0;
		$this->_can_start    = (bool) $this->can('nj668.start');
		$this->_can_moderate = (bool) $this->can('nj668.moderate');

		$nav = [
			['title' => 'Витрина',  'href' => '/draw2d', 'icon' => 'fas fa-bezier-curve'],
			['title' => 'Создать',  'href' => '/draw2d/create', 'icon' => 'fas fa-wand-magic-sparkles'],
			['title' => 'Проекты',  'href' => '/draw2d/projects', 'icon' => 'fas fa-folder-open'],
			['title' => 'Каталог',  'href' => '/draw2d/catalog', 'icon' => 'fas fa-book-open'],
		];

		if ($this->_can_moderate)
		{
			$nav[] = ['title' => 'Модерация', 'href' => '/draw2d/moderate', 'icon' => 'fas fa-gavel'];
			$nav[] = ['title' => 'Идеи',      'href' => '/draw2d/ideas', 'icon' => 'fas fa-lightbulb'];
		}

		$this->set_module_nav($nav);

		$this->set_title('Плоские иллюстрации: тема словами — в векторную картинку');
		$this->set_description('Опишите сцену — модель соберёт векторную иллюстрацию из прямых, кривых Безье и цветных заливок. На холсте фигуры можно двигать, перекрашивать и публиковать в каталог.');
		$this->set_keywords('иллюстрация вектор svg безье прямые заливка 2d рисунок конструктор');

		$this->response->headers('Cache-Control', 'private, no-cache, max-age=0');
		$this->response->headers('Pragma', 'no-cache');
	}

	public function action_index()
	{
		$this->template->content = View::factory('nj668/index', [
			'logged'    => Gate::logged_in(),
			'can_start' => $this->_can_start,
			'showcase'  => Service_Nj668_Draw2d::showcase(9),
			'csrf'      => Security::token(),
		]);
	}

	public function action_create()
	{
		$error = '';

		if ($this->request->method() === Request::POST)
		{
			$error = $this->_create_project((array) $this->request->post());
		}

		$this->set_title('Создать иллюстрацию — Плоские иллюстрации');

		$this->template->content = View::factory('nj668/create', [
			'logged'       => Gate::logged_in(),
			'can_start'    => $this->_can_start,
			'can_moderate' => $this->_can_moderate,
			'error'        => $error,
			'rate_text'    => Service_Nj668_Draw2d::rate_text(),
			'limits'       => Service_Nj668_Draw2d::limits(),
			'ideas'        => Service_Nj668_Draw2d::idea_presets_pick(3),
			'csrf'         => Security::token(),
			'post'         => (array) $this->request->post(),
		]);
	}

	public function action_projects()
	{
		$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';

		$this->set_title('Мои иллюстрации — Плоские иллюстрации');

		$this->template->content = View::factory('nj668/projects', [
			'logged'    => Gate::logged_in(),
			'mine'      => Service_Nj668_Draw2d::for_user($this->_user_id, $uid),
			'statuses'  => Service_Nj668_Draw2d::statuses(),
			'csrf'      => Security::token(),
			'rate_text' => Service_Nj668_Draw2d::rate_text(),
			'limits'    => Service_Nj668_Draw2d::limits(),
		]);
	}

	public function action_catalog()
	{
		$q = UTF8::substr(trim((string) $this->request->query('q')), 0, 80);

		$this->set_title('Каталог — Плоские иллюстрации');

		$this->template->content = View::factory('nj668/catalog', [
			'logged' => Gate::logged_in(),
			'items'  => Service_Nj668_Draw2d::catalog(24, $q),
			'q'      => $q,
			'csrf'   => Security::token(),
		]);
	}

	public function action_project()
	{
		$code    = (string) $this->request->param('code', '');
		$project = Service_Nj668_Draw2d::project($code);

		if ($project === NULL)
		{
			throw HTTP_Exception::factory(404, 'Иллюстрация не найдена или удалена.');
		}

		$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj668_Draw2d::owns($project, $this->_user_id, $uid))
		{
			throw HTTP_Exception::factory(403, 'Это чужая иллюстрация.');
		}

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу.');
			}

			$restore = (int) Arr::get($post, 'restore', 0);
			$forget  = (int) Arr::get($post, 'forget_revision', 0);

			if ($restore > 0)
			{
				Service_Nj668_Draw2d::restore((int) Arr::get($project, 'id'), $restore);
			}
			elseif ($forget > 0)
			{
				Service_Nj668_Draw2d::delete_revision((int) Arr::get($project, 'id'), $forget);
			}

			HTTP::redirect('/draw2d/p/'.$code, 303);
		}

		$this->set_title('Иллюстрация: '.(string) Arr::get($project, 'title'));

		$pid = (int) Arr::get($project, 'id');

		$this->template->content = View::factory('nj668/project', [
			'logged'     => Gate::logged_in(),
			'can_start'  => $this->_can_start,
			'project'    => $project,
			'scene'      => Service_Nj668_Draw2d::scene_of($project),
			'revisions'  => Service_Nj668_Draw2d::revisions($pid),
			'limits'     => Service_Nj668_Draw2d::limits(),
			'rate_text'  => Service_Nj668_Draw2d::rate_text(),
			'csrf'       => Security::token(),
		]);
	}

	public function action_view()
	{
		$code    = (string) $this->request->param('code', '');
		$project = Service_Nj668_Draw2d::project($code);

		if ($project === NULL)
		{
			throw HTTP_Exception::factory(404, 'Иллюстрация не найдена.');
		}

		$uid = class_exists('Visitor') ? (string) Visitor::uid() : '';
		$own = Service_Nj668_Draw2d::owns($project, $this->_user_id, $uid);

		if ( ! $own AND ! Service_Nj668_Draw2d::is_public($project))
		{
			throw HTTP_Exception::factory(403, 'Эта иллюстрация ещё не в каталоге.');
		}

		if ( ! $own)
		{
			Service_Nj668_Draw2d::bump_views((int) Arr::get($project, 'id'));
		}

		$this->set_title((string) Arr::get($project, 'title').' — Плоские иллюстрации');

		$this->template->content = View::factory('nj668/view', [
			'logged'  => Gate::logged_in(),
			'own'     => $own,
			'project' => $project,
			'scene'   => Service_Nj668_Draw2d::scene_of($project),
			'svg'     => Service_Nj668_Draw2d::svg_of($project),
		]);
	}

	protected function _create_project(array $post)
	{
		if ( ! Gate::logged_in())
		{
			return 'Иллюстрация доступна по аккаунту — войдите на сайт.';
		}

		if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
		{
			return 'Сессия устарела, обновите страницу.';
		}

		if ( ! $this->_can_start)
		{
			return 'Запускать сборку могут привилегированные аккаунты (роль vip) и администраторы.';
		}

		if ( ! Service_Nj668_Draw2d::rate_ok())
		{
			return 'Слишком часто. Лимит: '.Service_Nj668_Draw2d::rate_text().'.';
		}

		$prompt = UTF8::substr(trim((string) Arr::get($post, 'prompt', '')), 0,
			(int) Service_Nj668_Draw2d::limits('max_prompt', 800));
		$files  = isset($_FILES['photos']) ? $_FILES['photos'] : [];
		$jpegs  = Service_Nj668_Draw2d::prepare_uploads($files);

		if ($prompt === '' AND ! $jpegs)
		{
			return Service_Nj668_Draw2d::uploads_count($files) > 0
				? 'Фото не принялись: нужен jpeg, png или webp до 5 МБ.'
				: 'Опишите сцену или приложите фото.';
		}

		$done = Service_Nj668_Draw2d::create($this->_user_id, array_merge($post, [
			'has_photos' => (bool) $jpegs,
		]));

		if ( ! Arr::get($done, 'ok'))
		{
			return (string) Arr::get($done, 'error', 'Не удалось создать.');
		}

		if ($jpegs)
		{
			Service_Nj668_Draw2d::write_photos((string) Arr::get($done, 'code', ''), $jpegs);
		}

		Service_Nj668_Draw2d::sweep();

		$project = Service_Nj668_Draw2d::project_by_id((int) Arr::get($done, 'id'));
		$queued  = Service_Nj668_Draw2d::queue(Service_Nj668_Draw2d::CH_GENERATE, $project, [
			'prompt' => $prompt,
		]);

		if ( ! Arr::get($queued, 'ok'))
		{
			return (string) Arr::get($queued, 'error', 'Очередь не приняла задачу.');
		}

		HTTP::redirect('/draw2d/p/'.$done['code'], 303);

		return '';
	}

	/**
	 * GET|POST /draw2d/ideas — копилка идей для страницы «Создать».
	 * Тема → воркер nj668.ideas → 30 пресетов. Только админ (nj668.moderate).
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
				if ( ! Service_Nj668_Draw2d::idea_preset_delete((int) Arr::get($post, 'delete')))
				{
					$error = 'Не удалось удалить идею.';
				}
				else
				{
					HTTP::redirect('/draw2d/ideas', 303);
				}
			}
			elseif ((string) Arr::get($post, 'generate', '') !== '')
			{
				$done = Service_Nj668_Draw2d::queue_ideas($theme, $this->_user_id);

				if ( ! Arr::get($done, 'ok'))
				{
					$error = (string) Arr::get($done, 'error', 'Не удалось поставить генерацию.');
				}
				else
				{
					HTTP::redirect('/draw2d/ideas', 303);
				}
			}
		}

		$job_id   = (int) Service_Nj668_Draw2d::setting('ideas_job', '0');
		$job      = $job_id > 0 ? Service_Nj668_Draw2d::job_state($job_id) : [];
		$st       = strtolower((string) Arr::get($job, 'status', Arr::get($job, 'state', '')));
		$waiting  = ($job
			AND ! Service_Nj668_Draw2d::job_failed($job)
			AND ! in_array($st, ['done', 'finished', 'complete', 'success'], TRUE));
		$job_note = '';

		if ($job AND Service_Nj668_Draw2d::job_failed($job))
		{
			$job_note = Service_Nj668_Draw2d::job_error_text($job) ?: 'Генерация идей не удалась.';
		}

		if ($theme === '')
		{
			$theme = (string) Service_Nj668_Draw2d::setting('ideas_theme', '');
		}

		$this->set_title('Идеи для сборки — Плоские иллюстрации');

		$this->template->content = View::factory('nj668/ideas', [
			'presets'  => Service_Nj668_Draw2d::idea_presets(300),
			'job'      => $job,
			'waiting'  => $waiting,
			'job_note' => $job_note,
			'error'    => $error,
			'csrf'     => Security::token(),
			'theme'    => $theme,
		]);
	}

	/**
	 * GET|POST /draw2d/moderate — панель модератора.
	 * Одобрить, отклонить, отметить как образец. Публикация без ревизии.
	 */
	public function action_moderate()
	{
		if ( ! $this->_can_moderate)
		{
			throw HTTP_Exception::factory(403, 'Модерировать каталог могут администраторы.');
		}

		$error  = '';
		$status = (string) $this->request->query('status');

		if ($this->request->method() === Request::POST)
		{
			$post = (array) $this->request->post();

			if ( ! Security::check((string) Arr::get($post, 'csrf', '')))
			{
				$error = 'Сессия устарела, обновите страницу.';
			}
			else
			{
				$project = Service_Nj668_Draw2d::project((string) Arr::get($post, 'code', ''));

				if ($project === NULL)
				{
					$error = 'Иллюстрация не найдена.';
				}
				else
				{
					$done = Service_Nj668_Draw2d::moderate((int) Arr::get($project, 'id'),
						(string) Arr::get($post, 'verdict', ''),
						(string) Arr::get($post, 'note', ''),
						$this->_user_id);

					if ( ! Arr::get($done, 'ok'))
					{
						$error = (string) Arr::get($done, 'error', 'Не удалось записать решение.');
					}
					else
					{
						$q = '/draw2d/moderate?code='.rawurlencode((string) Arr::get($project, 'code'));

						if ($status !== '')
						{
							$q .= '&status='.rawurlencode($status);
						}

						HTTP::redirect($q, 303);
					}
				}
			}
		}

		$queue   = Service_Nj668_Draw2d::moderation_queue($status !== '' ? $status : NULL);
		$code    = (string) $this->request->query('code');
		$current = NULL;

		if ($code !== '')
		{
			foreach ($queue as $row)
			{
				if ((string) Arr::get($row, 'code') === $code)
				{
					$current = $row;
					break;
				}
			}

			if ($current === NULL)
			{
				$current = Service_Nj668_Draw2d::project($code);
			}
		}

		if ($current === NULL AND $queue)
		{
			$current = $queue[0];
		}

		$this->set_title('Модерация иллюстраций — Плоские иллюстрации');

		$this->template->content = View::factory('nj668/moderate', [
			'queue'    => $queue,
			'current'  => $current,
			'scene'    => $current === NULL ? Service_Nj668_Scene::scene_new() : Service_Nj668_Draw2d::scene_of($current),
			'svg'      => $current === NULL ? '' : Service_Nj668_Draw2d::svg_of($current),
			'status'   => $status,
			'statuses' => Service_Nj668_Draw2d::statuses(),
			'error'    => $error,
			'csrf'     => Security::token(),
		]);
	}
}
