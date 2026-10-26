<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-664 «Декодер JSON» — чистые функции: распознать, раскодировать, показать.
 *
 * Зачем модуль: JSON прилетает в самых разных видах — из лога он экранирован
 * (`{\"a\":1}`), из адреса браузера — URL-кодирован (`%7B%22a%22%3A1%7D`),
 * из HTML — сущностями (`&quot;`), а из PHP-дампа — вообще строкой с
 * `\\n` внутри. Человеку нужно одно: увидеть структуру и значения.
 *
 * Всё здесь — чистые функции: ни базы, ни файлов, ни сети. Входящий текст не
 * сохраняется и никуда не уходит.
 */
class Service_Nj664_Decode
{
	/** Как именно удалось раскодировать (что мы «починили» во входе). */
	const MODE_RAW     = 'raw';       // уже готовый JSON
	const MODE_SLASHES = 'slashes';   // экранирование: \" \\ \n
	const MODE_URL     = 'url';       // URL-кодирование: %7B%22…
	const MODE_HTML    = 'html';      // HTML-сущности: &quot; &amp;
	const MODE_QUOTED  = 'quoted';    // JSON внутри кавычек: "{...}"

	/** Предел входа: 2 МБ текста хватает для любого ответа API. */
	const MAX_BYTES = 2097152;

	/** Подписи режимов для человека. */
	public static function mode_label($mode)
	{
		$map = [
			self::MODE_RAW     => 'как есть — это уже JSON',
			self::MODE_SLASHES => 'снято экранирование (\\" и \\n)',
			self::MODE_URL     => 'раскодировано из URL (%7B%22…)',
			self::MODE_HTML    => 'раскодированы HTML-сущности (&quot;)',
			self::MODE_QUOTED  => 'раскрыта строка в кавычках',
		];

		return (string) Arr::get($map, (string) $mode, 'как есть');
	}

	/** Пример: экранированный JSON — ровно тот вид, с которым и приходят. */
	public static function sample()
	{
		return '{"InsuranceCompanies":{"source":"investopedia.com","top":[{"No":"1","Name":"Berkshire Hathaway (BRK.A)","Market Capitalization":"$507.04 billion"},{"No":"2","Name":"JPMorgan Chase","Market Capitalization":"$496.6 billion"}]},"updated":"2026-10-09"}';
	}

	/**
	 * Раскодировать вход в структуру PHP.
	 *
	 * Пробуем по порядку: как есть → снять экранирование → URL-кодирование →
	 * HTML-сущности → строка в кавычках. Первый удачный вариант и есть ответ;
	 * в `mode` пишем, что именно пришлось поправить, — человеку важно знать,
	 * что у него на входе.
	 *
	 * @param  string $input
	 * @return array ['ok' => bool, 'data' => mixed, 'mode' => string,
	 *                'text' => string (нормализованный JSON),
	 *                'error' => string, 'line' => int, 'col' => int]
	 */
	public static function decode($input)
	{
		$out = [
			'ok'    => FALSE,
			'data'  => NULL,
			'mode'  => self::MODE_RAW,
			'text'  => '',
			'error' => '',
			'line'  => 0,
			'col'   => 0,
		];

		$input = trim((string) $input);

		if ($input === '')
		{
			$out['error'] = 'Пусто: вставьте JSON или закодированную строку.';

			return $out;
		}

		if (strlen($input) > self::MAX_BYTES)
		{
			$out['error'] = 'Слишком много: больше '.round(self::MAX_BYTES / 1048576).' МБ за один раз нельзя.';

			return $out;
		}

		// Порядок важен: «как есть» первый, чтобы честный JSON не трогать
		// никакими догадками (stripslashes портит корректные данные).
		$tries = [
			self::MODE_RAW     => $input,
			self::MODE_SLASHES => self::_unslash($input),
			self::MODE_URL     => self::_urldecode($input),
			self::MODE_HTML    => self::_html_decode($input),
			self::MODE_QUOTED  => self::_unwrap_quoted($input),
		];

		foreach ($tries as $mode => $text)
		{
			if ($text === '' OR $text === $input AND $mode !== self::MODE_RAW)
			{
				continue;
			}

			$data = json_decode($text, TRUE);

			if (json_last_error() === JSON_ERROR_NONE)
			{
				$out['ok']   = TRUE;
				$out['data'] = $data;
				$out['mode'] = (string) $mode;
				$out['text'] = $text;

				return $out;
			}
		}

		// Ни один вариант не подошёл — говорим, где именно сломалось.
		$out['error'] = self::_error_text(json_last_error());

		$spot = self::_broken_spot($input);
		$out['line'] = (int) Arr::get($spot, 'line', 0);
		$out['col']  = (int) Arr::get($spot, 'col', 0);

		return $out;
	}

	/** Красивый JSON: 2 пробела, юникод и слэши не экранируем. */
	public static function beautify($data)
	{
		return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** Компактный JSON: одна строка, без пробелов. */
	public static function minify($data)
	{
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * PHP-представление: как его печатает print_r().
	 *
	 * Иначе в логе и в PHP-коде JSON выглядит не так, как его увидит скрипт —
	 * а человеку нужен именно вид «глазами PHP».
	 */
	public static function php_array($data, $level = 0)
	{
		$pad = str_repeat('    ', $level);

		if ( ! is_array($data))
		{
			return $pad.self::_php_scalar($data);
		}

		if ( ! $data)
		{
			return $pad.'Array'."\n".$pad.'('."\n".$pad.')';
		}

		$out = $pad.'Array'."\n".$pad.'('."\n";

		foreach ($data as $key => $value)
		{
			$out .= $pad.'    ['.$key.'] => ';

			if (is_array($value))
			{
				// Вложенный массив печатается на той же строке, что и ключ,
				// поэтому его отступ — на два уровня глубже, а у первой
				// строки («Array») ведущие пробелы снимаем. print_r делает
				// ровно так же и после вложенного массива оставляет пустую строку.
				$out .= ltrim(self::php_array($value, $level + 2))."\n\n";
			}
			else
			{
				$out .= self::_php_scalar($value)."\n";
			}
		}

		return $out.$pad.')';
	}

	/** Скаляр в том виде, как его печатает PHP. */
	protected static function _php_scalar($value)
	{
		if ($value === NULL)
		{
			return '';
		}

		if ($value === TRUE)
		{
			return '1';
		}

		if ($value === FALSE)
		{
			return '';
		}

		if (is_int($value) OR is_float($value))
		{
			return (string) $value;
		}

		return (string) $value;
	}

	/**
	 * Готовый PHP-код: строка с JSON, вызов json_decode() и разбор результата.
	 * Его копируют в скрипт целиком — поэтому он синтаксически целый.
	 */
	public static function php_code($data)
	{
		$json  = self::minify($data);
		$lines = [];

		$lines[] = '<?php';
		$lines[] = '';
		$lines[] = '$json = '.self::_php_string($json).';';
		$lines[] = '';
		$lines[] = '// TRUE вторым аргументом — получить массив, а не stdClass.';
		$lines[] = '$data = json_decode($json, TRUE);';
		$lines[] = '';
		$lines[] = 'if (json_last_error() !== JSON_ERROR_NONE) {';
		$lines[] = '    throw new RuntimeException(\'JSON не разобран: \'.json_last_error_msg());';
		$lines[] = '}';
		$lines[] = '';
		$lines[] = '// Что внутри:';
		$lines[] = '// '.str_replace("\n", "\n// ", self::php_array($data));
		$lines[] = '';
		$lines[] = '// Пример обращения к первому уровню:';

		if (is_array($data) AND $data)
		{
			$first = (string) key($data);

			$lines[] = '// echo $data['.self::_php_string($first).']'.(is_array(Arr::get($data, $first)) ? ' — массив' : ';');
		}
		else
		{
			$lines[] = '// print_r($data);';
		}

		return implode("\n", $lines);
	}

	/** Строковый литерал PHP с экранированием. */
	protected static function _php_string($value)
	{
		return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'";
	}

	/**
	 * Сводка по данным: сколько узлов, какая глубина, сколько ключей.
	 * По ней видно, что разобралось именно то, что ожидалось.
	 */
	public static function stats($data)
	{
		$nodes = 0;
		$keys  = 0;
		$depth = self::_walk($data, $nodes, $keys, 1);

		return [
			'nodes' => (int) $nodes,
			'keys'  => (int) $keys,
			'depth' => (int) $depth,
			'type'  => self::_type_of($data),
		];
	}

	/** Обход структуры: считаем узлы, ключи и максимальную глубину. */
	protected static function _walk($value, &$nodes, &$keys, $depth)
	{
		$nodes++;

		if ( ! is_array($value))
		{
			return $depth;
		}

		$max = $depth;

		foreach ($value as $key => $item)
		{
			$keys++;

			if (is_array($item))
			{
				$max = max($max, self::_walk($item, $nodes, $keys, $depth + 1));
			}
			else
			{
				$nodes++;
			}
		}

		return $max;
	}

	/** Тип корня человеческими словами. */
	protected static function _type_of($data)
	{
		if (is_array($data))
		{
			// Скобки обязательны: в PHP `=` главнее `or`, без них $is_list
			// получил бы только результат первого сравнения.
			$is_list = ($data === [] OR array_keys($data) === range(0, count($data) - 1));

			return $is_list ? 'массив (список)' : 'объект';
		}

		if ($data === NULL)    return 'null';
		if (is_bool($data))    return 'логическое';
		if (is_int($data))     return 'целое число';
		if (is_float($data))   return 'число с плавающей точкой';

		return 'строка';
	}

	// ------------------------------------------------------------------ вход

	/** Снять экранирование \" \\ \n — но не трогать уже корректный JSON. */
	protected static function _unslash($text)
	{
		if (strpos($text, '\\') === FALSE)
		{
			return '';
		}

		$out = @json_decode('"'.str_replace('"', '\\"', $text).'"', TRUE);

		if (json_last_error() === JSON_ERROR_NONE AND is_string($out))
		{
			return $out;
		}

		return stripslashes($text);
	}

	/** URL-кодирование: %7B%22… Плюс «+» как пробел. */
	protected static function _urldecode($text)
	{
		if (strpos($text, '%') === FALSE)
		{
			return '';
		}

		return urldecode($text);
	}

	/** HTML-сущности: &quot; &amp; &#34; */
	protected static function _html_decode($text)
	{
		if (strpos($text, '&') === FALSE)
		{
			return '';
		}

		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/** JSON, завёрнутый в кавычки: "{...}" или «'{\"a\":1}'». */
	protected static function _unwrap_quoted($text)
	{
		$first = mb_substr($text, 0, 1);
		$last  = mb_substr($text, -1);

		if (($first === '"' OR $first === "'") AND $last === $first)
		{
			return mb_substr($text, 1, -1);
		}

		return '';
	}

	// ------------------------------------------------------------- ошибки

	/** Сообщение PHP о ошибке JSON — человеческими словами. */
	protected static function _error_text($code)
	{
		$map = [
			JSON_ERROR_DEPTH          => 'Слишком глубоко вложенный JSON (предел — 512 уровней).',
			JSON_ERROR_STATE_MISMATCH => 'Неверный или некорректный JSON.',
			JSON_ERROR_CTRL_CHAR      => 'Внутри строки управляющий символ — его там быть не должно.',
			JSON_ERROR_SYNTAX         => 'Синтаксическая ошибка: проверьте запятые, кавычки и скобки.',
			JSON_ERROR_UTF8           => 'Некорректный UTF-8: строка битая или в другой кодировке.',
		];

		return (string) Arr::get($map, (int) $code, 'JSON не разобран: '.json_last_error_msg());
	}

	/**
	 * Где сломалось: грубый разборщик, который ищет первую «не ту» скобку
	 * или незакрытую строку. PHP не сообщает позицию, а без неё в большом
	 * ответе API искать ошибку приходится глазами.
	 *
	 * @return array ['line' => int, 'col' => int, 'hint' => string]
	 */
	protected static function _broken_spot($text)
	{
		$stack  = [];
		$in_str = FALSE;
		$esc    = FALSE;
		$line   = 1;
		$col    = 0;
		$pairs  = [']' => '[', '}' => '{'];

		$len = strlen($text);

		for ($i = 0; $i < $len; $i++)
		{
			$ch = $text[$i];

			if ($ch === "\n")
			{
				$line++;
				$col = 0;

				// Строка не может перейти на новую строку: это почти всегда
				// и есть сломанное место.
				if ($in_str)
				{
					return ['line' => $line - 1, 'col' => $col, 'hint' => 'строка не закрыта кавычкой'];
				}

				continue;
			}

			$col++;

			if ($in_str)
			{
				if ($esc)          { $esc = FALSE; continue; }
				if ($ch === '\\')  { $esc = TRUE; continue; }
				if ($ch === '"')   { $in_str = FALSE; }

				continue;
			}

			if ($ch === '"')
			{
				$in_str = TRUE;

				continue;
			}

			if ($ch === '{' OR $ch === '[')
			{
				$stack[] = $ch;

				continue;
			}

			if ($ch === '}' OR $ch === ']')
			{
				$open = array_pop($stack);

				if ($open !== $pairs[$ch])
				{
					return [
						'line' => $line,
						'col'  => $col,
						'hint' => 'закрывающая '.$ch.' не совпадает с открывающей '.($open === NULL ? '(ничего)' : $open),
					];
				}
			}
		}

		if ($in_str)
		{
			return ['line' => $line, 'col' => $col, 'hint' => 'строка не закрыта кавычкой'];
		}

		if ($stack)
		{
			$open = array_pop($stack);

			return ['line' => $line, 'col' => $col, 'hint' => 'не закрыта '.$open];
		}

		return ['line' => 0, 'col' => 0, 'hint' => ''];
	}
}
