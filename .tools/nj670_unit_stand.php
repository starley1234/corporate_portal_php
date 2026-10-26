<?php
/**
 * ProcessHub: юнит-проверки модуля без PHPUnit и без PHP в системе.
 *
 * `tests/Unit/Nj670ProcessHubTest.php` — обычный PHPUnit-тест, но в песочнице
 * Arena нет PHP CLI, а composer-зависимостей нет вовсе. Здесь мини-шим
 * PHPUnit: те же assert-методы, тот же порядок «метод = тест», плюс заглушки
 * Arr/UTF8/HTML/конфига. Сервисы модуля загружаются настоящие.
 *
 * Запуск:
 *   cd /tmp/phpwasm && node phpwasm_stand.mjs .tools/nj670_unit_stand.php
 *
 * Ограничение: проверки, которым нужна БД, здесь падают осознанно — тест
 * модуля обязан оставаться «чистым» (без SQL), иначе он не поедет в CI.
 */

namespace {

if ( ! defined('SYSPATH')) { define('SYSPATH', '/app/system/'); }
if ( ! defined('DOCROOT')) { define('DOCROOT', '/app/'); }

class Arr
{
	public static function get($a, $k, $d = NULL)
	{
		return (is_array($a) AND array_key_exists($k, $a)) ? $a[$k] : $d;
	}

	public static function path($a, $p, $d = NULL)
	{
		$cur = $a;

		foreach (explode('.', $p) as $k)
		{
			if ( ! is_array($cur) OR ! array_key_exists($k, $cur)) { return $d; }
			$cur = $cur[$k];
		}

		return $cur;
	}

	public static function set(&$a, $p, $v = NULL)
	{
		$cur = &$a;

		foreach (explode('.', $p) as $k) { $cur = &$cur[$k]; }

		$cur = $v;

		return $a;
	}
}

class HTML
{
	public static function chars($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

class UTF8
{
	public static function substr($s, $from, $len = NULL)
	{
		return mb_substr((string) $s, (int) $from, $len === NULL ? NULL : (int) $len, 'UTF-8');
	}

	public static function strlen($s) { return mb_strlen((string) $s, 'UTF-8'); }

	public static function strtolower($s) { return mb_strtolower((string) $s, 'UTF-8'); }

	public static function strrpos($s, $needle, $offset = 0)
	{
		return mb_strrpos((string) $s, (string) $needle, (int) $offset, 'UTF-8');
	}
}

class Kohana
{
	public static $config;
	public static $log;
	public static $charset = 'utf-8';
}

class Kohana_Config
{
	public function load($name)
	{
		$file = DOCROOT.'application/modules/nj670_processhub/config/'.$name.'.php';

		return is_file($file) ? require $file : [];
	}
}

class Log
{
	const WARNING = 4;

	public function add($level, $message, $args = []) {}
}

Kohana::$config = new Kohana_Config;
Kohana::$log = new Log;

class Valid
{
	public static function not_empty($v) { return ! ($v === NULL OR $v === ''); }
}

// Родители контроллеров: тест проверяет только наличие action-методов.
class Controller_Erp100_Base {}

class Controller_Nj120_Api extends Controller_Erp100_Base {}

}

// --- мини-шим PHPUnit ------------------------------------------------------

namespace PHPUnit\Framework {

	class AssertionFailed extends \Exception {}

	class TestCase
	{
		protected function ok($cond, $message)
		{
			if ( ! $cond) { throw new AssertionFailed($message); }
		}

		public function assertTrue($v, $m = '') { $this->ok($v === TRUE, $m ?: 'ожидалось true'); }
		public function assertFalse($v, $m = '') { $this->ok($v === FALSE, $m ?: 'ожидалось false'); }
		public function assertNull($v, $m = '') { $this->ok($v === NULL, $m ?: 'ожидалось null'); }
		public function assertNotFalse($v, $m = '') { $this->ok($v !== FALSE, $m ?: 'ожидалось не false'); }

		public function assertSame($e, $a, $m = '')
		{
			$this->ok($e === $a, $m ?: 'ожидалось '.var_export($e, TRUE).', получено '.var_export($a, TRUE));
		}

		public function assertNotSame($e, $a, $m = '') { $this->ok($e !== $a, $m ?: 'значения совпали'); }

		public function assertCount($n, $a, $m = '')
		{
			$this->ok(count((array) $a) === (int) $n, $m ?: 'ожидалось элементов '.$n.', получено '.count((array) $a));
		}

		public function assertGreaterThan($e, $a, $m = '') { $this->ok($a > $e, $m ?: 'ожидалось больше '.$e); }

		public function assertArrayHasKey($k, $a, $m = '')
		{
			$this->ok(array_key_exists($k, (array) $a), $m ?: 'нет ключа '.$k);
		}

		public function assertArrayNotHasKey($k, $a, $m = '')
		{
			$this->ok( ! array_key_exists($k, (array) $a), $m ?: 'лишний ключ '.$k);
		}

		public function assertContains($needle, $haystack, $m = '')
		{
			$this->ok(in_array($needle, (array) $haystack, TRUE), $m ?: 'нет значения '.var_export($needle, TRUE));
		}

		public function assertNotContains($needle, $haystack, $m = '')
		{
			$this->ok( ! in_array($needle, (array) $haystack, TRUE), $m ?: 'лишнее значение '.var_export($needle, TRUE));
		}

		public function assertStringContainsString($needle, $haystack, $m = '')
		{
			$this->ok(strpos((string) $haystack, (string) $needle) !== FALSE, $m ?: 'нет подстроки '.$needle);
		}

		public function assertStringNotContainsString($needle, $haystack, $m = '')
		{
			$this->ok(strpos((string) $haystack, (string) $needle) === FALSE, $m ?: 'лишняя подстрока '.$needle);
		}

		public function assertStringStartsWith($needle, $haystack, $m = '')
		{
			$this->ok(strpos((string) $haystack, (string) $needle) === 0, $m ?: 'строка не начинается с '.$needle);
		}

		public function assertMatchesRegularExpression($re, $s, $m = '')
		{
			$this->ok((bool) preg_match($re, (string) $s), $m ?: 'нет совпадения '.$re);
		}

		public function fail($message = '')
		{
			throw new AssertionFailed($message ?: 'провал');
		}
	}
}

namespace {

	$dir = DOCROOT.'application/modules/nj670_processhub/classes/';

	// Порядок важен: Access/Mcp/Graph наследуют Repo, поэтому он первым.
	$order = ['Repo', 'Graph', 'Access', 'Mcp', 'Bpmn', 'Compare', 'Demo', 'Diff',
		'Map', 'Mining', 'Ontology', 'Ontologyfeed', 'Process', 'Report', 'Simulate', 'Synthesis'];

	foreach ($order as $name)
	{
		$file = $dir.'Service/Nj670/'.$name.'.php';

		if (is_file($file)) { require_once $file; }
	}
	require_once $dir.'Controller/Nj670/Api.php';

	if (is_file($dir.'Controller/Nj670/Worker.php')) { require_once $dir.'Controller/Nj670/Worker.php'; }

	require_once DOCROOT.'tests/Unit/Nj670ProcessHubTest.php';

	$test = new Nj670ProcessHubTest;
	$methods = get_class_methods($test);
	$passed = 0;
	$failed = [];

	foreach ($methods as $method)
	{
		if (strpos($method, 'test_') !== 0) { continue; }

		try
		{
			$test->$method();
			$passed++;
			echo 'ok   '.$method."\n";
		}
		catch (Throwable $e)
		{
			$failed[] = $method.': '.$e->getMessage();
			echo 'ФЕЙЛ '.$method.': '.$e->getMessage()."\n";
		}
	}

	echo "\n".$passed.' прошло, '.count($failed)." упало\n";
	exit($failed ? 1 : 0);
}
