<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Привязка каналов пользователя (prx597): почта, Telegram, MAX.
 *
 * Чтобы прислать человеку сообщение в мессенджер, нужно знать его chat_id.
 * Кладём его в базу мы не можем (никто не вводит такие числа руками), поэтому
 * работает связка «код — бот»:
 *
 *   1. пользователь в центре уведомлений жмёт «Привязать Telegram»;
 *   2. модуль выдаёт одноразовый код и ссылку на бота с этим кодом;
 *   3. человек открывает бота и пишет /start <код>;
 *   4. бот зовёт наш вебхук (или мы забираем обновления опросом), мы находим
 *      код и превращаем его в активную привязку: chat_id → user_id.
 *
 * Вебхук — основной путь (мгновенно). Опрос — страховка: если вебхук не
 * настроен, «Пиналка» (prx603) дёргает /prx597/api/outbox раз в минуту (или как
 * настроит администратор) и забирает обновления сама.
 */
class Service_Prx597_Bind extends Service_Prx597_Base
{
	/** Сколько живёт код привязки, секунд. */
	const CODE_TTL = 900;

	/** Из каких символов собираем код (без похожих 0/O/1/I). */
	const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

	// ------------------------------------------------------------------- коды

	/**
	 * Выдать пользователю код привязки канала.
	 *
	 * @return array ['code' => …, 'url' => …, 'channel' => …] или ['error' => …]
	 */
	public static function start($user_id, $channel)
	{
		$user_id = (int) $user_id;
		$channel = self::_channel($channel);

		if ($user_id <= 0 OR $channel === '')
		{
			return ['error' => 'Не указан пользователь или канал'];
		}

		if ( ! array_key_exists($channel, Service_Prx597_Base::user_channels()))
		{
			return ['error' => 'Канал '.Service_Prx597_Registry::channel_label($channel).' отключён'];
		}

		$code = self::_new_code();
		$db   = self::_db();

		// Код одноразовый: новая попытка обнуляет старую привязку канала,
		// поэтому чужой chat_id не останется привязанным к пользователю.
		$db->query(Database::INSERT,
			'INSERT INTO "prx_597_channels" ("user_id", "channel", "destination", "status", "bind_code", "created_at", "updated_at")
			 VALUES ('.$user_id.', '.$db->quote($channel).', \'\', \'pending\', '.$db->quote($code).', now(), now())
			 ON CONFLICT ("user_id", "channel") DO UPDATE SET
			     "status" = \'pending\', "bind_code" = EXCLUDED."bind_code",
			     "destination" = \'\', "external_id" = NULL, "verified_at" = NULL, "updated_at" = now()',
			FALSE);

		return [
			'code'    => $code,
			'channel' => $channel,
			'url'     => self::bot_url($channel, $code),
			'expires_at' => date('c', time() + self::CODE_TTL),
		];
	}

	/**
	 * Подтвердить привязку: найти код, привязать адрес.
	 *
	 * @param  string $channel
	 * @param  string $code        код из сообщения боту
	 * @param  string $destination chat_id / телефон / что принимает канал
	 * @param  string $external_id исходный идентификатор в мессенджере
	 * @return array ['ok' => TRUE, 'user_id' => …] | ['error' => …]
	 */
	public static function confirm($channel, $code, $destination, $external_id = '')
	{
		$channel = self::_channel($channel);
		$code    = strtolower(trim((string) $code));

		if ($channel === '' OR $code === '' OR trim((string) $destination) === '')
		{
			return ['error' => 'Не хватает данных для привязки'];
		}

		$row = self::_one('SELECT * FROM "prx_597_channels"
		                    WHERE "bind_code" = '.self::_q($code).'
		                      AND "channel" = '.self::_q($channel).'
		                      AND "updated_at" > now() - interval \''.self::CODE_TTL.' seconds\'
		                    LIMIT 1');

		if ( ! $row)
		{
			return ['error' => 'Код не найден или устарел — запросите новый в центре уведомлений'];
		}

		$db = self::_db();
		$db->query(Database::UPDATE,
			'UPDATE "prx_597_channels" SET
			     "status" = \'active\',
			     "destination" = '.$db->quote(self::_cut($destination, 200)).',
			     "external_id" = '.$db->quote(self::_cut($external_id, 80)).',
			     "title" = '.$db->quote(self::_cut($external_id !== '' ? $external_id : $destination, 120)).',
			     "bind_code" = NULL,
			     "verified_at" = now(),
			     "updated_at" = now()
			 WHERE "id" = '.((int) $row['id']), FALSE);

		self::_log(Log::INFO, 'канал :ch привязан к пользователю :u',
			[':ch' => $channel, ':u' => (int) $row['user_id']]);

		self::_confirm_message($channel, $destination);

		return ['ok' => TRUE, 'user_id' => (int) $row['user_id'], 'channel' => $channel];
	}


	/**
	 * Отправить человеку подтверждение в тот же чат. Неудача отправки никак
	 * не влияет на результат привязки: связка уже сохранена.
	 */
	protected static function _confirm_message($channel, $destination)
	{
		$driver = Service_Prx597_Base::channel_driver($channel);

		if ( ! class_exists($driver))
		{
			return;
		}

		try
		{
			call_user_func([$driver, 'send'], [
				'user_id'     => 0,
				'destination' => (string) $destination,
				'subject'     => 'Уведомления подключены',
				'body'        => "Готово: уведомления PRX.ru подключены к этому чату.\n\n"
					."Что и куда присылать — настраивается в центре уведомлений: ".self::base_url()."/prx597",
				'format'      => 'text',
				'href'        => '/prx597',
				'event_alias' => 'system.bind',
				'priority'    => 1,
			]);
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'не удалось отправить подтверждение привязки :ch: :e',
				[':ch' => $channel, ':e' => $e->getMessage()]);
		}
	}

	/** Отвязать канал (пользователь или администратор). */
	public static function unbind($user_id, $channel)
	{
		$db = self::_db();
		$db->query(Database::UPDATE,
			'UPDATE "prx_597_channels" SET "status" = \'blocked\', "bind_code" = NULL, "updated_at" = now()
			 WHERE "user_id" = '.((int) $user_id).' AND "channel" = '.self::_q(self::_channel($channel)), FALSE);

		return TRUE;
	}

	/** Ссылка на бота с кодом привязки. */
	public static function bot_url($channel, $code)
	{
		$channel = self::_channel($channel);

		if ($channel === 'telegram')
		{
			$nick = ltrim(self::env('TELEGRAM_BOT_NICK'), '@');

			return $nick !== '' ? 'https://t.me/'.$nick.'?start='.rawurlencode($code) : '';
		}

		if ($channel === 'max')
		{
			$nick = self::env('MAX_BOT_NICK');

			if ($nick === '')
			{
				return '';
			}

			$pattern = (string) self::cfg('max.bot_link', 'https://max.me/{nick}?start={code}');

			return strtr($pattern, ['{nick}' => $nick, '{code}' => rawurlencode($code)]);
		}

		return '';
	}

	// ---------------------------------------------------------------- вебхуки

	/** Полный адрес вебхука для регистрации в боте (показываем в админке). */
	public static function webhook_url($channel)
	{
		$channel = self::_channel($channel);
		$secret  = self::secret($channel);

		if ($secret === '')
		{
			return '';
		}

		return self::base_url().'/prx597/hook/'.$channel.'?secret='.rawurlencode($secret);
	}

	/** Секрет вебхука канала (создаётся при первом обращении). */
	public static function secret($channel)
	{
		$channel = self::_channel($channel);

		try
		{
			$row = self::_one('SELECT * FROM "prx_597_bots" WHERE "channel" = '.self::_q($channel).' LIMIT 1');
		}
		catch (Throwable $e)
		{
			return '';
		}

		if ($row AND trim((string) $row['secret']) !== '')
		{
			return (string) $row['secret'];
		}

		$secret = self::_new_code(24);

		self::_exec(Database::INSERT,
			'INSERT INTO "prx_597_bots" ("channel", "mode", "secret", "updated_at")
			 VALUES ('.self::_q($channel).', \'webhook\', '.self::_q($secret).', now())
			 ON CONFLICT ("channel") DO UPDATE SET "secret" = EXCLUDED."secret", "updated_at" = now()');

		return $secret;
	}

	/** Проверить секрет вебхука (из заголовка или из адреса). */
	public static function secret_ok($channel, $given)
	{
		$expected = self::secret($channel);

		return $expected !== '' AND trim((string) $given) !== '' AND hash_equals($expected, (string) $given);
	}

	/** Режим работы бота: webhook | poll | off. */
	public static function mode($channel)
	{
		try
		{
			$value = self::_val('SELECT "mode" FROM "prx_597_bots" WHERE "channel" = '.self::_q(self::_channel($channel)), 'webhook');
		}
		catch (Throwable $e)
		{
			return 'webhook';
		}

		return in_array((string) $value, ['webhook', 'poll', 'off'], TRUE) ? (string) $value : 'webhook';
	}

	/** Переключить режим (админка). */
	public static function set_mode($channel, $mode)
	{
		if ( ! in_array($mode, ['webhook', 'poll', 'off'], TRUE))
		{
			return FALSE;
		}

		self::_exec(Database::INSERT,
			'INSERT INTO "prx_597_bots" ("channel", "mode", "secret", "updated_at")
			 VALUES ('.self::_q(self::_channel($channel)).', '.self::_q($mode).', '.self::_q(self::secret($channel)).', now())
			 ON CONFLICT ("channel") DO UPDATE SET "mode" = EXCLUDED."mode", "updated_at" = now()');

		return TRUE;
	}

	/**
	 * Разобрать входящее обновление бота и подтвердить привязку, если это
	 * команда /start с кодом.
	 *
	 * @param  string $channel
	 * @param  array  $update  разобранный JSON вебхука/опроса
	 * @return array ['ok' => TRUE, 'user_id' => …] | ['error' => …] | ['note' => …]
	 */
	public static function handle_update($channel, array $update)
	{
		$channel = self::_channel($channel);
		$text    = self::_update_text($update);

		if ($text === '')
		{
			return ['note' => 'обновление без текста'];
		}

		$code = self::_code_from_text($text);

		if ($code === '')
		{
			return ['note' => 'в сообщении нет кода'];
		}

		$chat = self::_update_chat($update);

		if ($chat === '')
		{
			return ['error' => 'не удалось понять, от кого сообщение'];
		}

		return self::confirm($channel, $code, $chat, self::_update_user($update));
	}

	/**
	 * Забрать обновления опросом (фолбэк, если вебхук не настроен).
	 *
	 * @return array ['ok' => TRUE, 'bound' => n, 'offset' => …] | ['error' => …]
	 */
	public static function poll($channel, $limit = 20)
	{
		$channel = self::_channel($channel);

		if ($channel !== 'telegram' AND $channel !== 'max')
		{
			return ['error' => 'Опрос нужен только мессенджерам'];
		}

		$state = self::_bot_state($channel);
		$updates = $channel === 'telegram' ? self::_telegram_updates($state, $limit) : self::_max_updates($state, $limit);

		if (isset($updates['error']))
		{
			self::_bot_error($channel, (string) $updates['error']);

			return $updates;
		}

		$bound = 0;
		$offset = (int) Arr::get($state, 'update_offset', 0);

		foreach ((array) Arr::get($updates, 'updates', []) as $update)
		{
			$update = (array) $update;
			$next   = (int) Arr::get($update, 'update_id', 0);

			if ($next > $offset)
			{
				$offset = $next;
			}

			$res = self::handle_update($channel, $update);

			if ( ! empty($res['ok']))
			{
				$bound++;
			}
		}

		self::_bot_offset($channel, $offset);

		return ['ok' => TRUE, 'bound' => $bound, 'offset' => $offset, 'seen' => count((array) Arr::get($updates, 'updates', []))];
	}

	// ------------------------------------------------------------- разборщики

	/** Обновления Telegram: getUpdates с курсором offset. */
	protected static function _telegram_updates(array $state, $limit = 20)
	{
		$token = self::env('TELEGRAM_BOT_TOKEN');

		if ($token === '')
		{
			return ['error' => 'TELEGRAM_BOT_TOKEN не задан'];
		}

		$api = rtrim((string) self::cfg('telegram.api_url', 'https://api.telegram.org/bot'), '/');

		$res = self::_http($api.$token.'/getUpdates', 'POST', [
			'offset'          => ((int) Arr::get($state, 'update_offset', 0)) + 1,
			'limit'           => (int) $limit,
			'timeout'         => 0,
			'allowed_updates' => ['message'],
		], [], 10, TRUE);

		if ($res['error'] !== '')
		{
			return ['error' => $res['error']];
		}

		$data = self::_json($res['body']);

		if ($data === NULL OR empty($data['ok']))
		{
			return ['error' => 'Telegram не отдал обновления: '.mb_substr($res['body'], 0, 200)];
		}

		return ['updates' => (array) Arr::get($data, 'result', [])];
	}

	/** Обновления MAX: GET /updates с маркером. */
	protected static function _max_updates(array $state, $limit = 20)
	{
		$token = self::env('MAX_BOT_TOKEN');

		if ($token === '')
		{
			return ['error' => 'MAX_BOT_TOKEN не задан'];
		}

		$api = rtrim((string) self::cfg('max.api_url', 'https://platform-api.max.ru'), '/');

		$query = ['limit' => (int) $limit, 'timeout' => 1];

		if ((int) Arr::get($state, 'update_offset', 0) > 0)
		{
			$query['marker'] = (int) $state['update_offset'];
		}

		$res = self::_http($api.'/updates', 'GET', $query, ['Authorization' => $token], 10, FALSE);

		if ($res['error'] !== '')
		{
			return ['error' => $res['error']];
		}

		$data = self::_json($res['body']);

		if ($data === NULL)
		{
			return ['error' => 'MAX не отдал обновления: '.mb_substr($res['body'], 0, 200)];
		}

		$marker = (int) Arr::get($data, 'marker', 0);

		if ($marker > 0)
		{
			// Курсор MAX — монотонный маркер; кладём его в то же поле.
			return ['updates' => (array) Arr::get($data, 'updates', []), 'marker' => $marker];
		}

		return ['updates' => (array) Arr::get($data, 'updates', [])];
	}

	/** Текст сообщения из обновления (Telegram и MAX кладут его в разные места). */
	protected static function _update_text(array $update)
	{
		$candidates = [
			['message', 'text'],
			['message', 'body', 'text'],
			['message', 'body'],
			['text'],
			['body', 'text'],
		];

		foreach ($candidates as $path)
		{
			$value = Arr::path($update, implode('.', $path), NULL);

			if (is_string($value) AND trim($value) !== '')
			{
				return trim($value);
			}
		}

		// Кнопка «Начать» в MAX приходит без текста, но с payload.
		$payload = Arr::path($update, 'message.body.payload', NULL);

		if ($payload === NULL)
		{
			$payload = Arr::path($update, 'payload', NULL);
		}

		return is_string($payload) ? trim($payload) : '';
	}

	/** Идентификатор чата/получателя из обновления. */
	protected static function _update_chat(array $update)
	{
		$candidates = [
			['message', 'chat', 'id'],
			['message', 'recipient', 'chat_id'],
			['message', 'recipient', 'user_id'],
			['chat_id'],
			['chat', 'id'],
		];

		foreach ($candidates as $path)
		{
			$value = Arr::path($update, implode('.', $path), NULL);

			if ((is_string($value) OR is_int($value)) AND (string) $value !== '')
			{
				return (string) $value;
			}
		}

		return '';
	}

	/** Пользователь в мессенджере (для подписи привязки). */
	protected static function _update_user(array $update)
	{
		$parts = [];

		foreach ([['message', 'from', 'username'], ['message', 'from', 'first_name'], ['message', 'sender', 'username'], ['message', 'sender', 'name']] as $path)
		{
			$value = Arr::path($update, implode('.', $path), NULL);

			if (is_string($value) AND trim($value) !== '')
			{
				$parts[] = trim($value);
			}
		}

		return $parts ? implode(' ', array_unique($parts)) : '';
	}

	/** Из текста «/start ABC123» или просто «ABC123» — код. */
	protected static function _code_from_text($text)
	{
		$text = trim((string) $text);

		if (preg_match('~^/start(?:@[\w_]+)?\s+([a-z0-9]{4,24})$~i', $text, $m))
		{
			return strtolower($m[1]);
		}

		if (preg_match('~\b([a-z0-9]{6,12})\b~i', $text, $m))
		{
			return strtolower($m[1]);
		}

		return '';
	}

	// ------------------------------------------------------------------ состояние

	protected static function _bot_state($channel)
	{
		try
		{
			$row = self::_one('SELECT * FROM "prx_597_bots" WHERE "channel" = '.self::_q($channel).' LIMIT 1');
		}
		catch (Throwable $e)
		{
			$row = NULL;
		}

		return $row === NULL ? ['channel' => $channel, 'update_offset' => 0] : $row;
	}

	protected static function _bot_offset($channel, $offset)
	{
		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_bots" SET "update_offset" = '.((int) $offset).', "last_poll_at" = now(),
			     "last_error" = NULL, "updated_at" = now()
			 WHERE "channel" = '.self::_q($channel));
	}

	protected static function _bot_error($channel, $error)
	{
		self::_exec(Database::UPDATE,
			'UPDATE "prx_597_bots" SET "last_error" = '.self::_q(self::_cut($error, 300)).',
			     "last_poll_at" = now(), "updated_at" = now()
			 WHERE "channel" = '.self::_q($channel));
	}

	// ---------------------------------------------------------------- помощники

	protected static function _channel($channel)
	{
		$channel = strtolower(trim((string) $channel));

		return preg_match('~^[a-z_]{2,20}$~', $channel) ? $channel : '';
	}

	/** Случайный код: короткий — для человека, длинный — для вебхука. */
	protected static function _new_code($len = 8)
	{
		$alphabet = self::ALPHABET;
		$max      = strlen($alphabet) - 1;
		$code     = '';

		for ($i = 0; $i < (int) $len; $i++)
		{
			try
			{
				$code .= $alphabet[random_int(0, $max)];
			}
			catch (Throwable $e)
			{
				$code .= $alphabet[mt_rand(0, $max)];
			}
		}

		return $code;
	}

	/** Тонкая обёртка HTTP (как в каналах, но без наследования Channel). */
	protected static function _http($url, $method = 'GET', $payload = NULL, array $headers = [], $timeout = 10, $as_json = TRUE)
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
		];

		$http_headers = ['Accept: application/json'];

		foreach ($headers as $name => $value)
		{
			$http_headers[] = $name.': '.$value;
		}

		if ($method === 'POST')
		{
			$options[CURLOPT_POST] = TRUE;
			$options[CURLOPT_POSTFIELDS] = $as_json
				? json_encode($payload, JSON_UNESCAPED_UNICODE)
				: (is_array($payload) ? http_build_query($payload) : (string) $payload);

			if ($as_json)
			{
				$http_headers[] = 'Content-Type: application/json';
			}
		}
		elseif (is_array($payload) AND $payload)
		{
			$options[CURLOPT_URL] = $url.(strpos($url, '?') === FALSE ? '?' : '&').http_build_query($payload);
		}

		$options[CURLOPT_HTTPHEADER] = $http_headers;

		curl_setopt_array($ch, $options);

		$body = curl_exec($ch);
		$err  = curl_error($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($err !== '' OR $body === FALSE)
		{
			return ['code' => $code, 'body' => '', 'error' => 'Сетевая ошибка: '.$err];
		}

		return ['code' => $code, 'body' => (string) $body, 'error' => ''];
	}

	protected static function _json($body)
	{
		$data = json_decode((string) $body, TRUE);

		return is_array($data) ? $data : NULL;
	}
}
