<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Диагностика платформы: единый набор проверок для CLI (bin/console dev:doctor)
 * и веб-страницы самопроверки (/prx100/selfcheck).
 *
 * 25.11.2026: миграция Д1 сломала два файла так, что текстовый контроль парности
 * кавычек это пропустил, а прод упал с ParseError. Логика доктора жила в CLI и
 * была недоступна из браузера — вынесена в сервис, чтобы после деплоя
 * администратор прогонял те же проверки кнопкой; веб-вариант дополнительно
 * компилирует все php-файлы (php -l) и показывает точное место каждой ошибки.
 *
 * run() возвращает плоский список строк отчёта:
 *   ['status' => 'OK'|'WARN'|'FAIL', 'group' => строка-раздел, 'message' => текст]
 *
 * Все проверки только читают состояние; php -l компилирует файлы, не исполняя их.
 */
class Service_Prx103_Doctor {

	/** @var string[] каталоги, пропускаемые при сборе php-файлов */
	protected const LINT_SKIP_DIRS = [
		'vendor', 'node_modules', '.git', 'uploads', 'avatar',
		'img', 'storage', 'sessions', 'cache', 'logs',
	];

	/** @var int сколько ошибок синтаксиса показывать целиком */
	protected const LINT_MAX_REPORTED = 50;

	public static function run(bool $with_lint = FALSE): array
	{
		$rows = [];

		self::_check_php($rows);
		self::_check_extensions($rows);
		self::_check_env($rows);
		self::_check_dirs($rows);

		if (self::_check_database($rows))
		{
			self::_check_migrations($rows);
			self::_check_queue($rows);
		}

		self::_check_modules_docs($rows);

		if ($with_lint)
		{
			self::_check_syntax($rows);
		}

		return $rows;
	}

	protected static function _add(array &$rows, string $status, string $group, string $message): void
	{
		$rows[] = ['status' => $status, 'group' => $group, 'message' => $message];
	}

	protected static function _check_php(array &$rows): void
	{
		$version = PHP_VERSION;

		if (version_compare($version, '8.4.0', '>='))
		{
			self::_add($rows, 'OK', 'Среда', "PHP $version");
		}
		else
		{
			self::_add($rows, 'FAIL', 'Среда', "PHP $version — требуется 8.4+ (bin/restore-env.sh ставит Sury php8.4)");
		}
	}

	protected static function _check_extensions(array &$rows): void
	{
		foreach (['pgsql', 'mbstring', 'json', 'curl', 'openssl'] as $ext)
		{
			if (extension_loaded($ext))
			{
				self::_add($rows, 'OK', 'Расширения PHP', "ext/$ext");
			}
			else
			{
				self::_add($rows, 'FAIL', 'Расширения PHP', "ext/$ext не загружен — php8.4-pgsql / php8.4-mbstring / php8.4-curl");
			}
		}
	}

	protected static function _check_env(array &$rows): void
	{
		if ( ! is_file(DOCROOT.'../.env') AND ! getenv('DB_DEFAULT_HOST'))
		{
			self::_add($rows, 'FAIL', 'Конфигурация', '.env не найден — bash bin/setup.sh');
			return;
		}

		foreach (['COOKIE_SALT', 'AUTH_HASH_KEY'] as $key)
		{
			if (strlen((string) getenv($key)) < 16)
			{
				self::_add($rows, 'WARN', 'Конфигурация', "$key пустой/короткий — сгенерирует bin/setup.sh");
			}
			else
			{
				self::_add($rows, 'OK', 'Конфигурация', "$key задан");
			}
		}
	}

	protected static function _check_dirs(array &$rows): void
	{
		$dirs = ['application/cache', 'application/logs', 'application/sessions',
			'storage', 'storage/prx212_file_storage/files'];

		foreach ($dirs as $dir)
		{
			$path = rtrim(dirname(APPPATH), '/').'/'.$dir;

			if ($dir === 'storage' OR strpos($dir, 'storage/') === 0)
			{
				try
				{
					$path = Storage_Path::absolute($path);
				}
				catch (InvalidArgumentException $e)
				{
					self::_add($rows, 'FAIL', 'Каталоги', "$dir: ".$e->getMessage());
					continue;
				}
			}

			if ( ! is_dir($path))
			{
				self::_add($rows, 'FAIL', 'Каталоги', "$dir не найден — подготовьте каталоги через bash bin/setup.sh");
			}
			elseif (is_writable($path))
			{
				self::_add($rows, 'OK', 'Каталоги', "$dir записываем");
			}
			else
			{
				self::_add($rows, 'FAIL', 'Каталоги', "$dir не записываем — проверьте владельца и права процесса php");
			}
		}
	}

	protected static function _check_database(array &$rows): bool
	{
		try
		{
			$version = Prx_Db::one('SHOW server_version');
			self::_add($rows, 'OK', 'База данных', 'PostgreSQL '.((array) $version)['server_version']);
			return TRUE;
		}
		catch (Exception $e)
		{
			self::_add($rows, 'FAIL', 'База данных', 'PostgreSQL недоступен: '.$e->getMessage());
			return FALSE;
		}
	}

	protected static function _check_migrations(array &$rows): void
	{
		try
		{
			$applied = (int) Prx_Db::one('SELECT COUNT(*) AS c FROM migrations_pg')['c'];
			self::_add($rows, 'OK', 'База данных', "миграции применены: $applied");
		}
		catch (Exception $e)
		{
			self::_add($rows, 'WARN', 'База данных', 'таблица migrations_pg отсутствует — bin/console db:migrate');
		}
	}

	protected static function _check_queue(array &$rows): void
	{
		try
		{
			$row = Service_Prx120_JobQueue::instance()->stats();

			$parts = [];
			foreach ($row as $status => $count)
			{
				if ($count > 0)
				{
					$parts[] = $status.': '.$count;
				}
			}

			self::_add($rows, 'OK', 'База данных', 'очередь prx_120_jobs'.($parts ? ' ('.implode(', ', $parts).')' : ' (пуста)'));
		}
		catch (Exception $e)
		{
			self::_add($rows, 'WARN', 'База данных', 'таблицы очереди prx_120_* отсутствуют — bin/console db:migrate');
		}
	}

	protected static function _check_modules_docs(array &$rows): void
	{
		$dir = APPPATH.'modules';
		$missing = [];

		foreach (scandir($dir) as $name)
		{
			if ($name[0] === '.' OR ! is_dir($dir.'/'.$name))
			{
				continue;
			}

			if ( ! is_file($dir.'/'.$name.'/AGENTS.md'))
			{
				$missing[] = $name;
			}
		}

		if ($missing)
		{
			self::_add($rows, 'WARN', 'Модули', 'нет AGENTS.md у модулей: '.implode(', ', $missing).' (шаблон docs/templates/module-AGENTS.md)');
		}
		else
		{
			self::_add($rows, 'OK', 'Модули', 'AGENTS.md есть у всех модулей');
		}
	}

	/**
	 * Компиляция всех php-файлов (php -l) одним дочерним процессом: в stdin
	 * уходит список файлов, в stdout — JSON с ошибками. Так один процесс
	 * вместо сотни, а точное место ошибки печатает сам php.
	 *
	 * 25.11.2026, прод: fwrite(): Broken pipe — под веб-SAPI (fpm/cgi)
	 * PHP_BINARY не CLI-интерпретатор и умирал до чтения stdin. Теперь ищем
	 * настоящий CLI коротким зондом (строго PHP_SAPI === 'cli' и доступный
	 * exec внутри дочернего php.ini); если CLI нет — WARN с подсказкой
	 * make lint, страница не падает. stderr потомка — в /dev/null, чтобы
	 * переполнение его пайпа не подвесило чтение stdout.
	 */
	protected static function _check_syntax(array &$rows): void
	{
		$files = self::_php_files();

		if ($files === [])
		{
			self::_add($rows, 'WARN', 'Синтаксис PHP', 'php-файлы не найдены');
			return;
		}

		if ( ! function_exists('proc_open'))
		{
			self::_add($rows, 'WARN', 'Синтаксис PHP',
				'proc_open запрещён на этом хостинге — выполните проверку вручную: make lint');
			return;
		}

		$php = self::_cli_binary();

		if ($php === NULL)
		{
			self::_add($rows, 'WARN', 'Синтаксис PHP',
				'CLI-интерпретатор PHP не найден (веб-режим '.PHP_SAPI.') — сайт не затронут; '
				.'запустите проверку по SSH: make lint');
			return;
		}

		$probe = tempnam(sys_get_temp_dir(), 'prxlnt');

		if ($probe === FALSE)
		{
			self::_add($rows, 'WARN', 'Синтаксис PHP', 'не удалось создать временный файл для php -l');
			return;
		}

		file_put_contents($probe, <<<'PROBE'
<?php
$lint = $argv[2] ?? 'php';
$list = json_decode(stream_get_contents(STDIN), TRUE) ?: [];
$bad = [];
foreach ($list as $f) {
    $out = [];
    exec(escapeshellarg($lint).' -l '.escapeshellarg($f).' 2>&1', $out, $code);
    if ($code !== 0) {
        $bad[] = [$f, implode(' ', array_filter(array_map('trim', $out)))];
    }
}
echo json_encode(['total' => count($list), 'bad' => $bad]);
PROBE);

		$res = NULL;
		$proc = @proc_open([$php, $probe, $php],
			[['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'r']], $pipes);

		if (is_resource($proc))
		{
			$written = @fwrite($pipes[0], json_encode($files));
			fclose($pipes[0]);

			if ($written !== FALSE)
			{
				$res = json_decode((string) @stream_get_contents($pipes[1]), TRUE);
			}

			fclose($pipes[1]);
			proc_close($proc);
		}

		@unlink($probe);

		$total = (int) ($res['total'] ?? 0);
		$bad = is_array($res['bad'] ?? NULL) ? $res['bad'] : [];

		if ($total === 0 AND $bad === [])
		{
			self::_add($rows, 'WARN', 'Синтаксис PHP',
				"дочерний процесс php не отработал ($php) — выполните make lint по SSH");
			return;
		}

		if ($bad === [])
		{
			self::_add($rows, 'OK', 'Синтаксис PHP', "php -l: $total файлов — ошибок нет");
			return;
		}

		foreach ($bad as $i => [$file, $error])
		{
			if ($i >= self::LINT_MAX_REPORTED)
			{
				self::_add($rows, 'FAIL', 'Синтаксис PHP',
					'…и ещё '.(count($bad) - self::LINT_MAX_REPORTED).' ошибок — полный список: make lint');
				break;
			}

			self::_add($rows, 'FAIL', 'Синтаксис PHP',
				preg_replace('/^Errors parsing [^ ]+/', '', $error, 1, $cnt) ?: $error);
		}
	}

	/**
	 * Настоящий CLI-интерпретатор для дочерних запусков. Под веб-SAPI
	 * (fpm/cgi) PHP_BINARY скрипты не исполняет (прод 25.11.2026: Broken
	 * pipe), а exec может быть запрещён php.ini — зонд требует строго
	 * PHP_SAPI === 'cli' И доступный exec в дочернем процессе. /usr/bin/php
	 * проверяем первым: на типовом хостинге это CLI, и бинарник fpm не
	 * зондируется вовсе.
	 */
	protected static function _cli_binary(): ?string
	{
		$candidates = [];

		foreach (['/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php8.4', '/usr/bin/php8.3', PHP_BINARY] as $cand)
		{
			if ($cand !== '' AND ! in_array($cand, $candidates, TRUE) AND @is_executable($cand))
			{
				$candidates[] = $cand;
			}
		}

		foreach ($candidates as $cand)
		{
			$answer = self::_spawn([$cand, '-r',
				'echo PHP_SAPI === \'cli\' && function_exists(\'exec\') ? \'ok\' : \'no\';']);

			if ($answer === 'ok')
			{
				return $cand;
			}
		}

		return NULL;
	}

	/** Разовый дочерний процесс без stdin; stdout — строка или NULL. */
	protected static function _spawn(array $cmd): ?string
	{
		$pipes = [];
		$proc = @proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'r']], $pipes);

		if ( ! is_resource($proc))
		{
			return NULL;
		}

		fclose($pipes[0]);
		$out = @stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($proc);

		return is_string($out) ? trim($out) : NULL;
	}

	protected static function _php_files(): array
	{
		$root = rtrim(dirname(APPPATH), '/');
		$out = [];

		foreach (['application', 'modules', 'public_html', 'bin'] as $dir)
		{
			$base = $root.'/'.$dir;

			if ( ! is_dir($base))
			{
				continue;
			}

			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

			foreach ($it as $file)
			{
				if ($file->getExtension() !== 'php')
				{
					continue;
				}

				$parts = explode('/', $file->getPathname());

				foreach (self::LINT_SKIP_DIRS as $skip)
				{
					if (in_array($skip, $parts, TRUE))
					{
						continue 2;
					}
				}

				$out[] = $file->getPathname();
			}
		}

		sort($out);

		return $out;
	}
}
