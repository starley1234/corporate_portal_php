<?php
use PHPUnit\Framework\TestCase;

/** Исполнитель задаётся тестом: вместо bin/console — короткий php -r. */
class ScheduleRunnerProbe extends Service_Prx103_Schedule
{
    public array $scripts = [];

    protected function _process(array $entry): array
    {
        return [PHP_BINARY, '-r', $this->scripts[$entry['key']] ?? 'exit(0);'];
    }
}

/** bin/console schedule:run на временной PostgreSQL: срок, сбои, таймаут, блокировка. */
class ScheduleRunnerIntegrationTest extends TestCase
{
    private $db;
    private string $prefix;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->db->begin();
        $this->prefix = 'test_'.bin2hex(random_bytes(4)).'_';
    }

    protected function tearDown(): void
    {
        $this->db->rollback();
    }

    private function probe(array $entries, array $scripts = []): ScheduleRunnerProbe
    {
        $named = [];
        foreach ($entries as $key => $spec) $named[$this->prefix.$key] = $spec + ['module' => 'prx103_platform'];
        $probe = new ScheduleRunnerProbe($named);
        foreach ($scripts as $key => $code) $probe->scripts[$this->prefix.$key] = $code;

        return $probe;
    }

    public function test_every_and_command_are_parsed_strictly(): void
    {
        $this->assertSame(600, Service_Prx103_Schedule::parse_every('10 minutes'));
        $this->assertSame(60, Service_Prx103_Schedule::parse_every('1 minute'));
        $this->assertSame(7200, Service_Prx103_Schedule::parse_every('2 hours'));
        $this->assertSame(86400, Service_Prx103_Schedule::parse_every('daily'));
        foreach (['', 'often', '0 minutes', '-5 minutes', '10'] as $bad) {
            $this->assertNull(Service_Prx103_Schedule::parse_every($bad), $bad);
        }

        $this->assertSame(['prx400:purge'], Service_Prx103_Schedule::parse_command('bin/console prx400:purge'));
        $this->assertSame(['Prx120:RequeueStale'], Service_Prx103_Schedule::parse_command('bin/console Prx120:RequeueStale'));
        $this->assertSame(['schedule:run', '--only=specflow_purge'], Service_Prx103_Schedule::parse_command('bin/console schedule:run --only=specflow_purge'));
        foreach ([
            'php index.php prx400:purge',          // не bin/console
            'bin/console prx400:purge; rm -rf /',  // shell-склейка
            'bin/console prx400:purge --x=$(id)',  // подстановка
            'bin/console prx400:purge `id`',
            'bin/console nosuch:task',             // задачи нет
            'bin/console',
        ] as $bad) {
            try {
                Service_Prx103_Schedule::parse_command($bad);
                $this->fail('принята команда: '.$bad);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_entries_classify_disabled_external_and_invalid(): void
    {
        $entries = $this->probe([
            'cmd'      => ['every' => '5 minutes', 'command' => 'bin/console prx400:purge'],
            'off'      => ['every' => '5 minutes', 'command' => 'bin/console prx400:purge', 'enabled' => FALSE],
            'url'      => ['every' => '5 minutes', 'url' => '/prx120/api/maintain'],
            'bad_cmd'  => ['every' => '5 minutes', 'command' => 'rm -rf /'],
            'bad_every' => ['every' => 'sometimes', 'command' => 'bin/console prx400:purge'],
            'empty'    => ['every' => '5 minutes'],
        ])->entries();
        $kind = fn (string $key) => $entries[$this->prefix.$key]['kind'];
        $this->assertSame('command', $kind('cmd'));
        $this->assertSame(300, $entries[$this->prefix.'cmd']['every_sec']);
        $this->assertSame('disabled', $kind('off'));
        $this->assertSame('external', $kind('url'));
        $this->assertSame('invalid', $kind('bad_cmd'));
        $this->assertSame('invalid', $kind('bad_every'));
        $this->assertSame('invalid', $kind('empty'));
    }

    public function test_every_manifest_schedule_is_executable(): void
    {
        // Каждый ключ schedule во всех манифестах исполняется планировщиком:
        // запись без command (или с несуществующей задачей) снова оставила бы
        // обслуживание модуля на ручной cron.
        $entries = (new Service_Prx103_Schedule)->entries();
        $this->assertNotEmpty($entries);
        foreach ($entries as $key => $entry) {
            $this->assertSame('command', $entry['kind'], $key.': '.$entry['problem']);
        }
        foreach (['maintenance', 'notifications', 'specflow_purge'] as $key) {
            $this->assertArrayHasKey($key, $entries);
        }
    }

    public function test_due_force_failures_and_recovery_are_recorded(): void
    {
        $probe = $this->probe(
            ['ok' => ['every' => '10 minutes', 'command' => 'bin/console prx400:purge'],
             'bad' => ['every' => '1 minute', 'command' => 'bin/console prx400:purge']],
            ['ok' => 'echo "готово"; exit(0);', 'bad' => 'fwrite(STDERR, "сломалось"); exit(3);']);

        $first = $probe->run_due();
        $this->assertSame('ran', $first[$this->prefix.'ok']['result']);
        $this->assertSame('ok', $first[$this->prefix.'ok']['status']);
        $this->assertSame(0, $first[$this->prefix.'ok']['exit_code']);
        $this->assertSame('failed', $first[$this->prefix.'bad']['status']);
        $this->assertSame(3, $first[$this->prefix.'bad']['exit_code']);

        $state = $probe->state();
        $this->assertSame('готово', $state[$this->prefix.'ok']['output']);
        $this->assertSame('сломалось', $state[$this->prefix.'bad']['output']);
        $this->assertSame(1, $state[$this->prefix.'bad']['consecutive_failures']);

        // Срок не наступил — не запускаем; --force — запускаем.
        $second = $probe->run_due($this->prefix.'ok');
        $this->assertSame('not_due', $second[$this->prefix.'ok']['result']);
        $this->assertGreaterThan(500, $second[$this->prefix.'ok']['next_in_sec']);
        $this->assertSame('ran', $probe->run_due($this->prefix.'ok', TRUE)[$this->prefix.'ok']['result']);
        $this->assertSame(2, $probe->state()[$this->prefix.'ok']['runs']);

        // Серия неудач растёт, успех её обнуляет; общий счётчик сбоев остаётся.
        $probe->run_due($this->prefix.'bad', TRUE);
        $this->assertSame(2, $probe->state()[$this->prefix.'bad']['consecutive_failures']);
        $probe->scripts[$this->prefix.'bad'] = 'exit(0);';
        $probe->run_due($this->prefix.'bad', TRUE);
        $row = $probe->state()[$this->prefix.'bad'];
        $this->assertSame('ok', $row['status']);
        $this->assertSame(0, $row['consecutive_failures']);
        $this->assertSame(2, $row['failures']);
        $this->assertSame(3, $row['runs']);

        $this->assertSame('unknown', $probe->run_due($this->prefix.'absent')[$this->prefix.'absent']['result']);
    }

    public function test_timeout_kills_the_process(): void
    {
        $probe = $this->probe(
            ['slow' => ['every' => '1 minute', 'command' => 'bin/console prx400:purge', 'timeout' => 1]],
            ['slow' => 'echo "начал"; sleep(30);']);
        $started = microtime(TRUE);
        $result = $probe->run_due()[$this->prefix.'slow'];
        $this->assertLessThan(10, microtime(TRUE) - $started);
        $this->assertSame('timeout', $result['status']);
        $row = $probe->state()[$this->prefix.'slow'];
        $this->assertSame('timeout', $row['status']);
        $this->assertSame('начал', $row['output']);
        $this->assertSame(1, $row['consecutive_failures']);
    }

    public function test_running_entry_is_not_started_twice(): void
    {
        $key = $this->prefix.'locked';
        $probe = $this->probe([substr($key, strlen($this->prefix)) => ['every' => '1 minute', 'command' => 'bin/console prx400:purge']]);
        // Отдельная сессия PG: pg_pconnect вернул бы тот же линк, а advisory
        // lock в своей сессии реентерабелен — проверка была бы пустой.
        $config = Kohana::$config->load('database')->get('default');
        $config['connection']['persistent'] = FALSE;
        $other = Database::instance('schedule_lock_'.bin2hex(random_bytes(3)), $config);
        $other->query(Database::SELECT, 'SELECT pg_advisory_lock(103, hashtext('.$other->quote($key).'))', FALSE);
        try {
            $this->assertSame('locked', $probe->run_due()[$key]['result']);
            $this->assertArrayNotHasKey($key, $probe->state());
        } finally {
            $other->query(Database::SELECT, 'SELECT pg_advisory_unlock(103, hashtext('.$other->quote($key).'))', FALSE);
            $other->disconnect();
        }
        $this->assertSame('ran', $probe->run_due()[$key]['result']);
    }

    public function test_doctor_problems_never_stale_and_failing(): void
    {
        $probe = $this->probe(
            ['never' => ['every' => '10 minutes', 'command' => 'bin/console prx400:purge'],
             'stale' => ['every' => '10 minutes', 'command' => 'bin/console prx400:purge'],
             'flaky' => ['every' => '10 minutes', 'command' => 'bin/console prx400:purge'],
             'fine'  => ['every' => '10 minutes', 'command' => 'bin/console prx400:purge'],
             'broken' => ['every' => '10 minutes', 'command' => 'bin/console nosuch:task']],
            ['flaky' => 'exit(1);']);
        foreach (['stale', 'flaky', 'fine'] as $key) $probe->run_due($this->prefix.$key);
        foreach ([1, 2] as $_) $probe->run_due($this->prefix.'flaky', TRUE);
        $this->db->query(Database::UPDATE, "UPDATE prx_103_schedule_runs SET started_at = now() - interval '2 hours' WHERE key = "
            .$this->db->quote($this->prefix.'stale'), FALSE);

        $problems = [];
        foreach ($probe->problems() as [$status, $message]) {
            if (preg_match('/'.preg_quote($this->prefix, '/').'(\w+)/', $message, $m)) $problems[$m[1]] = $status;
        }
        ksort($problems);
        $this->assertSame(['broken' => 'FAIL', 'flaky' => 'FAIL', 'never' => 'WARN', 'stale' => 'WARN'], $problems);
        $this->assertArrayNotHasKey('fine', $problems);
    }
}
