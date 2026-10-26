<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-666: приёмная python-воркера (/multivoice/w/*).
 *
 * Наследник Controller_Nj120_Api (как nj662/nj653/erp222): токен воркера в
 * заголовке X-Worker-Token, тело — JSON. Воркер не ходит в базу: он просит у
 * сайта контекст книги, отдаёт найденных персонажей и нарезанные блоки,
 * дёргает синтез блока и сообщает этапы.
 *
 * Точки:
 *   POST /multivoice/w/context     {book_id} — текст, настройки, персонажи,
 *                                  каталог голосов и цель по длине блока;
 *   POST /multivoice/w/characters  {book_id, characters:[…]} — слияние
 *                                  персонажей (замок не трогаем);
 *   POST /multivoice/w/blocks      {book_id, blocks:[…]} — принять нарезку;
 *   POST /multivoice/w/block       {book_id, block_id} — реплики блока кусками;
 *   POST /multivoice/w/audio       {book_id, block_id, audio} — принять звук;
 *   POST /multivoice/w/synth       {book_id, block_id} — запасной синтез на сайте;
 *   POST /multivoice/w/stage       {book_id, stage, note, progress} — этап;
 *   POST /multivoice/w/done        {book_id, status} — разбор/озвучка готовы;
 *   POST /multivoice/w/fail        {book_id, error} — упали.
 */
class Controller_Nj666_Worker extends Controller_Nj120_Api
{
	/** POST /multivoice/w/context — что знать воркеру об этой книге. */
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

		$context = Service_Nj666_Multivoice::worker_context((int) Arr::get($this->_body, 'book_id', 0));

		// Всегда 200: «книги нет» / «нет текста» — ответ точки, а не
		// отсутствующий маршрут. HTTP 404 заставлял site.post_json ретраить
		// ~2 с, воркер глотал отказ как {stopped:True} и ставил задаче done
		// при книге на «ждите» (11.10.2026).
		return $this->_respond($context);
	}

	/**
	 * POST /multivoice/w/characters — слияние персонажей.
	 *
	 * Воркер шлёт порцию за порцией: id (если узнал) или имя с описанием.
	 * Персонаж с замком не меняется: владелец поставил замочек ровно для
	 * того, чтобы модель не перекраивала его голос и характер.
	 */
	public function action_characters()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$book_id = (int) Arr::get($this->_body, 'book_id', 0);

		if ($book_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id'], 422);
		}

		$rows  = (array) Arr::get($this->_body, 'characters', []);
		$map   = [];
		$ids   = [];
		$added = 0;

		foreach ($rows as $row)
		{
			if ( ! is_array($row))
			{
				continue;
			}

			$before = count(Service_Nj666_Multivoice::characters($book_id));
			$id     = Service_Nj666_Multivoice::merge_character($book_id, $row);

			if ( ! $id)
			{
				continue;
			}

			// Ключ — ref из запроса воркера (или имя): по нему воркер ставит
			// персонажа в реплики, поэтому карта важнее, чем просто список id.
			$ref = (string) Arr::get($row, 'ref', '');

			if ($ref === '')
			{
				$ref = (string) Arr::get($row, 'name', '');
			}

			$ids[]            = $id;
			$map[$ref]        = $id;
			$map[(string) $id] = $id;

			if (count(Service_Nj666_Multivoice::characters($book_id)) > $before)
			{
				$added++;
			}
		}

		return $this->_respond(['ok' => TRUE, 'ids' => $ids, 'map' => $map, 'added' => $added]);
	}

	/** POST /multivoice/w/blocks — принять нарезку (реплики с говорящими). */
	public function action_blocks()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$book_id = (int) Arr::get($this->_body, 'book_id', 0);

		if ($book_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id'], 422);
		}

		$blocks = (array) Arr::get($this->_body, 'blocks', []);
		$final  = (bool) Arr::get($this->_body, 'final', FALSE);

		$n = Service_Nj666_Multivoice::apply_blocks($book_id, $blocks);

		if ($final)
		{
			Service_Nj666_Multivoice::set_status($book_id, Service_Nj666_Multivoice::S_PLANNED, '');
			Service_Nj666_Multivoice::set_stage($book_id, 'Книга разобрана',
				'персонажи и блоки готовы — можно озвучивать', 100);
		}

		return $this->_respond(['ok' => TRUE, 'blocks' => $n,
			'total' => count(Service_Nj666_Multivoice::blocks($book_id))]);
	}

	/**
	 * POST /multivoice/w/block — реплики блока, нарезанные под синтез.
	 *
	 * Воркер забирает chunks и сам ходит в OmniVoice: длинный синтез
	 * через этот запрос не идёт, nginx его не обрывает (11.10.2026).
	 */
	public function action_block()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$book_id  = (int) Arr::get($this->_body, 'book_id', 0);
		$block_id = (int) Arr::get($this->_body, 'block_id', 0);

		if ($book_id <= 0 OR $block_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id и block_id'], 422);
		}

		return $this->_respond(Service_Nj666_Multivoice::worker_block($book_id, $block_id));
	}

	/**
	 * POST /multivoice/w/audio — принять байты блока (base64).
	 *
	 * part/total — куски одной главы: склеивает сайт. error без audio —
	 * пометить блок неудачей, чтобы на странице была причина, а не «новый».
	 */
	public function action_audio()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$book_id  = (int) Arr::get($this->_body, 'book_id', 0);
		$block_id = (int) Arr::get($this->_body, 'block_id', 0);

		if ($book_id <= 0 OR $block_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id и block_id'], 422);
		}

		$bytes = Service_Nj666_Multivoice::decode_audio_b64((string) Arr::get($this->_body, 'audio', ''));
		$done  = Service_Nj666_Multivoice::accept_audio($book_id, $block_id, $bytes, $this->_body);

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/**
	 * POST /multivoice/w/synth — запасной синтез на сайте.
	 *
	 * Основной путь озвучки — воркер + /audio. Этот адрес оставлен на случай,
	 * если старый воркер ещё ходит сюда; длинную главу nginx может оборвать.
	 */
	public function action_synth()
	{
		if ($this->_halt)
		{
			return;
		}

		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$book_id  = (int) Arr::get($this->_body, 'book_id', 0);
		$block_id = (int) Arr::get($this->_body, 'block_id', 0);

		if ($book_id <= 0 OR $block_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id и block_id'], 422);
		}

		$done = Service_Nj666_Multivoice::voice_block($book_id, $block_id);

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/** POST /multivoice/w/stage — живой этап на страницу и в журнал задачи. */
	public function action_stage()
	{
		if ($this->_halt)
		{
			return;
		}

		$book_id = (int) Arr::get($this->_body, 'book_id', 0);

		if ($book_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id'], 422);
		}

		Service_Nj666_Multivoice::set_stage($book_id,
			(string) Arr::get($this->_body, 'stage', ''),
			(string) Arr::get($this->_body, 'note', ''),
			Arr::get($this->_body, 'progress') !== NULL ? (int) Arr::get($this->_body, 'progress') : NULL);

		return $this->_respond(['ok' => TRUE]);
	}

	/** POST /multivoice/w/done — этап закончен (разбор или озвучка). */
	public function action_done()
	{
		if ($this->_halt)
		{
			return;
		}

		$book_id = (int) Arr::get($this->_body, 'book_id', 0);

		if ($book_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id'], 422);
		}

		$status = (string) Arr::get($this->_body, 'status', Service_Nj666_Multivoice::S_PLANNED);
		$known  = [
			Service_Nj666_Multivoice::S_PLANNED,
			Service_Nj666_Multivoice::S_DONE,
			Service_Nj666_Multivoice::S_NEW,
		];

		Service_Nj666_Multivoice::set_status($book_id, in_array($status, $known, TRUE)
			? $status
			: Service_Nj666_Multivoice::S_PLANNED, '');

		Service_Nj666_Multivoice::set_stage($book_id,
			(string) Arr::get($this->_body, 'stage', 'Готово'),
			(string) Arr::get($this->_body, 'note', ''), 100);

		return $this->_respond(['ok' => TRUE]);
	}

	/** POST /multivoice/w/fail — задача упала: причина должна быть видна. */
	public function action_fail()
	{
		if ($this->_halt)
		{
			return;
		}

		$book_id = (int) Arr::get($this->_body, 'book_id', 0);

		if ($book_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду book_id'], 422);
		}

		Service_Nj666_Multivoice::set_status($book_id, Service_Nj666_Multivoice::S_ERROR,
			(string) Arr::get($this->_body, 'error', ''));

		Service_Nj666_Multivoice::set_stage($book_id, 'Не получилось',
			(string) Arr::get($this->_body, 'error', ''), 0);

		return $this->_respond(['ok' => TRUE]);
	}

	/**
	 * POST /multivoice/w/tick — пиналка: закрыть зависшие книги.
	 *
	 * Задача могла умереть вместе с воркером, а книга осталась в «разбирается»
	 * навсегда. Раз в несколько минут cron дёргает этот адрес, и книга
	 * честно переходит в «не получилось».
	 */
	public function action_tick()
	{
		if ($this->_halt)
		{
			return;
		}

		$minutes = max(5, (int) Arr::get($this->_body, 'minutes', 30));
		$closed  = 0;

		foreach (Service_Nj666_Multivoice::stuck($minutes) as $book)
		{
			Service_Nj666_Multivoice::set_status((int) Arr::get($book, 'id'),
				Service_Nj666_Multivoice::S_ERROR,
				'Задача не отвечала больше '.$minutes.' минут — попробуйте запустить заново.');

			$closed++;
		}

		Service_Nj666_Multivoice::sweep();

		return $this->_respond(['ok' => TRUE, 'closed' => $closed]);
	}

	/**
	 * JSON-ответ. Битый UTF-8 в тексте книги не должен отдавать пустое тело:
	 * json_encode тогда FALSE, воркер видит 200 без ok и тихо уходит
	 * (11.10.2026).
	 */
	protected function _respond(array $data, $status = 200)
	{
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

		if (defined('JSON_INVALID_UTF8_SUBSTITUTE'))
		{
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}

		$body = json_encode($data, $flags);

		if ($body === FALSE)
		{
			$status = 500;
			$body   = json_encode([
				'ok'    => FALSE,
				'error' => 'Не удалось отдать JSON: '.json_last_error_msg(),
			], JSON_UNESCAPED_UNICODE);
		}

		$this->response->status((int) $status);
		$this->response->body($body === FALSE ? '{"ok":false,"error":"JSON"}' : $body);
	}
}
