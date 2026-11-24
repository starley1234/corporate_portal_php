<?php

/**
 * Мини-раннер тестов для сред без composer/packagist (закрытая сеть).
 *
 * phpunit ставится через composer (require-dev), а packagist.org недоступен
 * в изолированных песочницах. Этот раннер даёт минимальную совместимость:
 * реализует используемые тестами ассерты PHPUnit и запускает классы
 * tests/Smoke и tests/Unit. Результат: список проверок + код возврата.
 *
 *   php tests/Support/mini_runner.php            # все тесты
 *   php tests/Support/mini_runner.php VatService # по подстроке имени класса
 *
 * В обычной среде используйте bin/test (phpunit) — этот файл только fallback.
 */

namespace PHPUnit\Framework {

	class AssertionFailedError extends \Exception {}
	class SkippedTest extends \Exception {}

	abstract class TestCase
	{
		public static function fail(string $message): void
		{
			throw new AssertionFailedError($message);
		}

		protected function _ok(bool $condition, string $kind, string $message, string $detail = ''): void
		{
			if ( ! $condition)
			{
				self::fail(($message ?: $kind).($detail !== '' ? ': '.$detail : ''));
			}
		}

		public function assertSame($expected, $actual, string $message = ''): void
		{
			$this->_ok($expected === $actual, 'assertSame', $message,
				'ожидалось '.var_export($expected, TRUE).', получено '.var_export($actual, TRUE));
		}

		public function assertEquals($expected, $actual, string $message = ''): void
		{
			$this->_ok($expected == $actual, 'assertEquals', $message,
				'ожидалось '.var_export($expected, TRUE).', получено '.var_export($actual, TRUE));
		}

		public function assertNotSame($expected, $actual, string $message = ''): void
		{
			$this->_ok($expected !== $actual, 'assertNotSame', $message,
				'ожидалось, что значения будут различаться строго');
		}

		public function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
		{
			$matched = @preg_match($pattern, $string);
			$this->_ok($matched === 1, 'assertMatchesRegularExpression', $message,
				$matched === FALSE ? 'некорректное регулярное выражение' : 'строка не совпала с '.$pattern);
		}

		public function assertFileExists(string $filename, string $message = ''): void
		{
			$this->_ok(is_file($filename), 'assertFileExists', $message, 'файл не найден: '.$filename);
		}

		public function assertFileDoesNotExist(string $filename, string $message = ''): void
		{
			$this->_ok( ! file_exists($filename), 'assertFileDoesNotExist', $message,
				'файл существует, хотя не должен: '.$filename);
		}

		public function assertEqualsWithDelta($expected, $actual, float $delta, string $message = ''): void
		{
			$this->_ok(is_numeric($expected) AND is_numeric($actual) AND abs($expected - $actual) <= $delta,
				'assertEqualsWithDelta', $message, "|$expected - $actual| > $delta");
		}

		public function assertTrue($condition, string $message = ''): void
		{
			$this->_ok($condition === TRUE, 'assertTrue', $message, 'условие ложно');
		}

		public function assertFalse($condition, string $message = ''): void
		{
			$this->_ok($condition === FALSE, 'assertFalse', $message, 'условие истинно');
		}

		public function assertNotFalse($condition, string $message = ''): void
		{
			$this->_ok($condition !== FALSE, 'assertNotFalse', $message);
		}

		public function assertNotNull($value, string $message = ''): void
		{
			$this->_ok($value !== NULL, 'assertNotNull', $message);
		}

		public function assertNull($value, string $message = ''): void
		{
			$this->_ok($value === NULL, 'assertNull', $message);
		}

		public function assertNotEmpty($value, string $message = ''): void
		{
			$this->_ok( ! empty($value), 'assertNotEmpty', $message);
		}

		public function assertGreaterThan($expected, $actual, string $message = ''): void
		{
			$this->_ok($actual > $expected, 'assertGreaterThan', $message, "$actual <= $expected");
		}

		public function assertGreaterThanOrEqual($expected, $actual, string $message = ''): void
		{
			$this->_ok($actual >= $expected, 'assertGreaterThanOrEqual', $message, "$actual < $expected");
		}

		public function assertLessThan($expected, $actual, string $message = ''): void
		{
			$this->_ok($actual < $expected, 'assertLessThan', $message, "$actual >= $expected");
		}

		public function assertLessThanOrEqual($expected, $actual, string $message = ''): void
		{
			$this->_ok($actual <= $expected, 'assertLessThanOrEqual', $message, "$actual > $expected");
		}

		public function assertCount(int $expected, $haystack, string $message = ''): void
		{
			$this->_ok(count($haystack) === $expected, 'assertCount', $message,
				'ожидалось '.$expected.', получено '.count($haystack));
		}

		public function assertContains($needle, $haystack, string $message = ''): void
		{
			$this->_ok(in_array($needle, (array) $haystack, TRUE), 'assertContains', $message,
				'элемент не найден');
		}

		public function assertNotContains($needle, $haystack, string $message = ''): void
		{
			$this->_ok( ! in_array($needle, (array) $haystack, TRUE), 'assertNotContains', $message,
				'элемент не должен был найтись');
		}

		/**
		 * Пропуск теста. Настоящий phpunit бросает служебное исключение и
		 * помечает тест как skipped; здесь достаточно того же исключения —
		 * раннер ловит Throwable и не считает пропуск падением.
		 */
		public function markTestSkipped(string $message = ''): void
		{
			throw new SkippedTest($message);
		}

		public function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
		{
			$this->_ok(strncmp($string, $prefix, strlen($prefix)) === 0, 'assertStringStartsWith', $message,
				'строка не начинается с '.$prefix);
		}

		public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
		{
			$this->_ok(strpos($haystack, $needle) !== FALSE, 'assertStringContainsString', $message,
				'подстрока не найдена: '.$needle);
		}

		public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
		{
			$this->_ok(strpos($haystack, $needle) === FALSE, 'assertStringNotContainsString', $message,
				'найдена запрещённая подстрока: '.$needle);
		}

		public function assertArrayHasKey($key, $array, string $message = ''): void
		{
			$this->_ok(is_array($array) AND array_key_exists($key, $array), 'assertArrayHasKey', $message,
				'нет ключа '.var_export($key, TRUE));
		}
		public function assertArrayNotHasKey($key, $array, string $message = ''): void
		{
			$this->_ok( ! array_key_exists($key, (array) $array), 'assertArrayNotHasKey', $message,
				'ключ «'.$key.'» не должен был найтись');
		}

	}
}

namespace {

	require dirname(__DIR__).'/bootstrap.php';

	// --- прогон -----------------------------------------------------------------
	$filter = $argv[1] ?? '';
	$files = array_merge(glob(dirname(__DIR__).'/Smoke/*.php'), glob(dirname(__DIR__).'/Unit/*.php'));
	$passed = $failed = $skipped = 0;

	/**
	 * Вызвать protected-хук теста (setUp/tearDown) — как это делает phpunit.
	 *
	 * Зачем: хуки объявляются protected, снаружи их не позвать, а без tearDown
	 * тест, работающий с БД (tests/Smoke/LabelRunTest.php), оставлял бы за собой
	 * строки: phpunit хуки вызывает всегда, мини-раннер — нет, и «мусор после
	 * тестов» копился бы только в закрытой песочнице.
	 */
	$call_hook = static function ($object, string $name): void {
		if ( ! method_exists($object, $name))
		{
			return;
		}

		$method = new ReflectionMethod($object, $name);
		$method->setAccessible(TRUE);
		$method->invoke($object);
	};

	foreach ($files as $file)
	{
		$base = basename($file, '.php');
		if ($filter !== '' && stripos($base, $filter) === FALSE)
		{
			continue;
		}

		require_once $file;

		$r = new ReflectionClass($base);
		if ( ! $r->isInstantiable())
		{
			continue;
		}

		$instance = $r->newInstance();
		foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
		{
			if (strpos($method->name, 'test') !== 0)
			{
				continue;
			}

			try
			{
				$call_hook($instance, 'setUp');

				try
				{
					$instance->{$method->name}();
					$passed++;
					echo "  ✔ {$base}::{$method->name}\n";
				}
				finally
				{
					// finally, а не «после try»: уборка обязана пройти и когда
					// тест упал на середине (иначе за ним остаются строки в БД).
					$call_hook($instance, 'tearDown');
				}
			}
			catch (PHPUnit\Framework\SkippedTest $e)
			{
				$skipped++;
				echo "  ○ {$base}::{$method->name}: пропущен — ".$e->getMessage()."\n";
			}
			catch (Throwable $e)
			{
				$failed++;
				$at = ($e instanceof PHPUnit\Framework\AssertionFailedError)
					? ''
					: ' ['.basename($e->getFile()).':'.$e->getLine().']';
				echo "  ✘ {$base}::{$method->name}: ".get_class($e).': '.$e->getMessage()."{$at}\n";
			}
		}
	}

	echo "\nИтог: пройдено {$passed}, упало {$failed}, пропущено {$skipped}\n";
	exit($failed > 0 ? 1 : 0);
}
