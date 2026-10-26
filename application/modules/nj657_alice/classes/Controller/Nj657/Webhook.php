<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-657: вебхук Яндекс.Диалогов — POST /alice/webhook и /alice/webhook/<токен>.
 *
 * Точка входа для Алисы: принимает JSON протокола 1.0, отдаёт JSON-ответ.
 * Контроллер тонкий — вся логика в Service_Nj657_Alice::handle().
 *
 * Адрес для консоли Диалогов: https://n2j.ru/alice/webhook
 */
class Controller_Nj657_Webhook extends Controller {

	public $auto_render = FALSE;

	public function before()
	{
		parent::before();

		$this->response->headers('Content-Type', 'application/json; charset=UTF-8');
	}

	public function after()
	{
		parent::after();
	}

	/**
	 * POST /alice/webhook(/<token>).
	 *
	 * GET без тела отвечает 405 с подсказкой — удобно проверять адрес
	 * браузером, не изображая запрос Яндекса.
	 */
	public function action_index()
	{
		if ($this->request->method() !== Request::POST)
		{
			$this->_json(405, [
				'error' => 'Только POST: сюда обращается платформа Яндекс.Диалогов',
			]);

			return;
		}

		// Алиса ждёт ответ быстро: не даём PHP-таймауту убить запрос раньше
		// отдачи заготовленного запасного ответа.
		if (function_exists('set_time_limit'))
		{
			@set_time_limit(30);
		}

		$data = json_decode((string) $this->request->body(), TRUE);

		if ( ! is_array($data))
		{
			$this->_json(400, ['error' => 'Тело запроса — JSON объекта запроса Алисы']);

			return;
		}

		$token = (string) $this->request->param('token', '');

		try
		{
			$result = Service_Nj657_Alice::handle($data, $token, NULL, 'yandex');
		}
		catch (Throwable $e)
		{
			// Вебхук не имеет права падать: Алисе нужен валидный JSON-ответ.
			Log_Plain::event(Log::ERROR, 'alice: вебхук упал: :e',
				[':e' => get_class($e).': '.mb_substr($e->getMessage(), 0, 300)]);

			$this->_json(200, [
				'version'  => '1.0',
				'session'  => [
					'session_id' => (string) Arr::path($data, 'session.session_id', ''),
					'message_id' => (int) Arr::path($data, 'session.message_id', 0),
					'user_id'    => (string) Arr::path($data, 'session.user_id', ''),
				],
				'response' => [
					'text'       => 'Внутренняя ошибка навыка. Попробуйте ещё раз.',
					'tts'        => 'Внутренняя ошибка навыка. Попробуйте ещё раз.',
					'buttons'    => [],
					'end_session' => FALSE,
				],
			]);

			return;
		}

		$this->response->status((int) Arr::get($result, 'http', 200));
		$this->response->body(json_encode($result['response'], JSON_UNESCAPED_UNICODE));
	}

	/** Ответ JSON с нужным кодом. */
	protected function _json($code, array $payload)
	{
		$this->response->status($code);
		$this->response->body(json_encode($payload, JSON_UNESCAPED_UNICODE));
	}

}
