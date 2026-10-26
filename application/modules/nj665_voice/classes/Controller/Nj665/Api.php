<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-665 «Озвучивание текста» — JSON API.
 *
 *   POST /voice/api/synthesize  — синтез (CSRF, аккаунт, лимитер);
 *   GET  /voice/api/voices      — справочник: модели, голоса, форматы,
 *                                 умолчания, лимиты (ключ не нужен —
 *                                 это данные самого сервиса, не внешнего API);
 *   GET  /voice/api/history     — последние озвучки этого посетителя;
 *   GET  /voice/api/key         — состояние ключа внешнего API (администратор).
 *
 * Для агентов справочник важнее документации: голоса и форматы живут на
 * стороне OmniVoice и меняются вместе с моделью, поэтому брать их надо
 * отсюда, а не из README.
 */
class Controller_Nj665_Api extends Controller
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

	/** POST /voice/api/synthesize — озвучить текст. */
	public function action_synthesize()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		if ( ! Gate::logged_in())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Озвучивание доступно по аккаунту. Войдите на сайт и повторите запрос.',
			], 401);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		if ( ! Service_Nj665_Voice::rate_ok())
		{
			return $this->_respond([
				'ok'    => FALSE,
				'error' => 'Слишком часто. Лимит: '.Service_Nj665_Voice::rate_text().'.',
			], 429);
		}

		$user = Auth::instance()->get_user();

		// user_id кладём и в тело: по нему сервис помечает неудачные попытки
		// в журнале (успешные он получает отдельным аргументом).
		$this->_body['user_id'] = $user ? (int) $user->id : 0;

		$result = Service_Nj665_Voice::synthesize($user ? (int) $user->id : 0, $this->_body);

		if ( ! $result['ok'])
		{
			if (class_exists('Log_Plain'))
			{
				Log_Plain::event(Log::ERROR, 'nj665: синтез не удался: :e',
					[':e' => mb_substr((string) Arr::get($result, 'error', ''), 0, 200)]);
			}

			return $this->_respond($result, 422);
		}

		return $this->_respond($result);
	}

	/** GET /voice/api/voices — модели, голоса, форматы, лимиты. */
	public function action_voices()
	{
		$refresh = strtolower((string) Arr::get($this->_body, 'refresh', '')) === '1';
		$models  = Service_Nj665_Voice::speech_models($refresh);
		$model   = trim((string) Arr::get($this->_body, 'model', ''));

		// Модель можно спросить явно: страница подменяет список голосов и
		// форматов при смене модели, не перезагружаясь.
		if ($model === '' OR Service_Nj665_Voice::model($model) === NULL)
		{
			$model = Service_Nj665_Voice::default_model();
		}

		return $this->_respond([
			'ok'        => TRUE,
			'enabled'   => Service_Nj665_Voice::enabled(),
			'model'     => $model,
			'models'    => $models,
			'voices'    => Service_Nj665_Voice::voices($model),
			'formats'   => Service_Nj665_Voice::formats($model),
			'languages' => Service_Nj665_Voice::languages(),
			'defaults'  => [
				'model'   => $model,
				'voice'   => Service_Nj665_Voice::default_voice($model),
				'format'  => Service_Nj665_Voice::default_format($model),
				'speed'   => Service_Nj665_Voice::clamp_speed((float) Arr::get(Service_Nj665_Voice::config(), 'default_speed', 1.0)),
				'language'=> (string) Arr::get(Service_Nj665_Voice::config(), 'default_language', ''),
			],
			'limits'    => Service_Nj665_Voice::limits(),
			'rate_text' => Service_Nj665_Voice::rate_text(),
			'logged'    => Gate::logged_in(),
		]);
	}

	/** GET /voice/api/history — последние озвучки этого посетителя. */
	public function action_history()
	{
		$user = Gate::logged_in() ? Auth::instance()->get_user() : NULL;

		return $this->_respond([
			'ok'      => TRUE,
			'history' => Service_Nj665_Voice::for_user(
				$user ? (int) $user->id : 0,
				class_exists('Visitor') ? (string) Visitor::uid() : '',
				(int) Arr::get($this->_body, 'limit', 20)),
		]);
	}

	/**
	 * POST /voice/api/delete — удалить свою озвучку.
	 *
	 * Вход не требуется: удаление разрешено владельцу записи, а им может быть
	 * и гость (тогда запись привязана к visitor_uid). Чужой код не пройдёт —
	 * проверяет Service_Nj665_Voice::owns().
	 */
	public function action_delete()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST.'], 405);
		}

		if ( ! Security::check((string) Arr::get($this->_body, 'csrf', '')))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Сессия устарела, обновите страницу.'], 403);
		}

		$user = Gate::logged_in() ? Auth::instance()->get_user() : NULL;

		$result = Service_Nj665_Voice::delete((string) Arr::get($this->_body, 'code', ''),
			$user ? (int) $user->id : 0,
			class_exists('Visitor') ? (string) Visitor::uid() : '');

		if ( ! $result['ok'])
		{
			return $this->_respond($result, 404);
		}

		return $this->_respond($result);
	}

	/** GET /voice/api/key — состояние ключа внешнего API (для администратора). */
	public function action_key()
	{
		if ( ! Gate::logged_in() OR ! Auth::instance()->logged_in('admin'))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только для администратора.'], 403);
		}

		$info = Service_Nj665_Voice::key_info((bool) Arr::get($this->_body, 'refresh', FALSE));

		// Объединять массивы знаком «+» нельзя: левый операнд забирает ключи
		// себе, и 'ok' из ответа сервиса перекрыл бы вычисленный.
		return $this->_respond([
			'ok'    => (bool) Arr::get($info, 'ok', FALSE),
			'data'  => Arr::get($info, 'data', []),
			'error' => Arr::get($info, 'error', ''),
		], Arr::get($info, 'ok') ? 200 : 502);
	}

	protected function _respond(array $data, $status = 200)
	{
		return $this->response->status((int) $status)
			->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
}
