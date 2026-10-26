<?php defined('SYSPATH') or die('No direct script access.');

/**
 * ERP-222 «Кузница систем»: приёмная python-воркера (/forge/api/*).
 *
 * Наследник Controller_Nj120_Api (как nj661/nj613): токен воркера в заголовке
 * X-Worker-Token, тело — JSON или form-data с JSON-строкой. Воркер работает
 * на отдельной машине и в БД не ходит: он спрашивает у сайта план шага, отдаёт
 * готовый артефакт и докладывает о завершении цикла.
 *
 * Почему так, а не LLM прямо в PHP: вызов модели идёт десятками секунд, а
 * веб-процесс должен отвечать быстро. Плюс у воркера свои ретраи, таймауты и
 * логи — это и есть архитектура платформы (nj120 → канал → воркер).
 */
class Controller_Erp222_Api extends Controller_Nj120_Api {

	/** POST /forge/api/step — план следующего шага цикла. */
	public function action_step()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);

		if ($run_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
		}

		$plan = Service_Erp222_Forge::step_input($run_id);

		if (isset($plan['error']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => (string) $plan['error']], 422);
		}

		return $this->_respond($plan);
	}

	/** POST /forge/api/artifact — принять артефакт шага. */
	public function action_artifact()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);
		$data   = Arr::get($this->_body, 'artifact', []);

		if (is_string($data))
		{
			$data = json_decode($data, TRUE);
		}

		if ($run_id <= 0 OR ! is_array($data))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id и artifact (JSON-объект)'], 422);
		}

		$res = Service_Erp222_Artifact::intake($run_id, $data);

		if (isset($res['error']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => (string) $res['error']], 422);
		}

		return $this->_respond($res);
	}

	/** POST /forge/api/progress — живой этап цикла (воркер сообщает, где он). */
	public function action_progress()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);
		$stage  = trim((string) Arr::get($this->_body, 'stage', ''));
		$note   = trim((string) Arr::get($this->_body, 'note', ''));

		if ($run_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
		}

		Service_Erp222_Forge::set_stage($run_id, $stage, $note);

		return $this->_respond(['ok' => TRUE]);
	}

	/** POST /forge/api/finish — цикл завершён (или остановлен с причиной). */
	public function action_finish()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);
		$status = (string) Arr::get($this->_body, 'status', 'done');
		$error  = (string) Arr::get($this->_body, 'error', '');
		$stats  = Arr::get($this->_body, 'stats', []);

		if (is_string($stats))
		{
			$stats = (array) json_decode($stats, TRUE);
		}

		if ($run_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
		}

		return $this->_respond(Service_Erp222_Forge::finish_run($run_id, $status, $error, (array) $stats));
	}

	/**
	 * GET/POST /forge/api/tick — пиналка расписания (cron).
	 *
	 * Расписание в конфиге модуля НЕ включено: цикл синтеза — это осознанное
	 * действие владельца, а не фон. Эндпоинт живёт для ручной автоматизации
	 * (например, ночной прогон по миру из crontab): tick ставит цикл самому
	 * свежему активному миру.
	 */
	public function action_tick()
	{
		$world_id = (int) Arr::get($this->_body, 'world_id', 0);

		if ($world_id <= 0)
		{
			$worlds = Service_Erp222_Forge::worlds(1);
			$world_id = $worlds ? (int) $worlds[0]['id'] : 0;
		}

		if ($world_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Нет ни одного мира']);
		}

		$steps = (int) Arr::get($this->_body, 'steps', 0);
		$res   = Service_Erp222_Forge::start_run($world_id, $steps, 'schedule', 1);

		if (isset($res['error']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => (string) $res['error']], 422);
		}

		return $this->_respond($res);
	}
}
