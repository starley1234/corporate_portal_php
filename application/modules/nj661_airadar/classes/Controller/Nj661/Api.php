<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj661 API: /airadar/api/* — принимающая сторона конвейера ИИ-радара.
 *
 * Токен воркера (наследник Nj120_Api, как nj613): сюда ходят python-воркер
 * nj661.airadar и cron (tick). Формат тел: JSON (site.post_json c json_body)
 * или form-data с JSON-строкой в поле — оба варианта понимаются.
 */
class Controller_Nj661_Api extends Controller_Nj120_Api {

	/** GET/POST /airadar/api/tick — пиналка расписания (cron). */
	public function action_tick()
	{
		$this->_respond(Service_Nj661_Airadar::tick());
	}

	/**
	 * POST /airadar/api/items — кандидаты от воркера: PHP дедупит по url_hash
	 * (виденное не возвращается) и отдаёт контекст базы знаний для LLM.
	 */
	public function action_items()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);
		$items  = Arr::get($this->_body, 'items', []);

		if (is_string($items))
		{
			$items = json_decode($items, TRUE);
		}

		if ($run_id <= 0 OR ! is_array($items))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id и items (JSON-массив)'], 422);
		}

		$this->_respond(Service_Nj661_Airadar::api_items($run_id, $items));
	}

	/**
	 * POST /airadar/api/analysis — результаты LLM по материалам: статусы,
	 * категории, скоры, факты (в базу знаний) и идеи (на борд).
	 */
	public function action_analysis()
	{
		$run_id  = (int) Arr::get($this->_body, 'run_id', 0);
		$results = Arr::get($this->_body, 'results', []);

		if (is_string($results))
		{
			$results = json_decode($results, TRUE);
		}

		if ($run_id <= 0 OR ! is_array($results))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id и results (JSON-массив)'], 422);
		}

		$this->_respond(Service_Nj661_Airadar::api_analysis($run_id, $results));
	}

	/** POST /airadar/api/report_input — данные для финального LLM-свода. */
	public function action_report_input()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);

		if ($run_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
		}

		$this->_respond(Service_Nj661_Airadar::report_input($run_id));
	}

	/** POST /airadar/api/report — сохранить отчёт (и, если пора, письмо). */
	public function action_report()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);

		if ($run_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
		}

		$this->_respond(Service_Nj661_Airadar::api_report($run_id, (array) Arr::get($this->_body, 'report', []),
			(array) Arr::get($this->_body, 'stats', [])));
	}

	/** POST /airadar/api/progress — живой этап прогона для дашборда. */
	public function action_progress()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);

		if ($run_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
		}

		$this->_respond(Service_Nj661_Airadar::api_progress($run_id,
			(string) Arr::get($this->_body, 'stage', ''), (string) Arr::get($this->_body, 'note', ''),
			(array) Arr::get($this->_body, 'stats', [])));
	}

	/** POST /airadar/api/finish — завершение прогона (done|fail). */
	public function action_finish()
	{
		$run_id = (int) Arr::get($this->_body, 'run_id', 0);

		if ($run_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
		}

		$stats = Arr::get($this->_body, 'stats', []);

		if (is_string($stats))
		{
			$stats = json_decode($stats, TRUE);
		}

		$this->_respond(Service_Nj661_Airadar::api_finish($run_id,
			(string) Arr::get($this->_body, 'status', 'done'),
			is_array($stats) ? $stats : [],
			(string) Arr::get($this->_body, 'error', '')));
	}
}
