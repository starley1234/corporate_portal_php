<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-668: приёмная python-воркера (/draw2d/w/*).
 *
 *   POST /draw2d/w/context  {project_id} — сцена, промпт, лимиты, фото;
 *   POST /draw2d/w/scene    {project_id, scene, model} — принять сцену;
 *   POST /draw2d/w/fail     {project_id, error} — ошибка, снять оверлей;
 *   POST /draw2d/w/ideas    {theme, ideas, job_id} — пачка пресетов.
 *
 * Воркер не ходит в базу. Проверка сцены — здесь, через Scene::clean.
 */
class Controller_Nj668_Worker extends Controller_Nj120_Api
{
	public function action_context()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$context = Service_Nj668_Draw2d::worker_context((int) Arr::get($this->_body, 'project_id', 0));

		return $this->_respond($context, Arr::get($context, 'ok') ? 200 : 404);
	}

	public function action_scene()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$project = Service_Nj668_Draw2d::project_by_id((int) Arr::get($this->_body, 'project_id', 0));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден.'], 404);
		}

		$scene = Service_Nj668_Scene::clean((array) Arr::get($this->_body, 'scene', []),
			(int) Service_Nj668_Draw2d::limits('max_shapes', 120));

		if ( ! Arr::get($scene, 'shapes'))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сцена пустая — модель не нарисовала фигур.'], 422);
		}

		$done = Service_Nj668_Draw2d::accept_scene((int) Arr::get($project, 'id'), $scene,
			(string) Arr::get($this->_body, 'model', ''));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	public function action_fail()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$project = Service_Nj668_Draw2d::project_by_id((int) Arr::get($this->_body, 'project_id', 0));

		if ($project === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден.'], 404);
		}

		Service_Nj668_Draw2d::set_error((int) Arr::get($project, 'id'),
			(string) Arr::get($this->_body, 'error', 'Модель не нарисовала сцену.'));

		return $this->_respond(['ok' => TRUE]);
	}

	public function action_ideas()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$done = Service_Nj668_Draw2d::idea_presets_save(
			(string) Arr::get($this->_body, 'theme', ''),
			(array) Arr::get($this->_body, 'ideas', []),
			(int) Arr::get($this->_body, 'job_id', 0));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}
}
