<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Диагностика окружения и платформы: bin/console dev:doctor
 *
 * Проверяет: PHP и расширения, .env и секреты, каталоги для записи,
 * соединение с PostgreSQL, миграции, таблицы очереди, покрытие модулей
 * AGENTS.md. Поломанное показывает с подсказкой как чинить.
 */
class Task_Dev_Doctor extends Minion_Task {

    protected $_options = [];

    /** @var array [строка статуса, сообщение, критично ли] */
    protected $_report = [];

    protected function _execute(array $params)
    {
        $this->_check_php();
        $this->_check_extensions();
        $this->_check_env();
        $this->_check_dirs();

        if ($this->_check_database())
        {
            $this->_check_migrations();
            $this->_check_queue();
        }

        $this->_check_modules_docs();

        // --- отчёт -----------------------------------------------------------
        $lines = ['', 'PRL — диагностика', str_repeat('-', 60)];
        foreach ($this->_report as [$status, $message])
        {
            $lines[] = "[$status] $message";
        }

        $fails = count(array_filter($this->_report, function ($r) {
            return $r[0] === 'FAIL';
        }));

        $lines[] = str_repeat('-', 60);
        $lines[] = $fails > 0
            ? "Проблем: $fails. Подсказки по разворачиванию — docs/ENVIRONMENT.md"
            : 'Всё критичное в порядке. Хорошей работы!';

        Minion_CLI::write(implode("\n", $lines));
    }

    protected function _ok($message)   { $this->_report[] = [' OK ', $message]; }
    protected function _warn($message) { $this->_report[] = ['WARN', $message]; }
    protected function _fail($message) { $this->_report[] = ['FAIL', $message]; }

    protected function _check_php()
    {
        $version = PHP_VERSION;
        if (version_compare($version, '8.4.0', '>='))
        {
            $this->_ok("PHP $version");
        }
        else
        {
            $this->_fail("PHP $version — требуется 8.4+ (bin/restore-env.sh ставит Sury php8.4)");
        }
    }

    protected function _check_extensions()
    {
        foreach (['pgsql', 'mbstring', 'json', 'curl', 'openssl'] as $ext)
        {
            if (extension_loaded($ext))
            {
                $this->_ok("ext/$ext");
            }
            else
            {
                $this->_fail("ext/$ext не загружен — php8.4-pgsql / php8.4-mbstring / php8.4-curl");
            }
        }
    }

    protected function _check_env()
    {
        if ( ! is_file(DOCROOT.'../.env') AND ! getenv('DB_DEFAULT_HOST'))
        {
            $this->_fail('.env не найден — bash bin/setup.sh');
            return;
        }

        foreach (['COOKIE_SALT', 'AUTH_HASH_KEY'] as $key)
        {
            if (strlen((string) getenv($key)) < 16)
            {
                $this->_warn("$key пустой/короткий — сгенерирует bin/setup.sh");
            }
            else
            {
                $this->_ok("$key задан");
            }
        }
    }

    protected function _check_dirs()
    {
        foreach (['application/cache', 'application/logs', 'application/sessions', 'public_html/uploads'] as $dir)
        {
            $path = DOCROOT.'../'.$dir;
            if ( ! is_dir($path))
            {
                @mkdir($path, 0775, TRUE);
            }

            if (is_writable($path))
            {
                $this->_ok("$dir записываем");
            }
            else
            {
                $this->_fail("$dir не записываем — chmod/права процесса php");
            }
        }
    }

    protected function _check_database()
    {
        try
        {
            $db = Database::instance();
            $version = $db->query(Database::SELECT, 'SHOW server_version', FALSE)->current();
            $this->_ok('PostgreSQL '.((array) $version)['server_version']);
            return TRUE;
        }
        catch (Exception $e)
        {
            $this->_fail('PostgreSQL недоступен: '.$e->getMessage());
            return FALSE;
        }
    }

    protected function _check_migrations()
    {
        $db = Database::instance();
        try
        {
            $applied = (int) $db->query(Database::SELECT,
                'SELECT COUNT(*) AS c FROM migrations_pg', FALSE)->current()['c'];
            $this->_ok("миграции применены: $applied");
        }
        catch (Exception $e)
        {
            $this->_warn('таблица migrations_pg отсутствует — bin/console db:migrate');
        }
    }

    protected function _check_queue()
    {
        $db = Database::instance();
        try
        {
            $row = $db->query(Database::SELECT,
                "SELECT status, COUNT(*) AS c FROM prl_120_jobs GROUP BY status", FALSE);

            $parts = [];
            foreach ($row as $r)
            {
                $parts[] = $r['status'].': '.$r['c'];
            }
            $this->_ok('очередь prl_120_jobs'.($parts ? ' ('.implode(', ', $parts).')' : ' (пуста)'));
        }
        catch (Exception $e)
        {
            $this->_warn('таблицы очереди prl_120_* отсутствуют — bin/console db:migrate');
        }
    }

    protected function _check_modules_docs()
    {
        $dir = APPPATH.'modules';
        $missing = [];

        foreach (scandir($dir) as $name)
        {
            if ($name[0] === '.' OR ! is_dir($dir.'/'.$name))
                continue;

            if ( ! is_file($dir.'/'.$name.'/AGENTS.md'))
            {
                $missing[] = $name;
            }
        }

        if ($missing)
        {
            $this->_warn('нет AGENTS.md у модулей: '.implode(', ', $missing).' (шаблон docs/templates/module-AGENTS.md)');
        }
        else
        {
            $this->_ok('AGENTS.md есть у всех модулей');
        }
    }
}
