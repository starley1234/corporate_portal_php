<?php
/**
 * Стенд порогов авто-остановки «воркер молчит» (nj120, PHP-in-WASM).
 *
 * Живой случай 06.10.2026: общий порог 240 с снимал живую задачу nj661.airadar
 * (конвейер с локальной LLM — генерация занимает минуты), а прогон при этом
 * доходил до конца. Порог стал поканальным: config nj120_jobs →
 * silent_stop_channels; эффективный порог = max(общий env, канал).
 *
 * Проверяет настоящим Service_Nj120_JobQueue (SQL уходит в фейковую БД):
 *   1. резолюция порога: env → default 240; канал → max(env, config);
 *   2. разбор auto_stop_silent на проходы: общий (без особых каналов) +
 *      по одному на особый канал — каждый со своим порогом и минутами;
 *   3. одиночная задача (job_id > 0) — сначала узнаёт канал, затем её порог.
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs .tools/nj120_silent_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

$ok  = 0;
$bad = 0;

function check($label, $cond)
{
	global $ok, $bad;

	if ($cond)
	{
		$ok++;
		echo "ok   $label\n";
	}
	else
	{
		$bad++;
		echo "ФЕЙЛ $label\n";
	}
}

function contains($label, $haystack, $needle)
{
	check($label, is_string($haystack) AND strpos($haystack, $needle) !== FALSE);
}

// ---------------------------------------------------------- стабы окружения

class Kohana_Config_Group extends ArrayObject
{
	public function as_array() { return (array) $this; }
	public function get($key, $default = NULL) { $a = (array) $this; return array_key_exists($key, $a) ? $a[$key] : $default; }
}

class Config
{
	public function load($name)
	{
		// настоящий конфиг модуля — проверяется именно он
		$file = '/app/application/modules/nj120_jobs/config/nj120_jobs.php';

		if ( ! is_file($file))
		{
			return new Kohana_Config_Group([]);
		}

		$data = require $file;

		// первый сегмент — имя файла, остальное — путь внутри массива
		$keys = explode('.', (string) $name);
		array_shift($keys);

		foreach ($keys as $key)
		{
			$data = is_array($data) ? ($data[$key] ?? NULL) : NULL;
		}

		return $data;
	}
}

class Kohana
{
	public static $config;
	public static $log;
	public static $charset = 'UTF-8';
}

Kohana::$config = new Config;
Kohana::$log = new class { public function add($l, $m, $v = []) { } };

class Log
{
	const WARNING = 2;
}

class FakeResult implements IteratorAggregate
{
	public function __construct($rows) { $this->rows = (array) $rows; }
	public $rows;
	public function current() { return $this->rows ? $this->rows[0] : FALSE; }
	public function getIterator(): ArrayIterator { return new ArrayIterator($this->rows); }
}

class Database
{
	const SELECT = 1;
	const UPDATE = 2;
	const INSERT = 3;

	/** @var string[] все SQL-запросы */
	public static $sqls = [];
	/** @var array очередь результатов (массив строк на каждый запрос) */
	public static $next = [];

	public static function instance($group = NULL) { return new self; }
	public function quote($v) { return "'".str_replace("'", "''", (string) $v)."'"; }

	public function query($type, $sql, $object = NULL)
	{
		self::$sqls[] = (string) $sql;

		return new FakeResult(array_shift(self::$next) ?: []);
	}
}

// reconcile_outcomes() зовёт Manifest::reconcile_hooks() — модуля в стенде
// нет; пустой список хуков = «нечего сверять», как на сайте без хуков.
class Manifest
{
	public static function reconcile_hooks() { return []; }
}

require '/app/application/modules/nj120_jobs/classes/Service/Nj120/JobQueue.php';

$q = Service_Nj120_JobQueue::instance();

echo "1. Резолюция порога молчания\n";

check('общий порог по умолчанию 240 с', $q->silent_stop_sec() === 240);
check('канал nj661.airadar — 3000 с (config)', $q->silent_stop_sec('nj661.airadar') === 3000);
check('обычный канал — общий порог', $q->silent_stop_sec('nj605.radar') === 240);
check('карта каналов из настоящего конфига', $q->silent_stop_channels() === ['nj661.airadar' => 3000]);

putenv('N2J_SILENT_STOP_SEC=5000');
check('env поднимает общий порог', $q->silent_stop_sec() === 5000);
check('эффективный порог канала = max(env, config)', $q->silent_stop_sec('nj661.airadar') === 5000);
putenv('N2J_SILENT_STOP_SEC=');
check('env сброшен — снова 240', $q->silent_stop_sec() === 240);

echo "\n2. auto_stop_silent: общий проход + отдельный на особый канал\n";

Database::$sqls = [];
Database::$next = [];
$q->auto_stop_silent();

$updates = array_values(array_filter(Database::$sqls, function ($s) { return strpos($s, 'UPDATE nj_120_jobs') === 0; }));
check('два прохода: общий + особый канал', count($updates) === 2);

$general = $updates[0];
contains('общий: порог 240 с', $general, '240 * interval');
contains('общий: особый канал исключён', $general, "channel NOT IN ('nj661.airadar')");
contains('общий: минуты в причине', $general, 'больше 4 мин');

$special = $updates[1];
contains('особый: только nj661.airadar', $special, "channel = 'nj661.airadar'");
contains('особый: порог 3000 с', $special, '3000 * interval');
contains('особый: свои минуты в причине', $special, 'больше 50 мин');
check('особый: без NOT IN', strpos($special, 'NOT IN') === FALSE);

echo "\n3. Одна задача — её каналом и её порогом\n";

Database::$sqls = [];
Database::$next = [[['channel' => 'nj661.airadar']]];   // SELECT channel → nj661
$n = $q->auto_stop_silent(77);

$job_sql = implode('', Database::$sqls);
contains('сначала узнаёт канал задачи', $job_sql, 'SELECT channel FROM nj_120_jobs WHERE id = 77');
$upd = '';
foreach (Database::$sqls as $s)
{
	if (strpos($s, 'UPDATE nj_120_jobs') === 0) { $upd = $s; break; }
}
contains('одиночная: порог канала 3000 с', $upd, '3000 * interval');
contains('одиночная: фильтр по id', $upd, 'AND id = 77');
check('одиночная: один UPDATE', count(array_filter(Database::$sqls, function ($s) { return strpos($s, 'UPDATE nj_120_jobs') === 0; })) === 1);

// задача обычного канала — общий порог
Database::$sqls = [];
Database::$next = [[['channel' => 'nj605.radar']]];
$q->auto_stop_silent(88);
$upd = '';
foreach (Database::$sqls as $s)
{
	if (strpos($s, 'UPDATE nj_120_jobs') === 0) { $upd = $s; break; }
}
contains('одиночная обычного канала: порог 240', $upd, '240 * interval');

// --- release_mine: SQL без PHP-склеек ----------------------------------------
// 06.10.2026: сообщение об исчерпанных попытках склеивалось точками ВНУТРИ
// SQL-строки — PostgreSQL падал «ошибка синтаксиса (".")», воркер не мог
// закрыть задачи прошлого запуска (HTTP 500 от /release_mine).
echo "\n6. release_mine: SQL без PHP-склеек\n";

Database::$sqls = [];
Database::$next = [];   // все выборки пусты: ничего не найдено — и не надо
$q->release_mine('w1');

$release_sqls = array_values(array_filter(Database::$sqls, function ($s) {
    return strpos($s, 'UPDATE nj_120_jobs') === 0;
}));
check('release_mine: два UPDATE (requeue + failed)', count($release_sqls), 2);
$failed_sql = '';
foreach ($release_sqls as $s)
{
    if (strpos($s, "status = 'failed'") !== FALSE) $failed_sql = $s;
}
contains('release_mine: провал с честным текстом', $failed_sql, 'попытки исчерпаны');
check('release_mine: SQL без PHP-склейки', preg_match('/^\s*\. \'/m', $failed_sql) === 0 ? 'true' : 'false', 'true');
check('release_mine: сообщение целиком', strpos($failed_sql, 'Смотрите журнал задачи и запустите заново.') !== FALSE ? 'true' : 'false', 'true');

echo "\n".($bad === 0 ? "ПОРОГИ NJ-120: OK ($ok)\n" : "ПОРОГИ NJ-120: ПРОВАЛ ($bad из ".($ok + $bad).")\n");
exit($bad === 0 ? 0 : 1);
