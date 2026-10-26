<?php
/**
 * Стенд механизма миграций nj111 (PHP-in-WASM): run_script/split_script
 * и реальные up()/down() миграций nj658/nj660/nj661.
 *
 * Почему: 06.10.2026 накат pgMigration_Nj_661_001 на проде упал с
 * «незавершённая строка в кавычках» — миграция резала SQL через
 * explode(';'), а «;» стояла внутри литерала COMMENT ON … IS '…; …'.
 * Здесь проверяется, что такого класса ошибок больше нет:
 *   1. split_script: «;» внутри '…', экранированные '', строчные «-- …»,
 *      хвост без «;», пустые куски;
 *   2. настоящие миграции nj661/nj660/nj658 (up+down): каждый оператор
 *      цел (сбалансированные кавычки, завершён «;»), COMMENT-тексты с
 *      «;» внутри доходят до run_query не разрезанными;
 *   3. nj661: ровно 16 операторов (5 таблиц + 6 индексов + 5 комментариев).
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs .tools/nj111_migrations_stand.php
 * (сид nj658 подтянется, если задать N2J_STAND_FILES с путём к его .sql)
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
		foreach (['/app/application/modules/nj111_migrations/config/'.$name.'.php', '/app/application/config/'.$name.'.php'] as $file)
		{
			if (is_file($file))
			{
				$data = require $file;

				return new Kohana_Config_Group(is_array($data) ? $data : []);
			}
		}

		return new Kohana_Config_Group([]);
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

class Database
{
	const SELECT = 1;
	const UPDATE = 0;

	public static $default = 'default';
}

/** Все SQL, ушедшие в run_query (массив строк). */
class Db_Statement
{
	public function execute($group = NULL) { return TRUE; }
}

class DB
{
	/** @var string[] */
	public static $sqls = [];

	public static function query($type, $sql)
	{
		self::$sqls[] = (string) $sql;

		return new Db_Statement;
	}
}

// ----------------------------------------------- настоящие классы миграций

require '/app/application/modules/nj111_migrations/classes/Model/Nj111/Migration.php';
require '/app/application/modules/nj111_migrations/classes/pgMigration.php';

/** Окошко к protected split_script для граничных тестов. */
class Test_Migration extends pgMigration
{
	public function up() {}
	public function down() {}
	public function split($sql) { return $this->split_script($sql); }
	public function script($sql) { return $this->run_script($sql); }
}

echo "1. split_script: граничные случаи\n";

$mig = new Test_Migration;

$parts = $mig->split("COMMENT ON TABLE t IS 'текст; с точками; внутри'; SELECT 1;");
check('«;» внутри литерала не режет оператор', count($parts) === 2);
check('литерал дошёл целым', strpos($parts[0], "'текст; с точками; внутри'") !== FALSE);
check('хвостовый оператор выделен', trim($parts[1]) === 'SELECT 1;');

$parts = $mig->split("INSERT INTO t VALUES ('ОВ''НИ'); SELECT 2;");
check('экранированные кавычки не закрывают строку', count($parts) === 2 AND strpos($parts[0], "'ОВ''НИ'") !== FALSE);

$parts = $mig->split("-- комментарий; с точкой\nSELECT 3;");
check('строчный «-- …» пропускается', count($parts) === 1 AND trim($parts[0]) === 'SELECT 3;');

$parts = $mig->split("SELECT 4;\n;\n  \nSELECT 5");
check('пустые куски отбрасываются', count($parts) === 2);
check('хвост без «;» завершается «;»', trim($parts[1]) === 'SELECT 5;');

DB::$sqls = [];
$n = $mig->script("SELECT 6; SELECT 7; SELECT 8;");
check('run_script возвращает число операторов', $n === 3 AND count(DB::$sqls) === 3);

echo "\n2. Настоящие миграции: операторы целые, комментарии не разрезаны\n";

/** Каждый оператор: чётное число кавычек (пары '' чётны и так) и «;» в конце. */
function statements_sane(array $sqls)
{
	foreach ($sqls as $sql)
	{
		if (substr_count($sql, "'") % 2 !== 0 OR substr(rtrim($sql), -1) !== ';')
		{
			return FALSE;
		}
	}

	return TRUE;
}

$migrations = [
	'nj661' => '/app/application/modules/nj661_airadar/migrations_pg/pgMigration_Nj_661_001_Airadar_CreateTables.php',
	'nj660' => '/app/application/modules/nj660_arch/migrations_pg/pgMigration_Nj_660_001_Arch_CreateTables.php',
	'nj658' => '/app/application/modules/nj658_geo/migrations_pg/pgMigration_Nj_658_001_Geo_CreateTables.php',
];

foreach ($migrations as $label => $file)
{
	require $file;

	$class = basename($file, '.php');

	DB::$sqls = [];
	$up = new $class;
	$up->up();
	$GLOBALS['up_'.$label] = DB::$sqls;

	check($label.' up: все операторы целые (кавычки/«;»)', statements_sane(DB::$sqls));

	$has_comment = (bool) preg_grep('/COMMENT ON/', DB::$sqls);
	$comments    = implode("\n", preg_grep('/COMMENT ON/', DB::$sqls) ?: []);

	if ($has_comment)
	{
		check($label.' up: COMMENT-тексты дошли целиком', strpos($comments, "IS '") !== FALSE);
	}

	DB::$sqls = [];
	$down = new $class;
	$down->down();

	check($label.' down: операторы целые', statements_sane(DB::$sqls));
	check($label.' down: что-то удаляет', count(DB::$sqls) > 0);
}

// nj661 — упавшая миграция: точный состав и целые тексты с «;» внутри.
$up661 = $GLOBALS['up_nj661'];

check('nj661 up: 16 операторов (5 таблиц + 6 индексов + 5 комментариев)', count($up661) === 16);
check('nj661 up: 5 CREATE TABLE', count(preg_grep('/CREATE TABLE IF NOT EXISTS/', $up661)) === 5);
check('nj661 up: 6 индексов', count(preg_grep('/CREATE (UNIQUE )?INDEX IF NOT EXISTS/', $up661)) === 6);
check('nj661 up: 5 комментариев', count(preg_grep('/^COMMENT ON TABLE/', $up661)) === 5);

$runs_comment = implode('', preg_grep('/nj_661_runs/', $up661) ?: []);
check(
	'nj661 up: COMMENT про runs целиком (тут были «;» внутри)',
	strpos($runs_comment, 'прогоны конвейера; trigger schedule|manual; status new|running|done|fail') !== FALSE
);

$items_comment = implode('', preg_grep('/COMMENT ON TABLE "nj_661_items"/', $up661) ?: []);
check('nj661 up: COMMENT про items целиком', strpos($items_comment, 'найденные материалы; url_hash') !== FALSE);

// nj658: если сид-файл доехал в /app — INSERT-порции тоже целые.
// Дамп начинается с «--»-комментариев — заодно проверяем, что они не
// превратились в операторы.
$up658   = $GLOBALS['up_nj658'];
$inserts = preg_grep('/^INSERT INTO/', $up658) ?: [];

if ($inserts)
{
	check('nj658 up: сид INSERT-порции целые', statements_sane($inserts));
	check('nj658 up: 9 батч-INSERT (2020 городов)', count($inserts) === 9);
	check('nj658 up: «--»-заголовок сида не попал в операторы',
		count(preg_grep('/^\s*--/', $up658)) === 0 AND count(preg_grep('/Источник: source_data/', $up658)) === 0);
}
else
{
	echo "     (сид nj658 в стенде отсутствует — пропущен)\n";
}

echo "\n".($bad === 0 ? "МИГРАЦИИ NJ-111: OK ($ok)\n" : "МИГРАЦИИ NJ-111: ПРОВАЛ ($bad из ".($ok + $bad).")\n");
exit($bad === 0 ? 0 : 1);
