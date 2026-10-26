<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API конструктора архитектур (NJ-660).
 *
 *   POST /arch/api/evaluate  {"dsl": {...}}   → метрики (локальный счёт,
 *        без LLM — можно дёргать на каждое нажатие; публично);
 *   POST /arch/api/simulate  {"code": "...", "prompt": "...",
 *        "history": [...]}                    → ответ «ожившей» модели
 *        (требуется вход; мягкий лимит в час).
 */
class Controller_Nj660_Api extends Controller {

	public $auto_render = FALSE;

	public function before()
	{
		parent::before();
		$this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
	}

	/** POST /arch/api/evaluate — калькулятор: DSL → все метрики. */
	public function action_evaluate()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		$body = $this->_body();
		$dsl = (array) Arr::get($body, 'dsl', []);

		if (strlen((string) json_encode($dsl)) > 200000)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'DSL слишком большой'], 413);
		}

		$eval = Service_Nj660_Arch::evaluate($dsl);

		if (isset($eval['errors']))
		{
			return $this->_respond(['ok' => FALSE, 'errors' => $eval['errors']], 422);
		}

		// flat не нужен фронту (в нём развёрнутый стек) — убираем
		unset($eval['flat']);

		return $this->_respond(['ok' => TRUE] + $eval);
	}

	/** POST /arch/api/simulate — диалог с «ожившей» спроектированной моделью. */
	public function action_simulate()
	{
		if ($this->request->method() !== Request::POST)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
		}

		if ( ! Auth::instance()->logged_in())
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Симуляция доступна после входа', 'login' => '/auth/login'], 401);
		}

		$body = $this->_body();
		$code = (string) Arr::get($body, 'code', '');
		$prompt = mb_substr(trim((string) Arr::get($body, 'prompt', '')), 0, 2000);

		if ($prompt === '')
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Пустой промпт'], 422);
		}

		$f = 'Service_Nj660_Arch';
		$design = $f::find($code);

		if ($design === NULL)
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Проект не найден'], 404);
		}

		$cfg = (array) Arr::get($f::config(), 'llm', []);

		if ($f::runs_recent(Visitor::uid(), 'simulate') >= (int) Arr::get($cfg, 'simulate_per_hour', 20))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'Лимит симуляций: '.Arr::get($cfg, 'simulate_per_hour', 20).' в час — чуть позже'], 429);
		}

		$dsl = json_decode((string) $design['dsl'], TRUE);
		$eval = $f::evaluate(is_array($dsl) ? $dsl : []);

		if (isset($eval['errors']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => 'DSL проекта невалиден'], 422);
		}

		// история: только role/content, обрезаем до разумного размера
		$history = [];

		foreach ((array) Arr::get($body, 'history', []) as $msg)
		{
			$role = Arr::get($msg, 'role') === 'assistant' ? 'assistant' : 'user';
			$content = mb_substr(trim((string) Arr::get($msg, 'content', '')), 0, 2000);

			if ($content !== '')
			{
				$history[] = ['role' => $role, 'content' => $content];
			}
		}

		$result = Service_Nj660_Expert::simulate($design, $eval, $prompt, $history);

		$user_id = Auth::instance()->get_user() ? (int) Auth::instance()->get_user()->id : NULL;
		$f::log_run((int) $design['id'], 'simulate', Visitor::uid(), $user_id,
			$prompt, (string) Arr::get($result, 'model', ''), (int) Arr::get($result, 'ms', 0),
			(string) Arr::get($result, 'answer', ''), (string) Arr::get($result, 'error', ''));

		if (empty($result['ok']))
		{
			return $this->_respond(['ok' => FALSE, 'error' => Arr::get($result, 'error', 'движок не ответил')], 502);
		}

		return $this->_respond([
			'ok'     => TRUE,
			'answer' => (string) $result['answer'],
			'model'  => (string) $result['model'],
			'ms'     => (int) $result['ms'],
		]);
	}

	// ------------------------------------------------------------------

	/** Тело запроса: JSON или form-data. */
	protected function _body()
	{
		$raw = $this->request->body();

		if ($raw)
		{
			$json = json_decode($raw, TRUE);

			if (is_array($json))
			{
				return $json;
			}
		}

		return $_POST;
	}

	protected function _respond(array $data, $status = 200)
	{
		$this->response->status((int) $status);
		$this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
	}
}
