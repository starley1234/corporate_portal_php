<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Базовый класс канала доставки (nj597).
 *
 * Канал — это «как именно ушло сообщение»: письмом, в Telegram, в MAX или
 * просто строкой в колокольчике. Бизнес-модуль не знает, какие каналы есть в
 * природе: он заявляет событие, а дальше центр сам решает, куда нести.
 *
 * Контракт канала:
 *   * enabled()     — можно ли им пользоваться (настроен ли токен/почта);
 *   * destination() — адрес доставки конкретного пользователя (из nj_597_channels);
 *   * send()        — отправка; возвращает ['ok' => TRUE] или ['error' => 'текст'];
 *   * test()        — пробное сообщение из админки.
 *
 * Канал НИКОГДА не должен бросать исключение наружу: упавший канал — это
 * причина повтора, а не сломанный запрос пользователя. Исключение ловим в
 * send() и превращаем в ['error' => …].
 */
abstract class Service_Nj597_Channel extends Service_Nj597_Base
{
	/** Имя канала в реестре (из имени класса: Channel_Email → email). */
	public static function id()
	{
		$name = get_called_class();
		$tail = substr($name, strrpos($name, '_') + 1);

		return $tail === 'Inapp' ? 'in_app' : strtolower($name === $tail ? '' : $tail);
	}

	/** Подпись канала из конфига. */
	public static function label()
	{
		return self::channel_label(self::id());
	}

	/** Иконка канала. */
	public static function icon()
	{
		return self::channel_icon(self::id());
	}

	/** Нужна ли привязка адреса у пользователя. */
	public static function needs_binding()
	{
		$channels = self::channels(FALSE);

		return self::_truthy(Arr::get(Arr::get($channels, self::id(), []), 'bind', FALSE));
	}

	/** Включён ли канал в конфиге. */
	public static function enabled()
	{
		$channels = self::channels(FALSE);
		$info     = Arr::get($channels, self::id(), []);

		return self::_truthy(Arr::get($info, 'enabled', TRUE));
	}

	/**
	 * Адрес доставки пользователя (почта, chat_id, пользователь MAX).
	 * Пустая строка — канал не привязан: движок такое сообщение пропустит.
	 */
	public static function destination($user_id)
	{
		$row = self::binding($user_id);

		if ($row === NULL OR $row['status'] !== 'active')
		{
			return '';
		}

		return (string) $row['destination'];
	}

	/** Запись привязки пользователя к этому каналу (или NULL). */
	public static function binding($user_id)
	{
		try
		{
			$row = self::_one('SELECT * FROM "nj_597_channels"
			                    WHERE "user_id" = '.((int) $user_id).'
			                      AND "channel" = '.self::_q(self::id()).' LIMIT 1');
		}
		catch (Throwable $e)
		{
			return NULL;
		}

		if ( ! $row)
		{
			return NULL;
		}

		return [
			'id'          => (int) $row['id'],
			'user_id'     => (int) $row['user_id'],
			'channel'     => (string) $row['channel'],
			'destination' => (string) $row['destination'],
			'external_id' => (string) Arr::get($row, 'external_id', ''),
			'status'      => (string) $row['status'],
			'title'       => (string) Arr::get($row, 'title', ''),
			'verified_at' => (string) Arr::get($row, 'verified_at', ''),
		];
	}

	/**
	 * Отправка сообщения.
	 *
	 * @param  array $message ['user_id', 'destination', 'subject', 'body',
	 *                         'format', 'href', 'event_alias', 'priority']
	 * @return array ['ok' => TRUE, 'id' => …] или ['error' => 'текст']
	 */
	abstract public static function send(array $message);

	/**
	 * Пробное сообщение из админки: тот же send(), но с понятным текстом
	 * и без записи в журнал. Переопределяется, если у канала свой формат.
	 */
	public static function test($destination, $text = NULL)
	{
		$text = $text !== NULL ? (string) $text : 'Пробное сообщение центра уведомлений N2J.ru — канал '.self::label().' настроен верно.';

		return self::send([
			'user_id'     => 0,
			'destination' => (string) $destination,
			'subject'     => 'Проверка канала: '.self::label(),
			'body'        => $text,
			'format'      => 'text',
			'href'        => '',
			'event_alias' => 'test',
			'priority'    => 1,
		]);
	}

	// -------------------------------------------------------------- помощники

	/** Тело сообщения, приведённое к формату канала. */
	protected static function _body(array $message, $mode = 'text')
	{
		$body = (string) Arr::get($message, 'body', '');

		if ($body === '')
		{
			return '';
		}

		if ((string) Arr::get($message, 'format', 'text') === 'html' AND $mode === 'text')
		{
			$body = Service_Nj597_Render::html_to_text($body);
		}

		$href = trim((string) Arr::get($message, 'href', ''));

		// Ссылка «открыть» — часть сообщения, а не отдельное поле: в мессенджерах
		// и в письме её место в конце текста.
		if ($href !== '' AND mb_strpos($body, $href) === FALSE)
		{
			$href = Service_Nj597_Notify::safe_href($href);
			$url  = self::base_url().$href;
			$body = rtrim($body)."\n\n".($mode === 'html'
				? '<a href="'.HTML::chars($url, TRUE).'">Открыть</a>'
				: 'Открыть: '.$url);
		}

		return Service_Nj597_Render::clip($body, self::_body_limit());
	}

	/** Лимит длины тела для канала (переопределяется). */
	protected static function _body_limit()
	{
		return 4000;
	}

	/** Заголовок сообщения (для каналов без темы — первая строка тела). */
	protected static function _subject(array $message)
	{
		$subject = trim((string) Arr::get($message, 'subject', ''));

		if ($subject === '')
		{
			$subject = self::_first_line((string) Arr::get($message, 'body', ''));
		}

		// «Одна мысль»: без «Уведомление:», без переводов строк, до 60 знаков.
		return Service_Nj597_Render::subject_line($subject);
	}

	protected static function _first_line($text)
	{
		$line = strtok((string) $text, "\n");

		return Service_Nj597_Render::clip(trim((string) $line), 120);
	}

	/**
	 * Универсальный HTTP-запрос каналов: POST/GET с JSON или формой.
	 * curl в проекте используется повсеместно — не тянем новый HTTP-клиент.
	 *
	 * @return array ['code' => int, 'body' => string, 'error' => string]
	 */
	protected static function _http($url, $method = 'POST', $payload = NULL, array $headers = [], $timeout = 10, $as_json = TRUE)
	{
		if ( ! function_exists('curl_init'))
		{
			return ['code' => 0, 'body' => '', 'error' => 'Расширение curl не установлено'];
		}

		$ch = curl_init();

		$options = [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT        => (int) $timeout,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_SSL_VERIFYPEER => TRUE,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HEADER         => FALSE,
		];

		if ($method === 'POST')
		{
			$options[CURLOPT_POST] = TRUE;
			$options[CURLOPT_POSTFIELDS] = $as_json
				? json_encode($payload, JSON_UNESCAPED_UNICODE)
				: (is_array($payload) ? http_build_query($payload) : (string) $payload);
		}
		elseif ($payload !== NULL AND is_array($payload))
		{
			$url .= (strpos($url, '?') === FALSE ? '?' : '&').http_build_query($payload);
			$options[CURLOPT_URL] = $url;
		}

		$http_headers = [];

		foreach ($headers as $name => $value)
		{
			$http_headers[] = $name.': '.$value;
		}

		if ($as_json AND $method === 'POST')
		{
			$http_headers[] = 'Content-Type: application/json';
		}

		$http_headers[] = 'Accept: application/json';
		$http_headers[] = 'User-Agent: N2J-Notification-Center/1.0';

		$options[CURLOPT_HTTPHEADER] = $http_headers;

		curl_setopt_array($ch, $options);

		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);

		curl_close($ch);

		if ($body === FALSE OR $err !== '')
		{
			return ['code' => $code, 'body' => '', 'error' => 'Сетевая ошибка: '.($err !== '' ? $err : 'пустой ответ')];
		}

		return ['code' => $code, 'body' => (string) $body, 'error' => ''];
	}

	/** Разбор JSON-ответа провайдера: массив или NULL. */
	protected static function _json($body)
	{
		$data = json_decode((string) $body, TRUE);

		return is_array($data) ? $data : NULL;
	}
}
