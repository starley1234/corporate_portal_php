<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Исполнитель расписаний манифестов (`schedule` в config/prx_module.php).
 *
 * До 27.11.2026 секция `schedule` была только описанием: исполнителя не было,
 * и обслуживание очереди prx120, доставка уведомлений prx597 и уборки
 * prx395–400 шли, только если кто-то вручную завёл cron на каждую задачу.
 * Без requeue_stale задача упавшего воркера висела «в работе» до визита админа
 * в монитор, а записи модулей — вечным «Разбирается».
 *
 * Теперь одна строка cron (или сервис `scheduler` в docker-compose):
 *
 *     * * * * *  cd /var/www && bin/console portal:maintain
 *
 * (`portal:maintain` — исторический вход, это тот же schedule:run.)
 * Каждый запуск проходит по манифестам и исполняет записи, у которых наступил
 * срок `every` с прошлого старта. Правила:
 *
 *  - исполняется только `command` вида `bin/console <задача> [--опция=значение]`:
 *    без shell, задача обязана существовать (Minion); запись только с `url`
 *    — внешний пинок, её не трогаем и показываем как «внешняя»;
 *  - каждая запись — отдельным процессом PHP: фатальная ошибка или утечка
 *    памяти одной задачи не роняют остальные; потолок времени `timeout`;
 *  - pg_try_advisory_lock на ключ: длинный запуск не стартует второй раз из
 *    следующей минуты cron, но и не задерживает соседние записи; блокировка
 *    сессионная — умерший исполнитель отпускает её вместе с соединением;
 *  - результат (код выхода, длительность, хвост вывода, серия неудач) — в
 *    prx_103_schedule_runs; его читают schedule:list и dev:doctor.
 */
class Service_Prx103_Schedule
{
    const LOCK_SPACE = 103;

    /** Допуск срока: cron раз в минуту стартует на 59,8 с — «1 minute» не должен пропускать тик. */
    const JITTER_SEC = 10;

    // Регистр сохраняем: Minion ищет файл класса по имени задачи, и
    // `prx120:requeuestale` (как в списке Minion) не находит RequeueStale.php.
    const COMMAND_TASK   = '/^[A-Za-z0-9]+(?::[A-Za-z0-9]+)*$/';
    const COMMAND_OPTION = '/^--[a-z][a-z0-9_]*(?:=[A-Za-z0-9_.,:\/-]*)?$/';

    /** @var array|NULL ключ => запись манифеста (NULL — из Manifest::schedules()) */
    protected $_entries;

    public function __construct(?array $entries = NULL)
    {
        $this->_entries = $entries;
    }

    /** Записи манифестов с разобранными полями: every_sec, argv, kind, problem. */
    public function entries(): array
    {
        $disabled = array_flip((array) self::config('disabled', []));
        $out = [];

        foreach ($this->_entries ?? Manifest::schedules() as $key => $spec)
        {
            $key = (string) $key;
            $every = self::parse_every((string) ($spec['every'] ?? ''));
            $entry = [
                'key'       => $key,
                'module'    => (string) ($spec['module'] ?? ''),
                'label'     => (string) ($spec['label'] ?? $key),
                'every'     => (string) ($spec['every'] ?? ''),
                'every_sec' => $every,
                'timeout'   => max(1, (int) ($spec['timeout'] ?? self::config('timeout', 600))),
                'command'   => (string) ($spec['command'] ?? ''),
                'argv'      => [],
                'kind'      => 'command',
                'problem'   => '',
            ];

            if ((array_key_exists('enabled', $spec) AND ! $spec['enabled']) OR isset($disabled[$key]))
            {
                $entry['kind'] = 'disabled';
            }
            elseif ($entry['command'] === '')
            {
                $entry['kind'] = empty($spec['url']) ? 'invalid' : 'external';
                $entry['problem'] = empty($spec['url']) ? 'нет command' : '';
            }
            elseif ($every === NULL)
            {
                $entry['kind'] = 'invalid';
                $entry['problem'] = 'непонятный every «'.$entry['every'].'»';
            }
            else
            {
                try
                {
                    $entry['argv'] = self::parse_command($entry['command']);
                }
                catch (InvalidArgumentException $e)
                {
                    $entry['kind'] = 'invalid';
                    $entry['problem'] = $e->getMessage();
                }
            }

            $out[$key] = $entry;
        }

        ksort($out);

        return $out;
    }

    /**
     * Один проход: исполнить всё, чему пора.
     *
     * @param  string|NULL $only  только этот ключ
     * @param  bool        $force не смотреть на срок (ручной запуск)
     * @return array ключ => ['result' => ran|skipped|locked|…, 'status' => …]
     */
    public function run_due(?string $only = NULL, bool $force = FALSE): array
    {
        $state = $this->state();
        $report = [];

        foreach ($this->entries() as $key => $entry)
        {
            if ($only !== NULL AND $key !== $only) continue;

            if ($entry['kind'] !== 'command')
            {
                $report[$key] = ['result' => $entry['kind'], 'problem' => $entry['problem']];
                continue;
            }

            $age = $state[$key]['age_sec'] ?? NULL;
            if ( ! $force AND $age !== NULL AND $age < $entry['every_sec'] - self::JITTER_SEC)
            {
                $report[$key] = ['result' => 'not_due', 'next_in_sec' => (int) ceil($entry['every_sec'] - self::JITTER_SEC - $age)];
                continue;
            }

            $report[$key] = $this->run_one($entry);
        }

        if ($only !== NULL AND ! isset($report[$only]))
        {
            $report[$only] = ['result' => 'unknown'];
        }

        return $report;
    }

    /** Исполнить запись под блокировкой и записать итог. */
    public function run_one(array $entry): array
    {
        $key = $entry['key'];
        if ( ! self::_lock($key))
        {
            return ['result' => 'locked'];
        }

        try
        {
            Prx_Db::execute(
                "INSERT INTO prx_103_schedule_runs (key, module, status, started_at, finished_at, exit_code, duration_ms, updated_at)
                 VALUES (:key, :module, 'running', now(), NULL, NULL, NULL, now())
                 ON CONFLICT (key) DO UPDATE SET module = EXCLUDED.module, status = 'running',
                    started_at = now(), finished_at = NULL, exit_code = NULL, duration_ms = NULL, updated_at = now()",
                [':key' => $key, ':module' => $entry['module']]);

            $result = $this->_spawn($this->_process($entry), $entry['timeout']);
            $status = $result['timeout'] ? 'timeout' : ($result['exit_code'] === 0 ? 'ok' : 'failed');
            $failed = $status === 'ok' ? 0 : 1;

            Prx_Db::execute(
                'UPDATE prx_103_schedule_runs SET status = :status, finished_at = now(), exit_code = :exit_code,
                    duration_ms = :ms, output = :output, runs = runs + 1, failures = failures + :failed,
                    consecutive_failures = CASE WHEN :failed = 1 THEN consecutive_failures + 1 ELSE 0 END,
                    updated_at = now()
                 WHERE key = :key',
                [':key' => $key, ':status' => $status, ':exit_code' => $result['exit_code'],
                 ':ms' => $result['duration_ms'], ':failed' => $failed,
                 ':output' => self::_tail($result['output'], (int) self::config('output_bytes', 4000))]);

            if ($failed)
            {
                Log_Plain::event(Log::WARNING, 'prx103_schedule key=:key module=:module status=:status exit=:code ms=:ms', [
                    ':key' => $key, ':module' => $entry['module'], ':status' => $status,
                    ':code' => (string) $result['exit_code'], ':ms' => $result['duration_ms'],
                ]);
            }

            return ['result' => 'ran', 'status' => $status, 'exit_code' => $result['exit_code'], 'duration_ms' => $result['duration_ms']];
        }
        finally
        {
            self::_unlock($key);
        }
    }

    /** Состояние из БД: ключ => строка + age_sec (секунд с последнего старта). */
    public function state(): array
    {
        $rows = Prx_Db::select(
            'SELECT key, module, status, started_at, finished_at, exit_code, duration_ms, output, runs, failures,
                    consecutive_failures, EXTRACT(EPOCH FROM (now() - started_at)) AS age_sec
             FROM prx_103_schedule_runs');
        $out = [];
        foreach ($rows as $row)
        {
            $row['age_sec'] = $row['age_sec'] === NULL ? NULL : (float) $row['age_sec'];
            foreach (['runs', 'failures', 'consecutive_failures'] as $field) $row[$field] = (int) $row[$field];
            $out[(string) $row['key']] = $row;
        }

        return $out;
    }

    /**
     * Проблемы для dev:doctor: [[status, message], …]. Пусто — всё исполняется.
     */
    public function problems(): array
    {
        $state = $this->state();
        $factor = max(2, (int) self::config('stale_factor', 3));
        $out = [];

        foreach ($this->entries() as $key => $entry)
        {
            if ($entry['kind'] === 'invalid')
            {
                $out[] = ['FAIL', 'Расписание '.$key.' ('.$entry['module'].'): '.$entry['problem']];
                continue;
            }
            if ($entry['kind'] !== 'command') continue;

            $row = $state[$key] ?? NULL;
            if ($row === NULL OR $row['age_sec'] === NULL)
            {
                $out[] = ['WARN', 'Расписание '.$key.' ни разу не запускалось — нужен cron «* * * * * bin/console schedule:run» (или сервис scheduler)'];
            }
            elseif ($row['age_sec'] > $entry['every_sec'] * $factor + 60)
            {
                $out[] = ['WARN', 'Расписание '.$key.' не запускалось '.self::human((int) $row['age_sec']).' (норма — раз в '.$entry['every'].'): cron schedule:run остановлен?'];
            }
            elseif ($row['consecutive_failures'] >= 3)
            {
                $out[] = ['FAIL', 'Расписание '.$key.' падает '.$row['consecutive_failures'].' раз подряд (последний код '.$row['exit_code'].') — bin/console schedule:list'];
            }
        }

        return $out;
    }

    /** «10 minutes», «1 hour», «2 days», hourly, daily → секунды; NULL — не понято. */
    public static function parse_every(string $every): ?int
    {
        $every = strtolower(trim($every));
        $named = ['hourly' => 3600, 'daily' => 86400];
        if (isset($named[$every])) return $named[$every];
        if ( ! preg_match('/^(\d+)\s*(minutes?|mins?|hours?|days?)$/', $every, $m) OR (int) $m[1] < 1) return NULL;

        $unit = ['m' => 60, 'h' => 3600, 'd' => 86400][$m[2][0]];

        return (int) $m[1] * $unit;
    }

    /** `bin/console задача --опция=значение` → ['задача', '--опция=значение']; иначе исключение. */
    public static function parse_command(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command));
        if (count($parts) < 2 OR $parts[0] !== 'bin/console')
        {
            throw new InvalidArgumentException('command должен быть вида «bin/console <задача>»');
        }
        $task = $parts[1];
        if ( ! preg_match(self::COMMAND_TASK, $task))
        {
            throw new InvalidArgumentException('недопустимое имя задачи «'.$parts[1].'»');
        }
        if ( ! class_exists(Minion_Task::convert_task_to_class_name($task)))
        {
            throw new InvalidArgumentException('задачи '.$task.' нет (модуль не подключён или регистр имени не совпал с файлом класса)');
        }
        $options = array_slice($parts, 2);
        foreach ($options as $option)
        {
            if ( ! preg_match(self::COMMAND_OPTION, $option))
            {
                throw new InvalidArgumentException('недопустимая опция «'.$option.'»');
            }
        }

        return array_merge([$task], $options);
    }

    public static function human(int $seconds): string
    {
        if ($seconds < 120) return $seconds.' с';
        if ($seconds < 7200) return (int) round($seconds / 60).' мин';
        if ($seconds < 172800) return (int) round($seconds / 3600).' ч';

        return (int) round($seconds / 86400).' дн';
    }

    /** str_pad по символам, а не байтам (кириллица в таблицах CLI). */
    public static function pad(string $text, int $width): string
    {
        $len = mb_strlen($text);

        return $len >= $width ? $text.' ' : $text.str_repeat(' ', $width - $len);
    }

    public static function config(string $key, $default = NULL)
    {
        return Kohana::$config->load('prx103_schedule')->get($key, $default);
    }

    /** Команда процесса (переопределяется в тестах). */
    protected function _process(array $entry): array
    {
        return array_merge([PHP_BINARY, DOCROOT.'index.php'], $entry['argv']);
    }

    /** Запустить без shell, читать вывод, по таймауту — TERM, затем KILL. */
    protected function _spawn(array $cmd, int $timeout): array
    {
        $started = microtime(TRUE);
        $pipes = [];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(DOCROOT));
        if ( ! is_resource($proc))
        {
            return ['exit_code' => -1, 'timeout' => FALSE, 'output' => 'proc_open не запустил процесс', 'duration_ms' => 0];
        }

        foreach ([1, 2] as $i) stream_set_blocking($pipes[$i], FALSE);
        $output = '';
        $limit = (int) self::config('output_bytes', 4000) * 4;
        $timed_out = FALSE;
        $exit = NULL;

        while (TRUE)
        {
            $read = array_filter([$pipes[1], $pipes[2]], static fn ($p) => ! feof($p));
            if ($read)
            {
                $write = $except = NULL;
                if (@stream_select($read, $write, $except, 0, 200000) > 0)
                {
                    foreach ($read as $pipe)
                    {
                        $output .= (string) fread($pipe, 65536);
                        if (strlen($output) > $limit) $output = substr($output, -$limit);
                    }
                }
            }
            $status = proc_get_status($proc);
            if ( ! $status['running'])
            {
                $exit = (int) $status['exitcode'];
                break;
            }
            if ( ! $read) usleep(100000);
            if (microtime(TRUE) - $started > $timeout)
            {
                $timed_out = TRUE;
                proc_terminate($proc, 15);
                $grace = microtime(TRUE) + 5;
                while (proc_get_status($proc)['running'] AND microtime(TRUE) < $grace) usleep(100000);
                if (proc_get_status($proc)['running']) proc_terminate($proc, 9);
                break;
            }
        }

        foreach ([1, 2] as $i)
        {
            $output .= (string) stream_get_contents($pipes[$i]);
            fclose($pipes[$i]);
        }
        $closed = proc_close($proc);
        if ($exit === NULL OR $exit === -1) $exit = $timed_out ? NULL : $closed;

        return [
            'exit_code'   => $exit,
            'timeout'     => $timed_out,
            'output'      => self::_clean_output($output),
            'duration_ms' => (int) round((microtime(TRUE) - $started) * 1000),
        ];
    }

    protected static function _clean_output(string $output): string
    {
        // Без управляющих символов и битого UTF-8 — это TEXT в PostgreSQL.
        $output = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output);

        return mb_convert_encoding($output, 'UTF-8', 'UTF-8');
    }

    protected static function _tail(string $output, int $bytes): string
    {
        if (strlen($output) <= $bytes) return trim($output);

        return '…'.trim(mb_strcut($output, strlen($output) - $bytes, $bytes, 'UTF-8'));
    }

    protected static function _lock(string $key): bool
    {
        $row = Prx_Db::one('SELECT CASE WHEN pg_try_advisory_lock(:space, hashtext(:key)) THEN 1 ELSE 0 END AS ok',
            [':space' => self::LOCK_SPACE, ':key' => $key]);

        return (int) ($row['ok'] ?? 0) === 1;
    }

    protected static function _unlock(string $key): void
    {
        Prx_Db::one('SELECT pg_advisory_unlock(:space, hashtext(:key)) AS released',
            [':space' => self::LOCK_SPACE, ':key' => $key]);
    }
}
