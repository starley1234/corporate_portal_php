<?php
/* ---------------------------------------------------------------------------
   Стенд монитора воркеров (nj120): SQL, который строит JobQueue, целиком
   отдаётся на живой PostgreSQL отдельным стендом (.tools/nj120_monitor_pg_stand.py).

   Зачем: на проде /nj120/monitor упал с «столбец ev.created_at не существует» —
   PHP собрал запрос, а база его не поняла (в LATERAL-подзапросе выбирали
   только message, а снаружи читали ev.created_at). Линтеры такое не
   ловят: SQL синтаксически корректен, ошибочна семантика. Поэтому здесь SQL
   собирается настоящим Service_Nj120_JobQueue, а проверяет его PostgreSQL
   (pg_stand) — «монитор открывается» становится проверяемым фактом.

   Запуск (phpwasm):
     R=/path/to/n2j.ru; H=/tmp/phpwasm
     cd $H && node harness.mjs \
       "{\"/nj120_jobqueue.php\":\"$R/application/modules/nj120_jobs/classes/Service/Nj120/JobQueue.php\"}" \
       "$(cat $R/.tools/nj120_monitor_stand.php)" '{"/nj120_sql.json":"/tmp/nj120_sql.json"}'
   --------------------------------------------------------------------------- */
define('SYSPATH', '/app');

class Log {
    const ERROR = 'error'; const WARNING = 'warning'; const INFO = 'info';
    public static $messages = [];
    public static function instance(){ return new self; }
    public function add($l, $m, $p = []){ self::$messages[] = $m; }
}
class Kohana {
    public static $log; public static $config; public static $charset = 'utf-8';
    const DEVELOPMENT = 'development'; public static $environment = 'production';
}
Kohana::$log = new Log;
Kohana::$config = new class {
    public function load($key){
        $sets = [
            'nj120_jobs.db_group' => 'default',
            'nj120_jobs.channels_ui' => ['semantic.index' => ['label' => 'Семантика · индексация']],
            'nj120_jobs.requeue' => [],
        ];
        return isset($sets[$key]) ? $sets[$key] : [];
    }
};

class FakeResult implements IteratorAggregate, Countable {
    public $rows; public $single;
    public function __construct($rows = [], $single = NULL){ $this->rows = $rows; $this->single = $single; }
    public function current(){ return $this->single; }
    public function getIterator(): Traversable { return new ArrayIterator($this->rows); }
    public function as_array(){ return $this->rows; }
    public function count(): int { return count($this->rows); }
}

class Database {
    const SELECT = 1; const UPDATE = 2; const INSERT = 3; const DELETE = 4;

    public static $sql = [];
    public static function instance($g = NULL){ return new self; }
    public function quote($v){ return "'".str_replace("'", "''", (string) $v)."'"; }

    public function query($type, $sql, $as = TRUE)
    {
        self::$sql[] = ['type' => $type, 'sql' => $sql];
        $s = $sql;

        // Счётчики по статусам
        if (stripos($s, 'count(*) AS c FROM nj_120_jobs GROUP BY status') !== FALSE) {
            return new FakeResult([
                ['status' => 'processing', 'c' => 2],
                ['status' => 'done', 'c' => 5],
                ['status' => 'failed', 'c' => 1],
            ], NULL);
        }

        // Пульс и «застряли»
        if (stripos($s, 'max(') !== FALSE AND stripos($s, 'AS beat') !== FALSE) {
            return new FakeResult([], ['beat' => '2026-09-29 07:50:00', 'silent_now' => 1, 'stalled_now' => 1, 'stuck' => 1]);
        }

        // Воркеры за сутки
        if (stripos($s, 'SELECT DISTINCT claimed_by') !== FALSE) {
            return new FakeResult([['claimed_by' => 'worker-1']], NULL);
        }

        // «Чем занят воркер сейчас» — тот самый LATERAL с этапом
        if (stripos($s, 'ev.message AS stage') !== FALSE AND stripos($s, 'FROM nj_120_jobs j') !== FALSE
            AND stripos($s, "j.status = 'processing'") !== FALSE) {
            return new FakeResult([[
                'channel' => 'semantic.index', 'payload' => '{"collection":"nj560_knowledge"}',
                'heartbeat_at' => '2026-09-29 07:50:00', 'started_at' => '2026-09-29 07:40:00',
                'claimed_by' => 'worker-1', 'claimed_at' => '2026-09-29 07:40:05',
                'created_at' => '2026-09-29 07:39:00', 'stage' => 'Считаю векторы 3/48',
                'stage_at' => '2026-09-29 07:49:30',
            ]], NULL);
        }

        // Последние завершённые
        if (stripos($s, "status IN ('done', 'failed') AND finished_at IS NOT NULL") !== FALSE) {
            return new FakeResult([[
                'channel' => 'semantic.index', 'status' => 'done',
                'finished_at' => '2026-09-29 07:30:00', 'claimed_by' => 'worker-1',
            ]], NULL);
        }

        // Итоги за сутки: сколько задач закрыто успешно/с ошибкой
        if (stripos($s, 'count(*) AS c FROM nj_120_jobs WHERE status') !== FALSE) {
            return new FakeResult([], ['c' => stripos($s, "'done'") !== FALSE ? 5 : 1]);
        }

        // Среднее время по учёту расхода
        if (stripos($s, 'avg(elapsed_ms)') !== FALSE) {
            return new FakeResult([], ['a' => 1234]);
        }

        // Карточка одной задачи (diagnose → get)
        if (stripos($s, 'SELECT * FROM nj_120_jobs WHERE id =') !== FALSE) {
            if (preg_match('/WHERE id = (\d+)/', $s, $m) AND (int) $m[1] !== 969) {
                return new FakeResult([], NULL);   // задачи нет — как в жизни
            }

            return new FakeResult([], [
                'id' => 969, 'channel' => 'semantic.index', 'payload' => '{"collection":"nj560_knowledge"}',
                'status' => 'processing', 'attempts' => 1, 'max_attempts' => 3, 'timeout_sec' => 600,
                'visitor_id' => 'u:7', 'created_by' => 7, 'error' => NULL,
                'claimed_by' => 'worker-1', 'claimed_at' => date('Y-m-d H:i:s', time() - 600),
                'heartbeat_at' => date('Y-m-d H:i:s', time() - 30), 'started_at' => date('Y-m-d H:i:s', time() - 600),
                'created_at' => date('Y-m-d H:i:s', time() - 660), 'finished_at' => NULL,
                'available_at' => date('Y-m-d H:i:s', time() - 660),
            ]);
        }

        // Журнал задачи (events)
        if (stripos($s, 'FROM nj_120_job_events') !== FALSE AND stripos($s, 'ORDER BY id ASC') !== FALSE) {
            return new FakeResult([
                ['event' => 'claimed', 'message' => 'взял воркер worker-1', 'meta' => '{}', 'created_at' => date('Y-m-d H:i:s', time() - 600)],
                ['event' => 'progress', 'message' => 'Считаю векторы 3/48', 'meta' => '{}', 'created_at' => date('Y-m-d H:i:s', time() - 25)],
            ], NULL);
        }

        // Последние задачи для монитора (тот же LATERAL, что в now_doing)
        if (stripos($s, 'ev.message AS stage') !== FALSE) {
            return new FakeResult([[
                'id' => 969, 'channel' => 'semantic.index', 'status' => 'processing',
                'payload' => '{"items":[{"id":1}]}', 'error' => NULL, 'attempts' => 1, 'max_attempts' => 3,
                'visitor_id' => NULL, 'created_by' => 7, 'created_at' => '2026-09-29 07:39:00',
                'started_at' => '2026-09-29 07:40:00', 'finished_at' => NULL, 'heartbeat_at' => '2026-09-29 07:50:00',
                'owner_name' => 'admin', 'stage' => 'Считаю векторы 3/48', 'stage_at' => '2026-09-29 07:49:30',
            ]], NULL);
        }

        // Задачи одного канала (карточка в модульных админках)
        if (stripos($s, 'FROM nj_120_jobs j') !== FALSE AND stripos($s, 'WHERE j.channel =') !== FALSE) {
            return new FakeResult([[
                'id' => 969, 'channel' => 'semantic.index', 'status' => 'processing',
                'payload' => '{"items":[]}', 'error' => NULL, 'attempts' => 1, 'max_attempts' => 3,
                'created_at' => '2026-09-29 07:39:00', 'started_at' => '2026-09-29 07:40:00',
                'finished_at' => NULL, 'claimed_by' => 'worker-1',
            ]], NULL);
        }

        return new FakeResult([], NULL);
    }
}

require '/nj120_jobqueue.php';

$FAIL = 0; $CHECKS = 0;
function check($label, $cond, $extra = '')
{
    global $FAIL, $CHECKS;
    $CHECKS++;
    if ( ! $cond) $FAIL++;
    echo ($cond ? 'ОК   ' : 'ПЛОХО')." $label".($extra !== '' ? " :: $extra" : '')."\n";
}

$q = Service_Nj120_JobQueue::instance();

echo "== Монитор: здоровье очереди ==\n";
$health = $q->monitor_health();
check('счётчики по статусам собраны', ($health['counts']['processing'] ?? -1) === 2 AND ($health['counts']['done'] ?? -1) === 5, json_encode($health['counts'] ?? []));
check('«застряли» посчитаны', ($health['stuck'] ?? -1) === 1, (string) ($health['stuck'] ?? '?'));
check('воркеры за сутки перечислены', ($health['workers'][0] ?? '') === 'worker-1', json_encode($health['workers'] ?? []));
check('«чем занят сейчас» — с этапом и его возрастом',
    ($health['now_doing'][0]['stage'] ?? '') === 'Считаю векторы 3/48'
    AND is_int($health['now_doing'][0]['progress_ago'] ?? NULL), json_encode($health['now_doing'] ?? [], JSON_UNESCAPED_UNICODE));
check('последние завершённые видны', ($health['last_finished'][0]['status'] ?? '') === 'done');
check('итоги за сутки (успешно/ошибки)', ($health['done_24h'] ?? -1) === 5 AND ($health['failed_24h'] ?? -1) === 1,
    ($health['done_24h'] ?? '?').'/'.($health['failed_24h'] ?? '?'));
check('среднее время задачи по учёту расхода', ($health['avg_ms_24h'] ?? NULL) === 1234, (string) ($health['avg_ms_24h'] ?? '?'));
check('вердикт при живом пульсе и незакрытых «застрял»', ($health['verdict'] ?? '') === 'stuck', (string) ($health['verdict'] ?? '?'));

echo "\n== Монитор: список задач ==\n";
$jobs = $q->monitor_jobs('processing', 50);
check('задачи отдаются с этапом', ($jobs[0]['stage'] ?? '') === 'Считаю векторы 3/48', json_encode($jobs[0] ?? [], JSON_UNESCAPED_UNICODE));
check('этап содержит время (stage_at)', ($jobs[0]['stage_at'] ?? '') === '2026-09-29 07:49:30');

echo "\n== Монитор: карточка задачи ==\n";
$diag = $q->diagnose(969);
check('диагноз задачи посчитан', is_array($diag) AND ($diag['status'] ?? '') === 'processing', json_encode($diag ?? [], JSON_UNESCAPED_UNICODE));
check('диагноз видит живой пульс (30 с)', is_int($diag['pulse_ago'] ?? NULL) AND $diag['pulse_ago'] < 120, (string) ($diag['pulse_ago'] ?? '?'));
check('диагноз помнит про предельный возраст', ($diag['max_age_sec'] ?? 0) > 0 AND ($diag['silent_sec'] ?? 0) === 240,
    ($diag['max_age_sec'] ?? '?').'/'.($diag['silent_sec'] ?? '?'));
check('у задачи есть журнал событий', count($q->events(969)) === 2);
check('диагноз несуществующей задачи — NULL', $q->diagnose(0) === NULL);

echo "\n== Монитор: задачи канала ==\n";
$ch = $q->channel_jobs('semantic.index', 8);
check('задачи канала отдаются', ($ch[0]['id'] ?? 0) === 969 AND ($ch[0]['claimed_by'] ?? '') === 'worker-1');

echo "\n== Монитор: уборка очереди (вызывается при каждом заходе) ==\n";
$before = count(Database::$sql);
$closed = $q->requeue_stale();
$rules  = [];

foreach (array_slice(Database::$sql, $before) as $row) {
    if (stripos($row['sql'], 'UPDATE nj_120_jobs') === 0) {
        $rules[] = $row['sql'];
    }
}

check('уборка прошла без исключения и вернула число', is_int($closed), gettype($closed));
check('собраны все правила уборки (молчание / стоп / стойло / возраст / pending)',
    count($rules) >= 5, 'правил: '.count($rules));
check('правило предельного возраста на месте',
    (bool) array_filter($rules, function ($sql) { return stripos($sql, 'GREATEST(timeout_sec * 3') !== FALSE; }));
check('правило «пульс есть, работы нет» на месте',
    (bool) array_filter($rules, function ($sql) { return stripos($sql, 'error =') !== FALSE AND stripos($sql, 'cancel') !== FALSE; }));

/* --- SQL уходит на живой PostgreSQL: там он и проверяется по-настоящему --- */
$out = [];
foreach (Database::$sql as $row) {
    $out[] = ['type' => $row['type'], 'sql' => $row['sql']];
}

file_put_contents('/nj120_sql.json', json_encode([
    'queries' => $out,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\n".($FAIL === 0 ? "ВСЁ ОК" : "ПРОВАЛОВ: $FAIL")." (проверок: $CHECKS, SQL собрано: ".count($out).")\n";

exit(0);
