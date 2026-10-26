<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Плоский ротируемый лог сайта: одно событие — одна строка.
 *
 * Зачем отдельный писатель, если в Kohana уже есть Log_File:
 *   * Log_File пишет PHP-файлы (`application/logs/2026/10/03.php`) с охранной
 *     строкой `defined('SYSPATH') or die()` — их неудобно смотреть глазами и
 *     невозможно приложить к разбору инцидента;
 *   * у Log_File нет ротации: файл растёт до бесконечности;
 *   * инцидент 03.10.2026 («фото уходит, результата нет») разбирали по догадкам:
 *     на воркере ответ сайта был виден, а на сайте — нет, потому что 500
 *     уходил в PHP-лог ядра, который никто не открывал.
 *
 * Теперь у сайта один предсказуемый файл (по умолчанию
 * `application/logs/n2j-site.log`), который можно просто прислать:
 *
 *     2026-10-03 08:35:12 | ERROR | label: приёмка результата прогона 51 упала: Database_Exception: ...
 *
 * Настройки (`.env`, все необязательные):
 *   N2J_LOG_FILE      — путь к файлу (по умолчанию application/logs/n2j-site.log)
 *   N2J_LOG_MAX_MB    — размер, после которого файл ротируется (5 МБ)
 *   N2J_LOG_BACKUPS   — сколько старых файлов хранить (5: n2j-site.log.1 … .5)
 *   N2J_LOG_LEVEL     — минимальный уровень: DEBUG…EMERGENCY (по умолчанию INFO)
 *   N2J_LOG_ENABLED   — 0, чтобы выключить файл (логи останутся в php-логе ядра)
 *
 * Писатель НИКОГДА не бросает исключений: сайт не должен падать из-за лога.
 */
class Log_Plain extends Log_Writer {

	/** @var string путь к файлу лога */
	protected $_file;

	/** @var int размер, после которого файл ротируется (байт; 0 — не ротировать) */
	protected $_max_bytes;

	/** @var int сколько старых файлов хранить */
	protected $_backups;

	/** @var int минимальный уровень (Log::DEBUG … Log::EMERGENCY) */
	protected $_min_level;

	/**
	 * @param string $file      путь к файлу
	 * @param int    $max_bytes размер ротации
	 * @param int    $backups   сколько файлов хранить
	 * @param int    $min_level минимальный уровень
	 */
	public function __construct($file = NULL, $max_bytes = NULL, $backups = NULL, $min_level = NULL)
	{
		$this->_file = $file !== NULL
			? (string) $file
			: (string) (getenv('N2J_LOG_FILE') ?: APPPATH.'logs'.DIRECTORY_SEPARATOR.'n2j-site.log');

		$this->_max_bytes = $max_bytes !== NULL
			? (int) $max_bytes
			: max(0, (int) round(((float) (getenv('N2J_LOG_MAX_MB') ?: 5)) * 1048576));

		$this->_backups = $backups !== NULL
			? (int) $backups
			: max(0, (int) (getenv('N2J_LOG_BACKUPS') ?: 5));

		$this->_min_level = $min_level !== NULL
			? (int) $min_level
			: $this->_level_by_name((string) (getenv('N2J_LOG_LEVEL') ?: 'INFO'));
	}

	/**
	 * Записать событие в плоский лог НЕМЕДЛЕННО.
	 *
	 * Почему не просто `Kohana::$log->add()`: Kohana сбрасывает накопленные
	 * сообщения на диск только при исключении (Kohana_Exception) и в редкой
	 * ветке Response::send_file(). Обычный запрос логирует и молчит — именно
	 * из-за этого 03.10.2026 на сайте не осталось следов 500 на приёмке.
	 * `Log_Plain::event()` добавляет сообщение в общий лог и сразу пишет его
	 * (всем писателям, включая php-лог ядра), так что ни одно событие не
	 * теряется и не дублируется.
	 *
	 * @param int    $level      Log::INFO | Log::WARNING | Log::ERROR …
	 * @param string $message    текст с плейсхолдерами (:run)
	 * @param array  $values     значения плейсхолдеров
	 * @param array  $additional контекст (в плоском логе — хвостом строки);
	 *                           `['exception' => $e]` добавит трассировку
	 */
	public static function event($level, $message, array $values = NULL, array $additional = NULL)
	{
		if ( ! is_object(Kohana::$log))
		{
			return;
		}

		Kohana::$log->add($level, $message, $values, $additional);
		Kohana::$log->write();
	}

	/** Путь к файлу лога — чтобы показать его человеку (страница, CLI, письмо). */
	public function file()
	{
		return $this->_file;
	}

	/**
	 * Записать сообщения в файл.
	 *
	 * @param array $messages сообщения из Log::add()
	 */
	public function write(array $messages)
	{
		if ( ! $this->_enabled())
		{
			return;
		}

		try
		{
			$lines = '';

			foreach ($messages as $message)
			{
				if ( ! is_array($message) OR ! isset($message['level']))
				{
					continue;
				}

				if ((int) $message['level'] > $this->_min_level)
				{
					continue;
				}

				$lines .= $this->_line($message).PHP_EOL;
			}

			if ($lines === '')
			{
				return;
			}

			$dir = dirname($this->_file);

			if ( ! is_dir($dir))
			{
				@mkdir($dir, 0777, TRUE);
			}

			$this->_rotate();

			@file_put_contents($this->_file, $lines, FILE_APPEND | LOCK_EX);
		}
		catch (Throwable $e)
		{
			// Лог не имеет права уронить сайт: молча живём дальше (в php-лог
			// ядра сообщение всё равно попадёт своим писателем).
		}
	}

	/** Включён ли файловый лог (N2J_LOG_ENABLED=0 выключает). */
	protected function _enabled()
	{
		$flag = strtolower(trim((string) getenv('N2J_LOG_ENABLED')));

		return ! in_array($flag, ['0', 'no', 'false', 'off'], TRUE);
	}

	/** Одна строка лога: время | уровень | сообщение | файл:строка. */
	protected function _line(array $message)
	{
		$level = isset($this->_log_levels[$message['level']])
			? $this->_log_levels[$message['level']]
			: (string) $message['level'];

		$line = date('Y-m-d H:i:s', (int) $message['time']).' | '.str_pad($level, 9).' | ';

		// body может быть массивом (Log::add() с плейсхолдерами) — приводим к тексту
		$body = isset($message['body']) ? $message['body'] : '';
		$line .= is_scalar($body) ? (string) $body : json_encode($body, JSON_UNESCAPED_UNICODE);

		if ( ! empty($message['file']))
		{
			$line .= ' | '.$message['file'].':'.(int) ($message['line'] ?? 0);
		}

		// Контекст (Log::add(..., $additional)) — коротко и в одну строку: по нему
		// ищут инцидент (run_id, статус, размер payload).
		if ( ! empty($message['additional']) AND is_array($message['additional']))
		{
			$extra = $message['additional'];
			unset($extra['exception']);

			if ($extra)
			{
				$line .= ' | '.json_encode($extra, JSON_UNESCAPED_UNICODE);
			}
		}

		$line .= PHP_EOL;

		// Трассировка исключения — следующими строками с отступом: одна строка
		// на кадр, чтобы файл читался глазами и грепался.
		if ( ! empty($message['additional']['exception'])
			AND $message['additional']['exception'] instanceof Throwable)
		{
			/** @var Throwable $e */
			$e = $message['additional']['exception'];

			// Первая строка — класс, текст и место: по ним и ищут причину, не
			// продираясь через кадры. (getTraceAsString() их не содержит.)
			$line .= '    '.get_class($e).': '.$e->getMessage()
				.' | '.$e->getFile().':'.$e->getLine().PHP_EOL;

			foreach (explode("\n", $e->getTraceAsString()) as $frame)
			{
				$line .= '    '.$frame.PHP_EOL;
			}
		}

		return rtrim($line, PHP_EOL);
	}

	/** Ротация по размеру: n2j-site.log → .1 → .2 … старше $backups удаляем. */
	protected function _rotate()
	{
		if ($this->_max_bytes <= 0 OR $this->_backups <= 0 OR ! is_file($this->_file))
		{
			return;
		}

		if (filesize($this->_file) < $this->_max_bytes)
		{
			return;
		}

		$oldest = $this->_file.'.'.$this->_backups;

		if (is_file($oldest))
		{
			@unlink($oldest);
		}

		for ($i = $this->_backups - 1; $i >= 1; $i--)
		{
			$from = $this->_file.'.'.$i;

			if (is_file($from))
			{
				@rename($from, $this->_file.'.'.($i + 1));
			}
		}

		@rename($this->_file, $this->_file.'.1');
	}

	/** Имя уровня (INFO, ERROR…) → константа LOG_*. */
	protected function _level_by_name($name)
	{
		$name = strtoupper(trim($name));

		$found = array_search($name, $this->_log_levels, TRUE);

		return $found === FALSE ? Log::INFO : (int) $found;
	}

}
