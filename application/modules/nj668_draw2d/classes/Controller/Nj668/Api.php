<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-668 JSON API страницы.
 *
 *   POST /draw2d/api/create    — новая иллюстрация и сборка;
 *   POST /draw2d/api/generate  — перерисовать по описанию и/или фото;
 *   POST /draw2d/api/save      — сохранить сцену с холста (ревизия);
 *   POST /draw2d/api/publish   — в каталог (без новой ревизии);
 *   POST /draw2d/api/delete    — удалить;
 *   POST /draw2d/api/moderate  — вердикт модератора;
 *   GET  /draw2d/api/status    — поллинг;
 *   GET  /draw2d/api/preview   — SVG превью;
 *   GET  /draw2d/api/ideas     — три случайных пресета.
 */
class Controller_Nj668_Api extends Controller
{
	public $auto_render = FALSE;

	protected $_body = [];

	public function before()
	{
		parent::before();

		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

		$raw     = $this->request->body();
		$decoded = $raw ? json_decode($raw, TRUE) : NULL;

		$this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();

		if ( ! $this->_body AND $this->request->query())
		{
			$this->_body = (array) $this->request->query();
		}
	}

	public function action_create()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$gate = $this->_gate(TRUE);

		if ($gate !== NULL)
		{
			return $gate;
		}

		$prompt = UTF8::substr(trim((string) Arr::get($this->_body, 'prompt', '')), 0,
			(int) Service_Nj668_Draw2d::limits('max_prompt', 800));
		$images = Arr::get($this->_body, 'images', []);
		$jpegs  = is_array($images) ? Service_Nj668_Draw2d::prepare_payload($images) : [];

		if ($prompt === '' AND ! $jpegs)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Опишите сцену или приложите фото.'], 422);
		}

		$user = Auth::instance()->get_user();
		$done = Service_Nj668_Draw2d::create($user ? (int) $user->id : 0, array_merge($this->_body, [
			'has_photos' => (bool) $jpegs,
		]));

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
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
			return $this->_respond($queued, 502);
		}

		return $this->_respond($done + [
			'url'    => '/draw2d/p/'.Arr::get($done, 'code'),
			'job_id' => (int) Arr::get($queued, 'job_id'),
		]);
	}

	public function action_generate()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project(TRUE);

		if ( ! is_array($project))
		{
			return $project;
		}

		$prompt = UTF8::substr(trim((string) Arr::get($this->_body, 'prompt',
			Arr::get($project, 'prompt', ''))), 0,
			(int) Service_Nj668_Draw2d::limits('max_prompt', 800));

		if ($prompt === '' AND Service_Nj668_Draw2d::photos_count((string) Arr::get($project, 'code')) < 1)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Опишите, что нарисовать, или приложите фото.'], 422);
		}

		Service_Nj668_Draw2d::set_prompt((int) Arr::get($project, 'id'), $prompt);

		$queued = Service_Nj668_Draw2d::queue(Service_Nj668_Draw2d::CH_GENERATE, $project, [
			'prompt' => $prompt,
		]);

		return $this->_respond($queued, Arr::get($queued, 'ok') ? 200 : 502);
	}

	public function action_save()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$project = $this->_project();

		if ( ! is_array($project))
		{
			return $project;
		}

		$done = Service_Nj668_Draw2d::save_scene((int) Arr::get($project, 'id'),
			(array) Arr::get($this->_body, 'scene', []),
			Arr::get($this->_body, 'title', NULL));

		return $this->_respond($done);
	}

	public function action_publish()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$gate = $this->_gate();

		if ($gate !== NULL)
		{
			return $gate;
		}

		$user = Auth::instance()->get_user();
		$done = Service_Nj668_Draw2d::publish((string) Arr::get($this->_body, 'code', ''),
			$user ? (int) $user->id : 0,
			class_exists('Visitor') ? (string) Visitor::uid() : '');

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	public function action_delete()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$gate = $this->_gate();

		if ($gate !== NULL)
		{
			return $gate;
		}

		$user = Auth::instance()->get_user();
		$done = Service_Nj668_Draw2d::delete((string) Arr::get($this->_body, 'code', ''),
			$user ? (int) $user->id : 0,
			class_exists('Visitor') ? (string) Visitor::uid() : '');

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 404);
	}

	public function action_status()
	{
		$project = $this->_project_read();

		if ( ! is_array($project))
		{
			return $project;
		}

		$job_id = (int) Arr::get($project, 'job_id', 0);
		$job    = Service_Nj668_Draw2d::job_state($job_id);
		$error  = (string) Arr::get($project, 'error', '');

		if ($job_id > 0 AND ! Service_Nj668_Draw2d::job_live($job))
		{
			if (Service_Nj668_Draw2d::job_failed($job))
			{
				$error = Service_Nj668_Draw2d::job_error_text($job);
				Service_Nj668_Draw2d::set_error((int) Arr::get($project, 'id'), $error);
			}
			else
			{
				Service_Nj668_Draw2d::clear_job((int) Arr::get($project, 'id', 0));
			}

			$job_id = 0;
		}

		return $this->_respond([
			'ok'     => TRUE,
			'status' => (string) Arr::get($project, 'status'),
			'job_id' => $job_id,
			'job'    => $job,
			'shapes' => (int) Arr::get($project, 'shapes_count'),
			'scene'  => Service_Nj668_Draw2d::scene_of($project),
			'error'  => $error,
			'updated'=> (string) Arr::get($project, 'updated_at'),
		]);
	}

	public function action_ideas()
	{
		$n = max(1, min(6, (int) Arr::get($this->_body, 'n', 3)));

		return $this->_respond(['ok' => TRUE, 'ideas' => Service_Nj668_Draw2d::idea_presets_pick($n)]);
	}

	public function action_moderate()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		if ( ! Gate::logged_in())
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Нужно войти на сайт.'], 401);
		}

		if ( ! $this->_allows('nj668.moderate'))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Модерировать каталог могут администраторы.'], 403);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		$project = Service_Nj668_Draw2d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Иллюстрация не найдена.'], 404);
		}

		$user = Auth::instance()->get_user();
		$done = Service_Nj668_Draw2d::moderate((int) Arr::get($project, 'id'),
			(string) Arr::get($this->_body, 'verdict', ''),
			(string) Arr::get($this->_body, 'note', ''),
			$user ? (int) $user->id : 0);

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	public function action_preview()
	{
		$code    = (string) Arr::get($this->_body, 'code', '');
		$project = Service_Nj668_Draw2d::project($code);

		if ($project === NULL)
		{
			$name = (string) Arr::get($this->_body, 'sample', '');
			$svg  = Service_Nj668_Scene::svg(Service_Nj668_Scene::sample($name !== '' ? $name : 'sunset'), 480, 320);
		}
		else
		{
			$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';
			$user = Auth::instance()->get_user();
			$own  = Service_Nj668_Draw2d::owns($project, $user ? (int) $user->id : 0, $uid);

			if ( ! $own AND ! Service_Nj668_Draw2d::is_public($project))
			{
				throw HTTP_Exception::factory(403, 'Это чужая иллюстрация.');
			}

			$svg = Service_Nj668_Draw2d::svg_of($project, 480, 320);
		}

		$this->response->headers('Content-Type', 'image/svg+xml; charset=UTF-8');
		$this->response->headers('Cache-Control', 'public, max-age=60');
		$this->response->body($svg);
	}

	protected function _gate($need_run = FALSE)
	{
		if ( ! Gate::logged_in())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Войдите на сайт и повторите запрос.',
			], 401);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		if ($need_run AND ! $this->_allows('nj668.start'))
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Запускать сборку могут привилегированные аккаунты (роль vip) и администраторы.',
			], 403);
		}

		if ($need_run AND ! Service_Nj668_Draw2d::rate_ok())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Слишком часто. Лимит: '.Service_Nj668_Draw2d::rate_text().'.',
			], 429);
		}

		return NULL;
	}

	protected function _project($need_run = FALSE)
	{
		$gate = $this->_gate($need_run);

		if ($gate !== NULL)
		{
			return $gate;
		}

		$project = Service_Nj668_Draw2d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Иллюстрация не найдена.'], 404);
		}

		$user = Auth::instance()->get_user();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj668_Draw2d::owns($project, $user ? (int) $user->id : 0, $uid))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Это чужая иллюстрация.'], 403);
		}

		return $project;
	}

	protected function _project_read()
	{
		$project = Service_Nj668_Draw2d::project((string) Arr::get($this->_body, 'code', ''));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Иллюстрация не найдена.'], 404);
		}

		$user = Auth::instance()->get_user();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj668_Draw2d::owns($project, $user ? (int) $user->id : 0, $uid))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Это чужая иллюстрация.'], 403);
		}

		return $project;
	}

	protected function _allows($ability)
	{
		if ( ! Gate::logged_in())
		{
			return FALSE;
		}

		$user = Auth::instance()->get_user();

		if ($user === NULL)
		{
			return FALSE;
		}

		if (method_exists($user, 'has_role') AND $user->has_role('admin'))
		{
			return TRUE;
		}

		$roles = [];

		try
		{
			foreach ($user->roles->find_all() as $role)
			{
				$roles[] = (string) $role->name;
			}
		}
		catch (Throwable $e)
		{
			return FALSE;
		}

		return (bool) ACL::check($roles, (string) $ability);
	}

	protected function _respond(array $data, $status = 200)
	{
		return $this->response->status((int) $status)
			->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
