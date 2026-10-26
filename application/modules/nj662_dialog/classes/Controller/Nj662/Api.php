<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-662: приёмная python-воркера (/dialog/api/*).
 *
 * Наследник Controller_Nj120_Api (как nj653/nj661/erp222): токен воркера в
 * заголовке X-Worker-Token, тело — JSON или form-data с JSON-строкой. Воркер
 * не ходит в базу: он спрашивает у сайта контекст обмена, отдаёт реплики,
 * поиск и сводку — и всё. Расписание обменов держит сайт: после приёма обмена
 * он сам ставит следующий (Service_Nj662_Dialog::apply_turn).
 *
 * Почему так, а не модель прямо в PHP: вызов LLM идёт десятками секунд, плюс
 * поиск в интернете и чтение страниц. Веб-процесс должен отвечать быстро, а у
 * воркера свои ретраи, таймауты и логи — это архитектура платформы.
 *
 * Точки:
 *   POST /dialog/api/context  {dialog_id, turn}  — контекст обмена (участники,
 *                                история, материалы, остаток бюджета поиска);
 *   POST /dialog/api/turn     {dialog_id, turn, data:{messages, search, summary}}
 *   POST /dialog/api/progress {dialog_id, stage, note} — живой этап на странице;
 *   POST /dialog/api/quick    {dialog_id, agents}      — участники подобраны;
 *   POST /dialog/api/fail     {dialog_id, error}       — обмен не получился;
 *   POST /dialog/api/tick                              — пиналка: закрыть
 *                                брошенные диалоги и догнать индексацию.
 */
class Controller_Nj662_Api extends Controller_Nj120_Api {

	/** POST /dialog/api/context — что знать агенту на этом обмене. */
	public function action_context()
	{
		$dialog_id = (int) Arr::get($this->_body, 'dialog_id', 0);
		$turn      = (int) Arr::get($this->_body, 'turn', 0);

		if ($dialog_id <= 0 OR $turn <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду dialog_id и turn'], 422);
		}

		$context = Service_Nj662_Dialog::worker_context($dialog_id, $turn);

		// «Диалог больше не идёт» — это НЕ ошибка приёмной: воркер прочитает
		// stop и закроет задачу мирно (пауза, отмена, конец разговора).
		return $this->_respond($context, 200);
	}

	/** POST /dialog/api/progress — живой этап хода (статус-ряд на странице). */
	public function action_progress()
	{
		$dialog_id = (int) Arr::get($this->_body, 'dialog_id', 0);

		if ($dialog_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду dialog_id'], 422);
		}

		Service_Nj662_Dialog::set_stage($dialog_id,
			(string) Arr::get($this->_body, 'stage', ''), (string) Arr::get($this->_body, 'note', ''));

		return $this->_respond(['ok' => TRUE]);
	}

	/** POST /dialog/api/turn — принять обмен и поставить следующий. */
	public function action_turn()
	{
		$dialog_id = (int) Arr::get($this->_body, 'dialog_id', 0);
		$turn      = (int) Arr::get($this->_body, 'turn', 0);
		$data      = Arr::get($this->_body, 'data', []);

		if (is_string($data))
		{
			$data = json_decode($data, TRUE);
		}

		if ($dialog_id <= 0 OR $turn <= 0 OR ! is_array($data))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду dialog_id, turn и data (JSON)'], 422);
		}

		$res = Service_Nj662_Dialog::apply_turn($dialog_id, $turn, $data);

		if (empty($res['ok']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => (string) Arr::get($res, 'error', 'Обмен не принят')], 422);
		}

		return $this->_respond($res);
	}

	/** POST /dialog/api/quick — участники подобраны (быстрый старт). */
	public function action_quick()
	{
		$dialog_id = (int) Arr::get($this->_body, 'dialog_id', 0);
		$agents    = Arr::get($this->_body, 'agents', []);

		if (is_string($agents))
		{
			$agents = json_decode($agents, TRUE);
		}

		if ($dialog_id <= 0 OR ! is_array($agents))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду dialog_id и agents (JSON)'], 422);
		}

		$autostart = Service_Nj662_Dialog::as_bool(Arr::get($agents, 'autostart', FALSE));
		$res       = Service_Nj662_Dialog::apply_quick($dialog_id, $agents, $autostart);

		if (empty($res['ok']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => (string) Arr::get($res, 'error', 'Участники не приняты')], 422);
		}

		return $this->_respond($res);
	}

	/** POST /dialog/api/fail — обмен не получился: причина видна на странице. */
	public function action_fail()
	{
		$dialog_id = (int) Arr::get($this->_body, 'dialog_id', 0);
		$error     = (string) Arr::get($this->_body, 'error', 'ход не получился');

		if ($dialog_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду dialog_id'], 422);
		}

		$dialog = Service_Nj662_Dialog::dialog($dialog_id);

		// Подбор участников не удался — это не «диалог сломался»: он остаётся
		// черновиком, и владелец может нажать кнопку ещё раз.
		if ($dialog AND (string) $dialog['status'] === 'quick')
		{
			Service_Nj662_Dialog::quick_failed($dialog_id, $error);
		}
		else
		{
			Service_Nj662_Dialog::fail($dialog_id, $error);
		}

		return $this->_respond(['ok' => TRUE]);
	}

	/**
	 * POST /dialog/api/tick — пиналка модуля (её же зовёт cron платформы).
	 *
	 * Делает ровно две вещи: закрывает брошенные диалоги (задача очереди
	 * закрыта, а диалог «идёт») и догоняет состояние индексации материалов.
	 * Расписание обменов НЕ трогает: дубли задач — хуже брошенной строки.
	 */
	public function action_tick()
	{
		return $this->_respond(Service_Nj662_Dialog::tick());
	}
}
