<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ERP-222 «Кузница систем»: дашборд, живой ход цикла и запуск.
 *
 * Дашборд отвечает на три вопроса владельца:
 *   1. «что сейчас происходит» — статус-ряд цикла (спиннер + этап + секундомер,
 *      лестница этапов Роли → Синтез → Критика → Сводка) обновляется поллингом
 *      /forge/progress, как на /airadar;
 *   2. «что уже сделано» — счётчики (миры, артефакты, средняя ценность, доля
 *      разобранного Критиком) и свежие артефакты с оценкой;
 *   3. «что делать дальше» — большая кнопка «Сделать шаг» и «Цикл из N шагов»
 *      (шаг = один вызов LLM, поэтому владелец сам выбирает длину забега).
 */
class Controller_Erp222_Index extends Controller_Erp222_Main {

	/** GET /forge — дашборд. */
	public function action_index()
	{
		$ov = Service_Erp222_Forge::overview();

		// Живой цикл мог не начаться, но мир уже выбран — план шага показываем
		// по последнему циклу: владелец видит, чем закончилось прошлое.
		$run = $ov['run'] ? $ov['run'] : Service_Erp222_Forge::last_run();

		$this->template->content = View::factory('erp222/index', [
			'ov'       => $ov,
			'run'      => $run,
			'progress' => Service_Erp222_Forge::run_progress($run),
			'counts'   => $ov['counts'],
			'latest'   => $ov['latest'],
			'events'   => $ov['events'],
			'world'    => $ov['world'],
			'roles'    => Service_Erp222_Forge::roles(),
			'types'    => Service_Erp222_Forge::types(),
			'steps_default' => (int) Arr::get((array) Service_Erp222_Forge::config('limits', []), 'steps_default', 6),
			'steps_max'     => (int) Arr::get((array) Service_Erp222_Forge::config('limits', []), 'steps_max', 12),
			'csrf'     => Security::token(),
			'flash'    => $this->_flash(),
		]);
	}

	/**
	 * GET /forge/progress — JSON хода цикла (поллинг дашборда).
	 *
	 * Форма ответа одна с серверной отрисовкой (Service_Erp222_Forge::run_progress):
	 * поэтому статус-ряд не «прыгает» при первом же опросе, а JS-движок один и
	 * тот же, что у ИИ-радара.
	 */
	public function action_progress()
	{
		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

		$run = Service_Erp222_Forge::active_run();

		$this->response->body(json_encode([
			'ok'    => TRUE,
			'run'   => Service_Erp222_Forge::run_progress($run),
			'pulse' => $run !== NULL,
			'counts' => Service_Erp222_Forge::counts(),
		], JSON_UNESCAPED_UNICODE));

		return $this->response;
	}

	/**
	 * POST /forge/step — сделать шаг или запустить цикл из N шагов.
	 *
	 * Два ответа на одно действие (как «Собрать сейчас» у ИИ-радара):
	 *   · fetch дашборда (X-Requested-With) получает JSON — страница не
	 *     перезагружается, статус-ряд оживает сразу;
	 *   · обычная отправка формы без JS получает 303 с плашкой.
	 */
	public function action_step()
	{
		$this->_post_only();

		$world_id = (int) Arr::get($_POST, 'world_id', 0);
		$steps    = (int) Arr::get($_POST, 'steps', 0);

		// «Один шаг» — вторая кнопка той же формы: она несёт quick=one, чтобы
		// владелец мог посмотреть на работу одной роли, не запуская весь цикл.
		if ((string) Arr::get($_POST, 'quick', '') === 'one')
		{
			$steps = 1;
		}

		// Куда вернуться при обычной отправке формы (без JS). Значение — свой
		// путь внутри /forge: чужой адрес в редирект не пускаем.
		$back = (string) Arr::get($_POST, 'back', '/forge');

		if ( ! preg_match('~^/forge(/[a-z0-9/_-]{0,120})?$~i', $back))
		{
			$back = '/forge';
		}

		$res   = Service_Erp222_Forge::start_run($world_id, $steps, 'manual', $this->_current_user ? (int) $this->_current_user->id : 1);
		$error = (string) Arr::get($res, 'error', '');

		if ($this->request->is_ajax())
		{
			$this->auto_render = FALSE;
			$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

			$this->response->body(json_encode([
				'ok'    => $error === '',
				'error' => $error,
				'run'   => Service_Erp222_Forge::run_progress(Service_Erp222_Forge::active_run()),
			], JSON_UNESCAPED_UNICODE));

			return $this->response;
		}

		$this->_flash_set($error !== '' ? 'error' : 'notice', $error !== '' ? $error
			: 'Цикл запущен: '.((int) Arr::get($res, 'steps', 0)).' шагов. Ход виден на дашборде');

		HTTP::redirect($back, 303);
	}
}
