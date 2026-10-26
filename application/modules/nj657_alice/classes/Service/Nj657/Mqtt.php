<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-657: минимальный MQTT 3.1.1-издатель для навыков Алисы.
 *
 * Зачем свой класс: в наработках из source_data ответ навыка уходил на
 * MQTT-сервер и отображался на домашнем дисплее (ESP32). Библиотеки
 * phpMQTT в проекте нет, а для публикации QoS 0 достаточно трёх пакетов:
 * CONNECT → PUBLISH → DISCONNECT. Реализация без зависимостей, ~100 строк.
 *
 * Настройки — секция 'mqtt' конфига nj657_alice (переменные NJ657_MQTT_*
 * в .env). Публикация не должна ронять вебхук: любая ошибка — запись в
 * лог и FALSE, ответ пользователю уходит в любом случае.
 *
 * Сборка пакетов вынесена в чистые методы — их проверяет стенд
 * .tools/nj657_alice_stand.php побайтово.
 */
class Service_Nj657_Mqtt
{
	/** Параметры из конфига модуля. */
	public static function config()
	{
		$config = Kohana::$config->load('nj657_alice');
		$data = $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;

		return (array) Arr::get($data, 'mqtt', []);
	}

	/** MQTT включён и хост задан. */
	public static function enabled()
	{
		$config = self::config();

		return (bool) Arr::get($config, 'enabled', FALSE) AND (string) Arr::get($config, 'host', '') !== '';
	}

	/**
	 * Опубликовать сообщение в топик (QoS 0).
	 *
	 * @param   string $topic   топик, например hasp/esp32/command/p1b1.text
	 * @param   string $payload текст сообщения
	 * @return  array           ['ok' => bool, 'error' => string]
	 */
	public static function publish($topic, $payload)
	{
		$out = ['ok' => FALSE, 'error' => ''];

		if ( ! self::enabled())
		{
			$out['error'] = 'mqtt disabled';

			return $out;
		}

		if ( ! self::topic_valid((string) $topic))
		{
			$out['error'] = 'bad topic';

			return $out;
		}

		$config = self::config();
		$host    = (string) Arr::get($config, 'host');
		$port    = (int) Arr::get($config, 'port', 1883);
		$timeout = max(0.5, (float) Arr::get($config, 'timeout_sec', 2));

		$socket = @stream_socket_client(
			'tcp://'.$host.':'.$port,
			$errno, $errstr,
			$timeout
		);

		if ($socket === FALSE)
		{
			$out['error'] = 'connect: '.($errstr !== '' ? $errstr : 'errno '.$errno);

			return $out;
		}

		stream_set_timeout($socket, (int) ceil($timeout));

		try
		{
			$client_id = 'nj657-'.bin2hex(random_bytes(4));

			$connect = self::build_connect_packet(
				$client_id,
				(string) Arr::get($config, 'username', ''),
				(string) Arr::get($config, 'password', '')
			);

			if (@fwrite($socket, $connect) === FALSE)
			{
				throw new Exception('write connect failed');
			}

			// CONNACK: 0x20 0x02 <flags> <return code>
			$connack = @fread($socket, 4);

			if (strlen((string) $connack) < 4 OR ord($connack[0]) !== 0x20 OR ord($connack[3]) !== 0x00)
			{
				throw new Exception('connack refused: '.self::_hex((string) $connack));
			}

			if (@fwrite($socket, self::build_publish_packet((string) $topic, (string) $payload)) === FALSE)
			{
				throw new Exception('write publish failed');
			}

			@fwrite($socket, pack('CC', 0xE0, 0x00)); // DISCONNECT

			$out['ok'] = TRUE;
		}
		catch (Throwable $e)
		{
			$out['error'] = $e->getMessage();
		}

		@fclose($socket);

		return $out;
	}

	/**
	 * Топик MQTT: непустой, до 200 символов, без пробелов и wildcard-символов
	 * (+ и # — для подписок, не для публикации).
	 */
	public static function topic_valid($topic)
	{
		$topic = trim((string) $topic);

		return $topic !== ''
			AND strlen($topic) <= 200
			AND preg_match('/^[A-Za-z0-9_\/$.\-]+$/', $topic) === 1
			AND strpos($topic, '//') === FALSE;
	}

	/**
	 * Пакет CONNECT (MQTT 3.1.1, clean session, без will).
	 *
	 * @param string $client_id идентификатор клиента
	 * @param string $username  логин (пустая строка — без авторизации)
	 * @param string $password  пароль
	 */
	public static function build_connect_packet($client_id, $username = '', $password = '')
	{
		$flags = 0x02; // clean session

		if ($username !== '')
		{
			$flags |= 0x80;
		}

		if ($password !== '')
		{
			$flags |= 0x40;
		}

		$variable  = self::_string('MQTT');
		$variable .= pack('CC', 0x04, $flags);      // уровень протокола, флаги
		$variable .= pack('n', 30);                 // keepalive, сек
		$variable .= self::_string((string) $client_id);

		if ($username !== '')
		{
			$variable .= self::_string($username);
		}

		if ($password !== '')
		{
			$variable .= self::_string($password);
		}

		return pack('C', 0x10).self::_remaining_length(strlen($variable)).$variable;
	}

	/**
	 * Пакет PUBLISH (QoS 0, без retain).
	 *
	 * @param string $topic   топик
	 * @param string $payload сообщение
	 */
	public static function build_publish_packet($topic, $payload)
	{
		$variable = self::_string((string) $topic);
		$body     = $variable.(string) $payload;

		return pack('C', 0x30).self::_remaining_length(strlen($body)).$body;
	}

	/** UTF-8 строка с big-endian длиной (формат MQTT). */
	protected static function _string($value)
	{
		return pack('n', strlen($value)).$value;
	}

	/** Кодирование «remaining length» (variable-length integer MQTT). */
	protected static function _remaining_length($length)
	{
		$out = '';

		do
		{
			$digit = $length % 128;
			$length = (int) floor($length / 128);

			if ($length > 0)
			{
				$digit |= 0x80;
			}

			$out .= pack('C', $digit);
		}
		while ($length > 0);

		return $out;
	}

	/** Строка в hex — для сообщений об ошибках. */
	protected static function _hex($value)
	{
		return bin2hex((string) $value);
	}
}
