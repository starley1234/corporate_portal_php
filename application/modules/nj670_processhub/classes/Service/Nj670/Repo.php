<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Низкий уровень ProcessHub: доступ к БД, конфиг, коды, журнал.
 *
 * Намеренно не ORM: граф — jsonb, а выборки идут пакетами (версия + узлы +
 * рёбра), ORM дал бы N+1 и динамические свойства, запрещённые в PHP 8.4.
 * Все таблицы — nj_670_*; чужих таблиц модуль не читает.
 */
class Service_Nj670_Repo
{
	/** Классы онтологии, которые принимает интерфейс. */
	public static function entity_types()
	{
		return [
			'role'       => 'Роли и должности',
			'system'     => 'ИТ-системы',
			'artifact'   => 'Артефакты',
			'event'      => 'События',
			'metric'     => 'Метрики',
			'rule'       => 'Правила',
			'department' => 'Подразделения',
		];
	}

	public static function config()
	{
		$config = Kohana::$config->load('nj670_processhub');

		return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
	}

	public static function branch($name, $default = [])
	{
		return (array) Arr::get(self::config(), $name, $default);
	}

	public static function limits($key = NULL, $default = NULL)
	{
		$limits = self::branch('limits');

		return $key === NULL ? $limits : Arr::get($limits, $key, $default);
	}

	public static function node_types()
	{
		return self::branch('node_types');
	}

	public static function edge_types()
	{
		return self::branch('edge_types');
	}

	public static function statuses()
	{
		return self::branch('statuses');
	}

	public static function severities()
	{
		return self::branch('severities');
	}

	public static function model($light = FALSE)
	{
		$env = $light ? 'NJ670_LIGHT_MODEL' : 'NJ670_MODEL';
		$own = trim((string) (getenv($env) ?: ''));

		if ($own !== '')
		{
			return UTF8::substr($own, 0, 160);
		}

		$key = $light ? 'light_model' : 'model';
		$fallback = (string) Arr::get(self::config(), $key, '');

		if ( ! $light AND $fallback === '')
		{
			$fallback = trim((string) (getenv('NJ670_LIGHT_MODEL') ?: ''));
		}

		return UTF8::substr(trim($fallback), 0, 160);
	}

	public static function timeout($channel)
	{
		return (int) Arr::get(self::branch('timeout'), $channel, 300);
	}

	/**
	 * Публичный код: буквы/цифры без похожих 0/1 и без гласных-двусмысленностей.
	 * Такой код удобно диктовать по телефону и он проходит регэксп маршрута.
	 */
	public static function code_new($len = 12)
	{
		$alphabet = '23456789abcdefghijkmnpqrstuvwxyz';
		$code = '';

		for ($i = 0; $i < (int) $len; $i++)
		{
			$code .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
		}

		return $code;
	}

	public static function code_valid($code)
	{
		return is_string($code) AND preg_match('~^[23456789a-z]{8,24}$~', $code) === 1;
	}

	public static function slug_code($text, $fallback = '')
	{
		$map = [
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
			'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
			'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
			'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
			'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
			'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
		];

		$text = mb_strtolower(trim((string) $text));
		$out = '';

		foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch)
		{
			if (isset($map[$ch]))
			{
				$out .= $map[$ch];
			}
			elseif (preg_match('~[a-z0-9]~', $ch))
			{
				$out .= $ch;
			}
			else
			{
				$out .= '-';
			}
		}

		$out = trim(preg_replace('~-+~', '-', $out), '-');
		$out = str_replace(['0', '1'], ['2', '3'], $out);

		if ($out === '')
		{
			$out = $fallback !== '' ? $fallback : self::code_new(8);
		}

		return UTF8::substr($out, 0, 24);
	}

	public static function json_encode($value, $fallback = '{}')
	{
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return $json === FALSE ? $fallback : $json;
	}

	public static function json_decode($value, $default = [])
	{
		if (is_array($value))
		{
			return $value;
		}

		if ( ! is_string($value) OR trim($value) === '')
		{
			return $default;
		}

		$decoded = json_decode($value, TRUE);

		return is_array($decoded) ? $decoded : $default;
	}

	/**
	 * Длительность в человеческом виде. Рабочий день — 480 минут: именно так
	 * сроки называют владельцы процессов, и именно на 480 делится демо
	 * (3 раб. дн. плана против 14 раб. дн. факта), а не на календарные 24 часа.
	 */
	public static function minutes_text($minutes)
	{
		$minutes = max(0, (int) $minutes);

		if ($minutes < 60)
		{
			return $minutes.' мин';
		}

		if ($minutes < 480)
		{
			$hours = intdiv($minutes, 60);
			$rest = $minutes % 60;

			return $hours.' ч'.($rest > 0 ? ' '.$rest.' мин' : '');
		}

		$days = intdiv($minutes, 480);
		$rest = $minutes % 480;
		$hours = intdiv($rest, 60);
		$mins = $rest % 60;

		$out = $days.' раб. дн.';

		if ($hours > 0)
		{
			$out .= ' '.$hours.' ч';
		}

		if ($mins > 0)
		{
			$out .= ' '.$mins.' мин';
		}

		return $out;
	}

	public static function money($value)
	{
		$value = round((float) $value, 2);
		$kopecks = abs($value - round($value)) > 0.004;

		return number_format($value, $kopecks ? 2 : 0, ',', ' ').' ₽';
	}

	/**
	 * Название процесса из текста регламента.
	 *
	 * Владелец не обязан придумывать имя: «Текст регламента» — единственное
	 * обязательное поле. Берём первую осмысленную строку (без нумерации и
	 * маркеров), обрезаем по границе слова и убираем хвостовую пунктуацию.
	 * Если текста нет (файл) — имя файла без расширения, иначе запасное имя.
	 */
	public static function title_from_text($text, $fallback = '')
	{
		$text = trim((string) $text);
		$lines = preg_split('~\R~u', $text);
		$title = '';

		foreach ((array) $lines as $line)
		{
			$line = trim(preg_replace('~\s+~u', ' ', (string) $line));

			if ($line === '')
			{
				continue;
			}

			// «1.», «1)», «Шаг 2.», «-», «•», «—» — служебная разметка списка.
			$line = preg_replace('~^(?:шаг\s*\d+\s*[.):]?\s*|\d+\s*[.)]\s*|[-–—•*]\s*)~iu', '', $line);
			$line = trim((string) $line);

			if (UTF8::strlen($line) >= 3)
			{
				$title = $line;
				break;
			}
		}

		if ($title === '')
		{
			return $fallback !== '' ? $fallback : 'Процесс от '.date('d.m.Y');
		}

		// Имя — не абзац: берём первое предложение и ограничиваем длину.
		$sentence = preg_split('~(?<=[.!?])\s+~u', $title);
		$title = trim((string) reset($sentence));

		$max = 90;

		if (UTF8::strlen($title) > $max)
		{
			$cut = UTF8::substr($title, 0, $max);
			$space = UTF8::strrpos($cut, ' ');

			if ($space !== FALSE AND $space > 40)
			{
				$cut = UTF8::substr($cut, 0, $space);
			}

			$title = $cut;
		}

		$title = rtrim(trim($title), " .,;:—-");
		$title = preg_replace('~\s+~u', ' ', $title);

		return UTF8::substr($title, 0, 255);
	}

	public static function date_text($value)
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return '—';
		}

		$ts = strtotime($value);

		return $ts ? date('d.m.Y H:i', $ts) : $value;
	}

	// ---------------------------------------------------------------- БД

	protected static function _db()
	{
		return Database::instance();
	}

	protected static function _q($value)
	{
		return self::_db()->quote($value);
	}

	protected static function _bool($value)
	{
		return $value ? 'TRUE' : 'FALSE';
	}

	protected static function _one($sql)
	{
		$row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

		return $row ? (array) $row : NULL;
	}

	protected static function _all($sql)
	{
		$rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();

		return $rows ? array_map(function ($row) { return (array) $row; }, $rows) : [];
	}

	protected static function _value($sql)
	{
		$row = self::_one($sql);

		if ($row === NULL)
		{
			return 0;
		}

		return reset($row);
	}

	protected static function _exec($sql)
	{
		return self::_db()->query(Database::INSERT, $sql, FALSE);
	}

	/**
	 * INSERT ... RETURNING id.
	 *
	 * @param string $table
	 * @param array  $row          поле => значение
	 * @param array  $json_fields  поля, которые надо отдать как ::jsonb
	 */
	protected static function _insert($table, array $row, array $json_fields = [])
	{
		$db = self::_db();
		$fields = [];
		$values = [];

		foreach ($row as $field => $value)
		{
			if ( ! preg_match('~^[a-z_][a-z0-9_]*$~', (string) $field))
			{
				throw new InvalidArgumentException('Недопустимое имя колонки: '.$field);
			}

			$fields[] = $field;

			if (in_array($field, $json_fields, TRUE))
			{
				$values[] = $db->quote(self::json_encode($value)).'::jsonb';
			}
			elseif ($value === NULL)
			{
				$values[] = 'NULL';
			}
			elseif (is_bool($value))
			{
				$values[] = $value ? 'TRUE' : 'FALSE';
			}
			elseif (is_int($value) OR is_float($value))
			{
				$values[] = (string) $value;
			}
			else
			{
				$values[] = $db->quote((string) $value);
			}
		}

		$one = self::_one('INSERT INTO '.$table.' ('.implode(', ', $fields).') VALUES ('
			.implode(', ', $values).') RETURNING id');

		return (int) Arr::get((array) $one, 'id', 0);
	}

	/**
	 * UPDATE по простому условию id = N.
	 *
	 * @param string $table
	 * @param array  $row
	 * @param int    $id
	 * @param array  $json_fields
	 */
	protected static function _update($table, array $row, $id, array $json_fields = [])
	{
		if ( ! $row)
		{
			return FALSE;
		}

		$db = self::_db();
		$sets = [];

		foreach ($row as $field => $value)
		{
			if ( ! preg_match('~^[a-z_][a-z0-9_]*$~', (string) $field))
			{
				throw new InvalidArgumentException('Недопустимое имя колонки: '.$field);
			}

			if (in_array($field, $json_fields, TRUE))
			{
				$sets[] = $field.' = '.$db->quote(self::json_encode($value)).'::jsonb';
			}
			elseif ($value === NULL)
			{
				$sets[] = $field.' = NULL';
			}
			elseif (is_bool($value))
			{
				$sets[] = $field.' = '.($value ? 'TRUE' : 'FALSE');
			}
			elseif (is_int($value) OR is_float($value))
			{
				$sets[] = $field.' = '.(string) $value;
			}
			else
			{
				$sets[] = $field.' = '.$db->quote((string) $value);
			}
		}

		$sets[] = 'updated_at = now()';

		self::_exec('UPDATE '.$table.' SET '.implode(', ', $sets).' WHERE id = '.(int) $id);

		return TRUE;
	}

	protected static function _push($channel, array $payload, $user_id, $timeout_key)
	{
		if ( ! class_exists('Service_Nj120_JobQueue'))
		{
			return ['ok' => FALSE, 'error' => 'Очередь задач недоступна.'];
		}

		try
		{
			$job_id = Service_Nj120_JobQueue::push($channel, $payload, [
				'module'       => 'nj670_processhub',
				'created_by'   => (int) $user_id,
				'timeout_sec'  => self::timeout($timeout_key),
				'max_attempts' => 2,
			]);
		}
		catch (Throwable $e)
		{
			self::_log(Log::ERROR, 'канал :channel не принял задачу: :error', [
				':channel' => $channel, ':error' => $e->getMessage(),
			]);

			return ['ok' => FALSE, 'error' => 'Не удалось поставить задачу в очередь: '.$e->getMessage()];
		}

		return $job_id > 0
			? ['ok' => TRUE, 'job_id' => (int) $job_id]
			: ['ok' => FALSE, 'error' => 'Очередь не вернула номер задачи.'];
	}

	protected static function _event($process_id, $user_id, $kind, $message, array $payload = [])
	{
		try
		{
			self::_insert('nj_670_events', [
				'process_id' => $process_id > 0 ? (int) $process_id : NULL,
				'user_id'    => (int) $user_id,
				'kind'       => UTF8::substr((string) $kind, 0, 40),
				'message'    => UTF8::substr((string) $message, 0, 1000),
				'payload'    => $payload,
			], ['payload']);
		}
		catch (Throwable $e)
		{
			// журнал действий не должен ломать основную операцию
		}
	}

	protected static function _log($level, $message, array $params = [])
	{
		if ( ! class_exists('Log_Plain') OR ! is_object(Kohana::$log))
		{
			return;
		}

		Log_Plain::event($level, 'NJ670 '.$message, $params);
	}

	protected static function _notify($event, array $payload, array $options = [])
	{
		if ( ! class_exists('Service_Nj597_Notify'))
		{
			return;
		}

		try
		{
			Service_Nj597_Notify::emit($event, $payload, $options);
		}
		catch (Throwable $e)
		{
			self::_log(Log::WARNING, 'уведомление :event не отправлено: :error', [
				':event' => $event,
				':error' => $e->getMessage(),
			]);
		}
	}
}
