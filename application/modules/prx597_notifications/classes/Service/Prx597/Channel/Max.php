<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Канал «MAX» (prx597) — мессенджер MAX.
 *
 * Два драйвера на выбор администратора (настройка 'max.driver'):
 *
 *   bot      — бот MAX. Токен из окружения (MAX_BOT_TOKEN, выдаёт @MasterBot).
 *              POST https://platform-api.max.ru/messages, заголовок
 *              Authorization: <токен>, тело {chat_id|user_id, text, format}.
 *              Получателя привязывает сам пользователь (/start <код> боту).
 *
 *   business — внешний провайдер рассылок: отправляем номер телефона и текст
 *              на его эндпоинт (MAX_BUSINESS_ENDPOINT + MAX_BUSINESS_KEY).
 *              Требует договора, зато не зависит от того, написал ли
 *              пользователь боту.
 *
 * Исторически в prx217 был драйвер «max-system.ru» с ключом в конфиге — он
 * не соответствует актуальному API MAX (сейчас platform-api.max.ru и токен
 * в заголовке), поэтому старый код не перенесён, а написан заново.
 */
class Service_Prx597_Channel_Max extends Service_Prx597_Channel
{
	/** Какой драйвер выбран администратором: bot или business. */
	public static function driver()
	{
		$driver = strtolower(trim((string) self::setting('max.driver', self::cfg('max_driver', 'bot'))));

		return $driver === 'business' ? 'business' : 'bot';
	}

	/** Канал работает, если настроен хотя бы один драйвер. */
	public static function enabled()
	{
		if ( ! parent::enabled())
		{
			return FALSE;
		}

		return self::driver() === 'business' ? self::_business_ready() : self::env('MAX_BOT_TOKEN') !== '';
	}

	/**
	 * Отправить сообщение в MAX.
	 *
	 * @param  array $message
	 * @return array ['ok' => TRUE, 'id' => …] | ['error' => string]
	 */
	public static function send(array $message)
	{
		$to = trim((string) Arr::get($message, 'destination', ''));

		if ($to === '')
		{
			return ['error' => 'Пользователь не привязал MAX'];
		}

		$text = self::_text($message);

		if ($text === '')
		{
			return ['error' => 'Пустое сообщение — отправлять нечего'];
		}

		return self::driver() === 'business'
			? self::_send_business($to, $text, $message)
			: self::_send_bot($to, $text, $message);
	}

	// ------------------------------------------------------------------- бот

	protected static function _send_bot($chat, $text, array $message)
	{
		$token = self::env('MAX_BOT_TOKEN');

		if ($token === '')
		{
			return ['error' => 'MAX_BOT_TOKEN не задан в окружении'];
		}

		$api = rtrim((string) self::cfg('max.api_url', 'https://platform-api.max.ru'), '/');

		// MAX принимает получателя как chat_id или user_id: числовой
		// идентификатор в обоих случаях, но бот знает chat_id (диалог),
		// а внешние системы чаще передают user_id.
		$payload = [
			'text'   => $text,
			'format' => 'HTML',
		];

		if (preg_match('~^\d+$~', $chat))
		{
			$payload['chat_id'] = (int) $chat;
		}
		else
		{
			$payload['user_id'] = $chat;
		}

		$res = self::_http($api.'/messages', 'POST', $payload, [
			'Authorization' => $token,
		], (int) self::cfg('max.timeout', 10), TRUE);

		if ($res['error'] !== '')
		{
			return ['error' => $res['error']];
		}

		$data = self::_json($res['body']);

		// Успех по документации: HTTP 200 и тело сообщения в ответе.
		if ($res['code'] >= 200 AND $res['code'] < 300)
		{
			$id = $data === NULL ? '' : (string) Arr::get(Arr::get($data, 'message', $data), 'message_id', '');

			return ['ok' => TRUE, 'id' => $id];
		}

		$err = $data === NULL
			? mb_substr($res['body'], 0, 200)
			: (string) Arr::get($data, 'message', Arr::get($data, 'error', 'без описания'));

		if ($res['code'] === 403)
		{
			return ['error' => 'Пользователь недоступен для бота (403): '.$err, 'blocked' => TRUE];
		}

		return ['error' => 'MAX (HTTP '.$res['code'].'): '.$err];
	}

	// --------------------------------------------------------------- бизнес-API

	protected static function _business_ready()
	{
		return self::_business_endpoint() !== '' AND self::env('MAX_BUSINESS_KEY') !== '';
	}

	protected static function _business_endpoint()
	{
		$endpoint = trim((string) self::env('MAX_BUSINESS_ENDPOINT'));

		if ($endpoint === '')
		{
			$endpoint = trim((string) self::cfg('max.business.endpoint', ''));
		}

		return $endpoint;
	}

	protected static function _send_business($phone, $text, array $message)
	{
		if ( ! self::_business_ready())
		{
			return ['error' => 'Драйвер business не настроен: нужны MAX_BUSINESS_ENDPOINT и MAX_BUSINESS_KEY'];
		}

		$res = self::_http(self::_business_endpoint(), 'POST', [
			'key'      => self::env('MAX_BUSINESS_KEY'),
			'phone'    => $phone,
			'subject'  => self::_subject($message),
			'message'  => $text,
			'event'    => (string) Arr::get($message, 'event_alias', ''),
			'url'      => self::base_url().Service_Prx597_Notify::safe_href((string) Arr::get($message, 'href', '')),
			'time'     => time(),
		], [
			'X-Max-Auth' => self::env('MAX_BUSINESS_KEY'),
		], (int) self::cfg('max.timeout', 10), TRUE);

		if ($res['error'] !== '')
		{
			return ['error' => $res['error']];
		}

		$data = self::_json($res['body']);

		if ($res['code'] >= 200 AND $res['code'] < 300)
		{
			$ok = $data === NULL ? TRUE : (strtolower((string) Arr::get($data, 'status', 'ok')) !== 'error');

			return $ok
				? ['ok' => TRUE, 'id' => (string) Arr::get((array) $data, 'id', '')]
				: ['error' => 'Провайдер MAX вернул ошибку: '.(string) Arr::get($data, 'error', 'без описания')];
		}

		return ['error' => 'Провайдер MAX (HTTP '.$res['code'].'): '.mb_substr($res['body'], 0, 200)];
	}

	// ------------------------------------------------------------------ разное

	/** Тест канала: понятное сообщение administrator. */
	public static function test($destination, $text = NULL)
	{
		$text = $text !== NULL ? (string) $text
			: "Проверка канала MAX.\n\nЕсли сообщение дошло — привязка работает.";

		return self::send([
			'user_id'     => 0,
			'destination' => (string) $destination,
			'subject'     => 'Проверка MAX',
			'body'        => $text,
			'format'      => 'text',
			'href'        => '',
			'event_alias' => 'test',
			'priority'    => 1,
		]);
	}

	/** Проверить бота MAX: кто мы (для админки). */
	public static function whoami()
	{
		if (self::driver() === 'business')
		{
			return self::_business_ready()
				? ['ok' => TRUE, 'username' => 'business', 'name' => self::_business_endpoint()]
				: ['error' => 'Провайдер бизнес-API не настроен'];
		}

		$token = self::env('MAX_BOT_TOKEN');

		if ($token === '')
		{
			return ['error' => 'MAX_BOT_TOKEN не задан'];
		}

		$api = rtrim((string) self::cfg('max.api_url', 'https://platform-api.max.ru'), '/');
		$res = self::_http($api.'/me', 'GET', NULL, ['Authorization' => $token], 10, FALSE);

		if ($res['error'] !== '')
		{
			return ['error' => $res['error']];
		}

		$data = self::_json($res['body']);

		if ($data === NULL)
		{
			return ['error' => 'MAX вернул не JSON (HTTP '.$res['code'].')'];
		}

		return [
			'ok'       => TRUE,
			'username' => (string) Arr::get($data, 'username', ''),
			'name'     => (string) Arr::get($data, 'name', Arr::get($data, 'first_name', '')),
		];
	}

	/** Текст сообщения: тема жирным, тело, ссылка. HTML в MAX разрешён. */
	protected static function _text(array $message)
	{
		$subject = trim((string) Arr::get($message, 'subject', ''));
		$body    = trim((string) Arr::get($message, 'body', ''));

		if ((string) Arr::get($message, 'format', 'text') !== 'html')
		{
			$body = HTML::chars($body, TRUE);
		}

		$out = '';

		if ($subject !== '')
		{
			$out .= '<b>'.HTML::chars($subject, TRUE).'</b>';

			if ($body !== '')
			{
				$out .= "\n\n";
			}
		}

		$out .= $body;

		$href = Service_Prx597_Notify::safe_href((string) Arr::get($message, 'href', ''));

		if ($href !== '' AND $href !== '/prx597')
		{
			$url = self::base_url().$href;
			$out .= "\n\n".'<a href="'.HTML::chars($url, TRUE).'">Открыть</a>';
		}

		return Service_Prx597_Render::clip(trim($out), 4000);
	}
}
