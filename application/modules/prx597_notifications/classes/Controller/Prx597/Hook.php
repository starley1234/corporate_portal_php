<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Вебхуки ботов (prx597): /prx597/hook/telegram и /prx597/hook/max.
 *
 * Сюда мессенджер присылает обновления, из них мы достаём «/start <код>» и
 * подтверждаем привязку аккаунта (Service_Prx597_Bind).
 *
 * Правила:
 *   * доступ — по секрету из адреса (?secret=…) или из заголовка
 *     (Telegram шлёт X-Telegram-Bot-Api-Secret-Token, который мы сами и
 *     задали при регистрации вебхука). Секрет лежит в prx_597_bots;
 *   * отвечаем быстро и всегда 200: бот считает неудачный ответ поводом
 *     прислать обновление ещё раз, а нам повтор не нужен;
 *   * режим бота выключен (mode = off) — обновление молча игнорируем;
 *   * вебхук не должен ронять площадку: всё в try/catch.
 */
class Controller_Prx597_Hook extends Controller
{
	/** Каналы, для которых принимаем обновления. */
	const CHANNELS = ['telegram', 'max'];

	public function before()
	{
		parent::before();

		$this->auto_render = FALSE;
		$this->response->headers('Content-Type', 'application/json; charset=utf-8');
	}

	/** POST /prx597/hook/telegram. */
	public function action_telegram()
	{
		$this->_handle('telegram');
	}

	/** POST /prx597/hook/max. */
	public function action_max()
	{
		$this->_handle('max');
	}

	/**
	 * Общий разбор вебхука.
	 *
	 * @param string $channel
	 */
	protected function _handle($channel)
	{
		if ( ! in_array($channel, self::CHANNELS, TRUE))
		{
			$this->response->status(404);
			$this->response->body(json_encode(['error' => 'Неизвестный канал'], JSON_UNESCAPED_UNICODE));

			return;
		}

		try
		{
			Service_Prx597_Notify::ensure_tables();

			if (Service_Prx597_Bind::mode($channel) === 'off')
			{
				$this->_answer(['ok' => TRUE, 'note' => 'приём обновлений выключен']);

				return;
			}

			$given = (string) ($this->request->query('secret')
				?: $this->request->headers('X-Telegram-Bot-Api-Secret-Token')
				?: $this->request->headers('X-Max-Secret'));

			if ( ! Service_Prx597_Bind::secret_ok($channel, $given))
			{
				// Чужой запрос: в лог и 403 — чтобы бот/провайдер не долбил нас
				// бесконечно с неверным секретом.
				$this->_log(Log::WARNING, 'вебхук :ch: секрет не совпал', [':ch' => $channel]);
				$this->response->status(403);
				$this->response->body(json_encode(['error' => 'Секрет не совпал'], JSON_UNESCAPED_UNICODE));

				return;
			}

			$raw     = (string) $this->request->body();
			$update  = json_decode($raw, TRUE);

			if ( ! is_array($update))
			{
				$this->_answer(['ok' => TRUE, 'note' => 'пустое или не JSON']);

				return;
			}

			$result = Service_Prx597_Bind::handle_update($channel, $update);

			// Отвечаем боту понятным сообщением: человеку должно быть видно,
			// привязалось или нет.
			if ( ! empty($result['ok']))
			{
				$this->_answer(['ok' => TRUE, 'bound' => TRUE, 'channel' => $channel, 'reply' => self::REPLY_OK]);
			}
			elseif (isset($result['error']))
			{
				$this->_answer(['ok' => TRUE, 'bound' => FALSE, 'error' => $result['error'], 'reply' => $result['error']]);
			}
			else
			{
				$this->_answer(['ok' => TRUE, 'note' => (string) Arr::get($result, 'note', ''), 'reply' => self::REPLY_HINT]);
			}
		}
		catch (Throwable $e)
		{
			$this->_log(Log::ERROR, 'вебхук :ch: :e', [':ch' => $channel, ':e' => $e->getMessage()]);

			// Мессенджеру всё равно отвечаем 200: иначе он будет слать то же
			// обновление снова и снова.
			$this->_answer(['ok' => TRUE, 'error' => 'внутренняя ошибка']);
		}
	}

	/** Что бот отвечает человеку. */
	const REPLY_OK   = 'Готово: уведомления подключены. Настроить их можно в центре уведомлений.';
	const REPLY_HINT = 'Напишите /start <код>, код выдаётся на странице уведомлений.';

	/** Ответ 200 с JSON (мессенджеры не любят ничего, кроме 200). */
	protected function _answer(array $payload)
	{
		$this->response->status(200);
		$this->response->body(json_encode($payload, JSON_UNESCAPED_UNICODE));
	}

	protected function _log($level, $message, array $values = [])
	{
		try
		{
			Kohana::$log->add($level, 'prx597 hook: '.$message, $values);
		}
		catch (Throwable $e)
		{
			// лог недоступен — не беда
		}
	}
}
