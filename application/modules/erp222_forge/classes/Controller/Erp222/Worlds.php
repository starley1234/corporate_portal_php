<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ERP-222 «Кузница систем»: миры (что синтезируем) — список, создание, страница.
 *
 * Мир — это постановка задачи и правила игры: задумка, протокол (что считаем
 * артефактом и что ценным), цель, роли. Раньше мир создавался «Genesis»-вызовом
 * LLM: модель придумывала название, протокол и агентов, и владелец получал
 * красивый, но чужой замысел. Здесь постановку даёт человек (это его инструмент),
 * а модель работает по ней: первый шаг цикла — Архитектор уточняет границы.
 */
class Controller_Erp222_Worlds extends Controller_Erp222_Main {

	/** GET /forge/worlds — список миров; POST — создать мир. */
	public function action_index()
	{
		if ($this->request->method() === Request::POST)
		{
			$this->_create();

			return;
		}

		$worlds = Service_Erp222_Forge::worlds(50);

		$this->template->content = View::factory('erp222/worlds', [
			'worlds' => $worlds,
			'counts' => Service_Erp222_Forge::counts(),
			'roles'  => Service_Erp222_Forge::roles(),
			'csrf'   => Security::token(),
			'flash'  => $this->_flash(),
		]);
	}

	/** GET /forge/world/<code> — страница мира. */
	public function action_view()
	{
		$code = (string) $this->request->param('code', '');
		$world = Service_Erp222_Forge::world_by_code($code);

		if ($world === NULL)
		{
			throw HTTP_Exception::factory(404, 'Мир не найден');
		}

		$run = Service_Erp222_Forge::active_run((int) $world['id']);

		if ($run === NULL)
		{
			$run = Service_Erp222_Forge::last_run((int) $world['id']);
		}

		$this->set_title($world['title'].' — мир Кузницы систем');
		$this->set_description(mb_substr(trim((string) $world['brief']), 0, 300));

		$this->template->content = View::factory('erp222/world', [
			'world'    => $world,
			'roles'    => Service_Erp222_Forge::world_roles((int) $world['id']),
			'artifacts' => Service_Erp222_Forge::artifacts_of((int) $world['id'], '', 30),
			'runs'     => Service_Erp222_Forge::runs_of((int) $world['id'], 10),
			'run'      => $run,
			'progress' => Service_Erp222_Forge::run_progress($run),
			'events'   => Service_Erp222_Forge::events(0, 12, (int) $world['id']),
			'types'    => Service_Erp222_Forge::types(),
			'steps_default' => (int) Arr::get((array) Service_Erp222_Forge::config('limits', []), 'steps_default', 6),
			'steps_max'     => (int) Arr::get((array) Service_Erp222_Forge::config('limits', []), 'steps_max', 12),
			'csrf'     => Security::token(),
			'flash'    => $this->_flash(),
		]);
	}

	/**
	 * POST /forge/world/<code>/status — пауза или продолжение мира.
	 *
	 * Пауза — не удаление: артефакты и история остаются, циклы не запускаются
	 * (start_run это проверяет). Так владелец «закрывает» наигранное, не теряя
	 * выпущенное.
	 */
	public function action_status()
	{
		$this->_post_only();

		$code  = (string) $this->request->param('code', '');
		$world = Service_Erp222_Forge::world_by_code($code);

		if ($world === NULL)
		{
			throw HTTP_Exception::factory(404, 'Мир не найден');
		}

		$want = (string) Arr::get($_POST, 'status', 'active') === 'active' ? 'active' : 'paused';

		Service_Erp222_Forge::set_status((int) $world['id'], $want);

		$this->_flash_set('notice', $want === 'active'
			? 'Мир снова активен — можно запускать циклы'
			: 'Мир на паузе: артефакты и история на месте, новые циклы не запускаются');

		HTTP::redirect('/forge/world/'.(string) $world['code'], 303);
	}

	/** POST /forge/worlds — создать мир и сразу поставить первый цикл (по желанию). */
	protected function _create()
	{
		$this->_post_only();

		$title  = (string) Arr::get($_POST, 'title', '');
		$brief  = (string) Arr::get($_POST, 'brief', '');
		$domain = (string) Arr::get($_POST, 'domain', '');

		$res = Service_Erp222_Forge::create_world($title, $brief, $domain, $this->_current_user ? (int) $this->_current_user->id : 1);

		if (isset($res['error']))
		{
			$this->_flash_set('error', (string) $res['error']);

			HTTP::redirect('/forge/worlds', 303);
		}

		// «Запустить сразу» — галочка в форме: мир без цикла остаётся заготовкой,
		// а владелец пришёл за артефактами. Ошибку запуска показываем плашкой,
		// мир при этом уже создан (он не пропадёт).
		if ((string) Arr::get($_POST, 'run_now', '') === '1')
		{
			$steps = (int) Arr::get($_POST, 'steps', 0);
			$start = Service_Erp222_Forge::start_run((int) $res['id'], $steps, 'manual', $this->_current_user ? (int) $this->_current_user->id : 1);

			$this->_flash_set(isset($start['error']) ? 'error' : 'notice', isset($start['error'])
				? 'Мир создан, но цикл не запустился: '.(string) $start['error']
				: 'Мир создан, цикл запущен: '.(int) Arr::get($start, 'steps', 0).' шагов');

			HTTP::redirect('/forge/world/'.(string) $res['code'], 303);
		}

		$this->_flash_set('notice', 'Мир создан: «'.$title.'». Запустите цикл — роли выпустят артефакты');

		HTTP::redirect('/forge/world/'.(string) $res['code'], 303);
	}
}
