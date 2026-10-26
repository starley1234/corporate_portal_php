<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Общая кухня сервисов prl597: конфиг, настройки администратора, доступ к БД
 * и мелкие помощники, которые иначе пришлось бы дублировать в каждом классе.
 *
 * Ничего не знает про бизнес-логику уведомлений — только инфраструктура:
 *   * config()           — config/prl597_notifications.php как массив;
 *   * setting($key)      — настройка администратора (таблица prl_597_settings)
 *                          с умолчанием из конфига; секреты — только из env;
 *   * _db/_q/_one/_all/_exec — тонкая обёртка над Database (драйвер проекта
 *                          возвращает на SELECT результат, на остальном — число,
 *                          поэтому ->execute() поверх query() звать нельзя);
 *   * _bool()            — PostgreSQL BOOLEAN приходит строкой 't'/'f', а
 *                          (bool)'f' в PHP это TRUE. Это уже стоило проекту
 *                          «выключенных» настроек, которые оставались включены.
 */
abstract class Service_Prl597_Base
{
	// ------------------------------------------------------------------ конфиг

	/** Кеш настроек общий для сервисов; сбрасывается после записи. */
	protected static $_settings = NULL;

	/** Конфиг модуля как массив. */
	public static function config()
	{
		try
		{
			$config = Kohana::$config->load('prl597_notifications');
		}
		catch (Throwable $e)
		{
			return [];
		}

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	/** Кусок конфига: Arr::get по пути 'limits.per_hour'. */
	public static function cfg($path = NULL, $default = NULL)
	{
		$config = self::config();

		if ($path === NULL OR $path === '')
		{
			return $config;
		}

		return Arr::path($config, $path, $default);
	}

	/**
	 * Настройка администратора. Сначала таблица prl_597_settings, затем умолчание
	 * из конфига. Ключи вида 'limits.per_user_hour.email'.
	 *
	 * Значения хранятся строкой; число возвращаем числом, '0'/'1' — как есть
	 * (пустой строке соответствует NULL, чтобы отличить «не задано»).
	 */
	public static function setting($key, $default = NULL)
	{
		if (self::$_settings === NULL)
		{
			self::$_settings = [];

			try
			{
				foreach (self::_all('SELECT "key", "value" FROM "prl_597_settings"') as $row)
				{
					self::$_settings[(string) $row['key']] = (string) $row['value'];
				}
			}
			catch (Throwable $e)
			{
				// Таблицы может не быть на самом первом запросе — живём на умолчаниях.
				self::$_settings = [];
			}
		}

		if (array_key_exists($key, self::$_settings))
		{
			return self::$_settings[$key];
		}

		if ($default !== NULL)
		{
			return $default;
		}

		return self::cfg($key);
	}

	/** Записать настройку администратора (атомарный upsert). */
	public static function set_setting($key, $value)
	{
		$db = self::_db();

		$db->query(Database::INSERT,
			'INSERT INTO "prl_597_settings" ("key", "value", "updated_at")
			 VALUES ('.$db->quote((string) $key).', '.$db->quote((string) $value).', now())
			 ON CONFLICT ("key") DO UPDATE SET "value" = EXCLUDED."value", "updated_at" = now()',
			FALSE);

		self::forget_settings();

		return TRUE;
	}

	/** Сбросить кеш настроек (после правки в админке). */
	public static function forget_settings()
	{
		self::$_settings = NULL;
	}

	// ---------------------------------------------------------------- каналы

	/**
	 * Каналы, с которыми умеет работать центр: ключ => описание.
	 * По умолчанию только включённые и настроенные; FALSE — полный список для админа.
	 */
	public static function channels($only_enabled = TRUE)
	{
		$out = [];

		foreach ((array) self::cfg('channels', []) as $key => $info)
		{
			$info = (array) $info;
			$info['key'] = (string) $key;

			if ($only_enabled AND ! self::channel_enabled((string) $key))
			{
				continue;
			}

			$out[(string) $key] = $info;
		}

		return $out;
	}

	/** Единое соответствие ключей канала классам драйверов (in_app ≠ In_app). */
	public static function channel_driver($channel)
	{
		$drivers = [
			'in_app' => 'Service_Prl597_Channel_Inapp',
			'email' => 'Service_Prl597_Channel_Email',
			'telegram' => 'Service_Prl597_Channel_Telegram',
			'max' => 'Service_Prl597_Channel_Max',
		];
		return $drivers[(string) $channel] ?? '';
	}

	/** Каналы, которые пользователь может настроить без помощи администратора. */
	public static function user_channels()
	{
		$out = self::channels();
		foreach (['telegram', 'max'] as $channel)
		{
			if ( ! isset($out[$channel])) { continue; }
			// Бизнес-API MAX принимает телефон: проверки телефона и самостоятельной
			// привязки здесь пока нет. Не предлагаем вместо неё неверную ссылку бота.
			if (($channel === 'max' AND Service_Prl597_Channel_Max::driver() === 'business')
				OR Service_Prl597_Bind::mode($channel) === 'off'
				OR Service_Prl597_Bind::bot_url($channel, '__CODE__') === '')
			{
				unset($out[$channel]);
			}
		}
		return $out;
	}

	/** Канал включён в конфиге и драйвер настроен? */
	public static function channel_enabled($channel)
	{
		$channels = self::channels(FALSE);

		if ( ! isset($channels[$channel]))
		{
			return FALSE;
		}

		if ( ! self::_truthy(Arr::get($channels[$channel], 'enabled', TRUE)))
		{
			return FALSE;
		}

		$driver = Service_Prl597_Base::channel_driver($channel);

		if ( ! class_exists($driver))
		{
			return FALSE;
		}

		return (bool) call_user_func([$driver, 'enabled']);
	}

	/** Название канала для интерфейса. */
	public static function channel_label($channel)
	{
		$channels = self::channels(FALSE);

		return isset($channels[$channel]) ? (string) Arr::get($channels[$channel], 'label', $channel) : (string) $channel;
	}

	/** Иконка канала (Font Awesome). */
	public static function channel_icon($channel)
	{
		$channels = self::channels(FALSE);

		return isset($channels[$channel]) ? (string) Arr::get($channels[$channel], 'icon', 'fas fa-bell') : 'fas fa-bell';
	}

	/** Каналы, которым нужен привязанный адрес (почта, чат в мессенджере). */
	public static function binding_channels()
	{
		$out = [];

		foreach (self::channels(FALSE) as $key => $info)
		{
			if (self::_truthy(Arr::get($info, 'bind', FALSE)))
			{
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	/** Каналы, которые молчат в тихие часы. */
	public static function quiet_channels()
	{
		$out = [];

		foreach (self::channels(FALSE) as $key => $info)
		{
			if (self::_truthy(Arr::get($info, 'quiet', FALSE)))
			{
				$out[] = (string) $key;
			}
		}

		return $out;
	}

	// -------------------------------------------------------------------- БД

	protected static function _db()
	{
		return Database::instance();
	}

	/** Кавычки для значения (все значения в SQL идут через него). */
	protected static function _q($value)
	{
		return self::_db()->quote((string) $value);
	}

	/** Одна строка (массив) или NULL. */
	protected static function _one($sql)
	{
		$row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	/** Все строки: массив массивов. */
	protected static function _all($sql)
	{
		$rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();
		$out  = [];

		foreach ($rows as $row)
		{
			$out[] = (array) $row;
		}

		return $out;
	}

	/** Запрос без выборки: INSERT/UPDATE/DELETE/DDL. */
	protected static function _exec($type, $sql)
	{
		return self::_db()->query($type, $sql, FALSE);
	}

	/** Скаляр: одно значение первой строки. */
	protected static function _val($sql, $default = NULL)
	{
		$row = self::_one($sql);

		if ( ! $row)
		{
			return $default;
		}

		$first = reset($row);

		return $first === NULL ? $default : $first;
	}

	// -------------------------------------------------------------- помощники

	/**
	 * PostgreSQL BOOLEAN приходит строкой 't'/'f'. (bool)'f' === TRUE — из-за
	 * этого в проекте однажды «выключенные» настройки оставались включёнными.
	 */
	public static function _truthy($value)
	{
		return $value === TRUE OR $value === 't' OR $value === 'true' OR $value === 1 OR $value === '1';
	}

	/** Обрезать строку по границам символов (БД всё равно бы обрезала). */
	protected static function _cut($value, $len)
	{
		$value = trim((string) $value);

		return mb_substr($value, 0, (int) $len);
	}

	/** «12» → 12, мусор → умолчание. */
	protected static function _int($value, $default = 0)
	{
		return is_numeric($value) ? (int) $value : (int) $default;
	}

	/** Список из строки с разделителем-запятой (роли, каналы). */
	public static function _csv($value)
	{
		if (is_array($value))
		{
			return array_values(array_filter(array_map('trim', $value), 'strlen'));
		}

		return array_values(array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'));
	}

	/** Полные имена env модуля; старые короткие имена поддерживаются для совместимости. */
	public static function env($key, $default = '')
	{
		$names = [$key];
		if (strpos($key, 'TELEGRAM_') === 0 OR strpos($key, 'MAX_') === 0)
		{
			$names = ['PRL597_NOTIFICATIONS_'.$key, 'PRL597_'.$key, $key];
		}
		foreach ($names as $name)
		{
			$value = getenv($name);
			if ($value !== FALSE) { return trim((string) $value); }
		}
		return $default;
	}

	/** Адрес сайта без слеша на конце (для ссылок в письмах и мессенджерах). */
	public static function base_url()
	{
		try
		{
			$url = (string) Kohana::$config->load('site_config.site_url');
		}
		catch (Throwable $e)
		{
			$url = '';
		}

		if ($url === '' AND ! empty($_SERVER['HTTP_HOST']))
		{
			$url = (( ! empty($_SERVER['HTTPS']) AND $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
				.$_SERVER['HTTP_HOST'];
		}

		return rtrim($url, '/');
	}

	/** Лог модуля: не должен ронять уведомление, даже если лог недоступен. */
	protected static function _log($level, $message, array $values = [])
	{
		try
		{
			if (class_exists('Log_Plain') AND method_exists('Log_Plain', 'event'))
			{
				Log_Plain::event($level, 'prl597: '.$message, $values);
			}
			else
			{
				Kohana::$log->add($level, 'prl597: '.$message, $values);
			}
		}
		catch (Throwable $e)
		{
			// молчим: уведомление важнее журнала
		}
	}
}
