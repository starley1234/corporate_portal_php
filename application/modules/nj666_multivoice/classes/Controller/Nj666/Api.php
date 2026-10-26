<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-666 «Многоголосая озвучка» — JSON API для страницы.
 *
 *   POST /multivoice/api/create      — новая книга;
 *   POST /multivoice/api/plan        — запустить разбор (очередь nj666.plan);
 *   POST /multivoice/api/voice       — запустить озвучку блоков (nj666.voice);
 *   POST /multivoice/api/settings    — настройки книги до разбора;
 *   POST /multivoice/api/character   — правка персонажа (голос, замочек);
 *   POST /multivoice/api/character_del — удалить персонажа (`to` — кому отдать реплики);
 *   POST /multivoice/api/voice_preview — превью выбранного голоса (кэш);
 *   POST /multivoice/api/block       — сохранить title/turns блока;
 *   POST /multivoice/api/block_voice — переозвучить один блок;
 *   GET  /multivoice/api/status      — живое состояние книги (для поллинга);
 *   GET  /multivoice/api/voices      — каталог голосов (пол, возраст, описание);
 *   POST /multivoice/api/delete      — удалить книгу.
 *
 * Всё, кроме справочника голосов, требует аккаунта: и разбор, и синтез —
 * платные вычисления на внешних сервисах.
 */
class Controller_Nj666_Api extends Controller
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

	/** POST /multivoice/api/create — новая книга из текста. */
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

		$user = Auth::instance()->get_user();

		$done = Service_Nj666_Multivoice::create($user ? (int) $user->id : 0, $this->_body);

		if ( ! Arr::get($done, 'ok'))
		{
			return $this->_respond($done, 422);
		}

		Service_Nj666_Multivoice::sweep();

		return $this->_respond($done + ['url' => '/multivoice/b/'.Arr::get($done, 'code')]);
	}

	/** POST /multivoice/api/plan — поставить разбор в очередь. */
	public function action_plan()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book(TRUE);

		if ( ! is_array($book))
		{
			return $book;
		}

		$status = (string) Arr::get($book, 'status');

		if ($status === Service_Nj666_Multivoice::S_PLAN OR $status === Service_Nj666_Multivoice::S_VOICING)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Разбор уже идёт.'], 409);
		}

		// Повторный разбор — это новая нарезка: старые блоки и файлы не годятся.
		Service_Nj666_Multivoice::clear_blocks((int) Arr::get($book, 'id'), $book);
		Service_Nj666_Multivoice::set_status((int) Arr::get($book, 'id'),
			Service_Nj666_Multivoice::S_PLAN, '');
		Service_Nj666_Multivoice::set_stage((int) Arr::get($book, 'id'),
			'Разбор в очереди', 'ждите: книга уйдёт в воркер', 1);

		$done = Service_Nj666_Multivoice::queue(Service_Nj666_Multivoice::CHANNEL_PLAN, $book);

		if ( ! Arr::get($done, 'ok'))
		{
			Service_Nj666_Multivoice::set_status((int) Arr::get($book, 'id'),
				Service_Nj666_Multivoice::S_ERROR, (string) Arr::get($done, 'error'));

			return $this->_respond($done, 502);
		}

		return $this->_respond(['ok' => TRUE, 'job_id' => (int) Arr::get($done, 'job_id'),
			'status' => Service_Nj666_Multivoice::S_PLAN]);
	}

	/** POST /multivoice/api/voice — озвучить блоки (очередь nj666.voice). */
	public function action_voice()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book(TRUE);

		if ( ! is_array($book))
		{
			return $book;
		}

		if ( ! Service_Nj666_Multivoice::blocks((int) Arr::get($book, 'id')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сначала разберите книгу: блоков пока нет.'], 422);
		}

		Service_Nj666_Multivoice::set_status((int) Arr::get($book, 'id'),
			Service_Nj666_Multivoice::S_VOICING, '');
		Service_Nj666_Multivoice::set_stage((int) Arr::get($book, 'id'),
			'Озвучка в очереди', 'блоки пойдут по очереди', 1);

		$done = Service_Nj666_Multivoice::queue(Service_Nj666_Multivoice::CHANNEL_VOICE, $book,
			(int) Arr::get(Service_Nj666_Multivoice::branch('voice'), 'timeout_sec', 3600));

		if ( ! Arr::get($done, 'ok'))
		{
			Service_Nj666_Multivoice::set_status((int) Arr::get($book, 'id'),
				Service_Nj666_Multivoice::S_ERROR, (string) Arr::get($done, 'error'));

			return $this->_respond($done, 502);
		}

		return $this->_respond(['ok' => TRUE, 'job_id' => (int) Arr::get($done, 'job_id'),
			'status' => Service_Nj666_Multivoice::S_VOICING]);
	}

	/** POST /multivoice/api/settings — настройки книги. */
	public function action_settings()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book();

		if ( ! is_array($book))
		{
			return $book;
		}

		Service_Nj666_Multivoice::update((int) Arr::get($book, 'id'), $this->_body);

		return $this->_respond(['ok' => TRUE]);
	}

	/** POST /multivoice/api/character — правка персонажа (голос, замочек). */
	public function action_character()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book();

		if ( ! is_array($book))
		{
			return $book;
		}

		$done = Service_Nj666_Multivoice::save_character((int) Arr::get($book, 'id'),
			(int) Arr::get($this->_body, 'id', 0), $this->_body);

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/** POST /multivoice/api/character_del — удалить персонажа. */
	public function action_character_del()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book();

		if ( ! is_array($book))
		{
			return $book;
		}

		$to_raw = Arr::get($this->_body, 'to', 0);
		$to     = ($to_raw === 'narrator' OR $to_raw === '' OR $to_raw === NULL)
			? 0
			: (int) $to_raw;

		$done = Service_Nj666_Multivoice::delete_character((int) Arr::get($book, 'id'),
			(int) Arr::get($this->_body, 'id', 0), FALSE, $to);

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/** POST /multivoice/api/voice_preview — короткая фраза выбранным голосом. */
	public function action_voice_preview()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book();

		if ( ! is_array($book))
		{
			return $book;
		}

		$done = Service_Nj666_Multivoice::preview_voice($book,
			(string) Arr::get($this->_body, 'voice', ''));

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/** POST /multivoice/api/block — сохранить заголовок и реплики блока. */
	public function action_block()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book();

		if ( ! is_array($book))
		{
			return $book;
		}

		$done = Service_Nj666_Multivoice::save_block((int) Arr::get($book, 'id'),
			(int) Arr::get($this->_body, 'no', 0), $this->_body);

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 422);
	}

	/** POST /multivoice/api/block_voice — переозвучить один блок (очередь nj666.voice). */
	public function action_block_voice()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		$book = $this->_book(TRUE);

		if ( ! is_array($book))
		{
			return $book;
		}

		$block_id = (int) Arr::get($this->_body, 'id', 0);

		if ($block_id <= 0)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Жду id блока.'], 422);
		}

		if ( ! Service_Nj666_Multivoice::rate_ok())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Слишком часто. Лимит: '.Service_Nj666_Multivoice::rate_text().'.',
			], 429);
		}

		// Длинный блок через PHP/nginx не озвучить (fastcgi_read_timeout):
		// тот же канал, что «озвучить книгу», только один id (11.10.2026).
		Service_Nj666_Multivoice::set_status((int) Arr::get($book, 'id'),
			Service_Nj666_Multivoice::S_VOICING, '');
		Service_Nj666_Multivoice::set_stage((int) Arr::get($book, 'id'),
			'Озвучка в очереди', 'блок пойдёт воркеру', 1);

		$done = Service_Nj666_Multivoice::queue(Service_Nj666_Multivoice::CHANNEL_VOICE, $book,
			(int) Arr::get(Service_Nj666_Multivoice::branch('voice'), 'timeout_sec', 3600),
			['block_ids' => [$block_id]]);

		if ( ! Arr::get($done, 'ok'))
		{
			Service_Nj666_Multivoice::set_status((int) Arr::get($book, 'id'),
				Service_Nj666_Multivoice::S_ERROR, (string) Arr::get($done, 'error'));

			return $this->_respond($done, 502);
		}

		return $this->_respond(['ok' => TRUE, 'job_id' => (int) Arr::get($done, 'job_id'),
			'status' => Service_Nj666_Multivoice::S_VOICING]);
	}

	/** GET /multivoice/api/status — живое состояние книги (поллинг страницы). */
	public function action_status()
	{
		$code = (string) Arr::get($this->_body, 'code', '');
		$book = Service_Nj666_Multivoice::book($code);

		if ($book === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Книга не найдена.'], 404);
		}

		$logged  = Gate::logged_in();
		$user_id = $logged && Auth::instance()->get_user() ? (int) Auth::instance()->get_user()->id : 0;

		if ( ! Service_Nj666_Multivoice::owns($book, $user_id,
			class_exists('Visitor') ? (string) Visitor::uid() : ''))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Это чужая книга.'], 403);
		}

		$progress = Service_Nj666_Multivoice::progress_of((int) Arr::get($book, 'id'));

		return $this->_respond([
			'ok'       => TRUE,
			'status'   => (string) Arr::get($book, 'status'),
			'stage'    => (string) Arr::get($book, 'stage'),
			'note'     => (string) Arr::get($book, 'stage_note'),
			'error'    => (string) Arr::get($book, 'error'),
			'progress' => $progress,
			'characters' => count(Service_Nj666_Multivoice::characters((int) Arr::get($book, 'id'))),
			'updated'  => (string) Arr::get($book, 'updated_at'),
		]);
	}

	/** GET /multivoice/api/voices — каталог голосов с характеристиками. */
	public function action_voices()
	{
		$filter = [];

		foreach (['gender', 'age'] as $field)
		{
			$value = trim((string) Arr::get($this->_body, $field, ''));

			if ($value !== '')
			{
				$filter[$field] = $value;
			}
		}

		$voices = Service_Nj665_Voice::voices_catalog((bool) Arr::get($this->_body, 'refresh', FALSE), $filter);

		return $this->_respond([
			'ok'      => TRUE,
			'voices'  => $voices,
			'genders' => Service_Nj666_Multivoice::genders(),
			'ages'    => Service_Nj666_Multivoice::ages(),
		]);
	}

	/** POST /multivoice/api/delete — удалить книгу (мягко в БД, файлы сразу). */
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

		$done = Service_Nj666_Multivoice::delete((string) Arr::get($this->_body, 'code', ''),
			$user ? (int) $user->id : 0,
			class_exists('Visitor') ? (string) Visitor::uid() : '');

		return $this->_respond($done, Arr::get($done, 'ok') ? 200 : 404);
	}

	// ------------------------------------------------------------------

	/**
	 * Общая проверка: аккаунт + CSRF. NULL — всё в порядке.
	 *
	 * $need_run=TRUE добавляет право nj666.start: запуск стоит денег, а
	 * правка своей книги (персонажи, настройки, удаление) доступна любому
	 * владельцу — иначе человек не мог бы даже допилить уже готовую книгу.
	 */
	protected function _gate($need_run = FALSE)
	{
		if ( ! Gate::logged_in())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Озвучка книги доступна по аккаунту. Войдите на сайт и повторите запрос.',
			], 401);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		if ($need_run AND ! Service_Nj666_Multivoice::can_start(Auth::instance()->get_user()))
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Запускать разбор и озвучку могут привилегированные аккаунты '
					.'(роль vip) и администраторы: книга это десятки вызовов модели и часы синтеза.',
			], 403);
		}

		if ( ! Service_Nj666_Multivoice::rate_ok())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Слишком часто. Лимит: '.Service_Nj666_Multivoice::rate_text().'.',
			], 429);
		}

		return NULL;
	}

	/** Книга по коду из запроса + право на неё. Массив — ок, иначе ответ. */
	protected function _book($need_run = FALSE)
	{
		$gate = $this->_gate($need_run);

		if ($gate !== NULL)
		{
			return $gate;
		}

		$book = Service_Nj666_Multivoice::book((string) Arr::get($this->_body, 'code', ''));

		if ($book === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Книга не найдена.'], 404);
		}

		$user = Auth::instance()->get_user();
		$uid  = class_exists('Visitor') ? (string) Visitor::uid() : '';

		if ( ! Service_Nj666_Multivoice::owns($book, $user ? (int) $user->id : 0, $uid))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Это чужая книга.'], 403);
		}

		return $book;
	}

	protected function _respond(array $data, $status = 200)
	{
		return $this->response->status((int) $status)
			->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
