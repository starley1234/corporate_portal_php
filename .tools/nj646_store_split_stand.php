<?php
/**
 * nj646_label: распил Store.php (долг P2-9) — класс собирается из трейтов, и все
 * Все методы распила на месте. После чистки мёртвых обёрток (8 методов, которые
 * никто не вызывал) публичных стало 109; P2-10 добавил два ЗАЩИЩЁННЫХ помощника
 * записи — контракт от этого не расширялся.
 *
 * Зачем стенд. Распил — механическая работа, где ошибка не видна глазом: потерялся
 * метод, трейт не подключился, имя разъехалось. Проверять это на живом сайте
 * поздно, а в песочнице PHP нет. Здесь мы повторяем ровно то, что делает
 * Kohana: автозагрузчик по имени (`Service_Nj646_Store_Goods` →
 * `classes/Service/Nj646/Store/Goods.php`) и `use` внутри класса, — а потом
 * сверяем состав методов класса с тем, что объявлено в трейтах.
 *
 * Что проверяем:
 *   1. Store.php подключается, трейты резолвятся (без них PHP упал бы фатально);
 *   2. класс объявляет ВСЕ методы трейтов и помощники ядра, и ничего не потерял;
 *   3. каждый трейт объявлен ровно в своём файле и содержит свои методы;
 *   4. публичный контракт прежний: список публичных методов не изменился;
 *   5. живые помощники работают: `cfg()`, `flag()` и `_json()` (через отражение).
 *
 * Запуск (в песочнице PHP нет) — через PHP-in-WASM:
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj646_store_split_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
$fail = 0;

set_error_handler(function ($no, $str, $file, $line) use (&$fail) {
    echo '  ПРОВАЛ  PHP: '.$str.' ('.$file.':'.$line.")\n";
    $fail++;

    return TRUE;
});

/** Класс, который нужен трейтам и ядру только по имени. */
class Log {
    const INFO = 6;
    const WARNING = 4;
    const ERROR = 3;
}

class Database {
    const SELECT = 1;
    const INSERT = 2;
    const UPDATE = 3;

    public static function instance() { return new self(); }

    public function quote($value) { return "'".str_replace("'", "''", (string) $value)."'"; }

    public function query($type, $sql, $as_object = NULL) { return new DB_Result(); }
}

class DB_Result {
    public function current() { return NULL; }
    public function as_array() { return []; }
}

class DB {
    public static $log = [];
    public static function expr($value) { return new DB_Expr($value); }
    public static function query($type, $sql) { DB::$log[] = $sql; return new DB_Query(); }
    public static function update($table) { DB::$log[] = 'UPDATE '.$table; return new DB_Query(); }
    public static function delete($table) { DB::$log[] = 'DELETE FROM '.$table; return new DB_Query(); }
    public static function insert($table) { DB::$log[] = 'INSERT INTO '.$table; return new DB_Query(); }
}

class DB_Expr { public $value; public function __construct($v) { $this->value = $v; } }
class DB_Query {
    public function set($fields) { return $this; }
    public function where() { return $this; }
    public function execute() { return 0; }
}

class Kohana {
    public static $config;
    public static $log;
}

class Kohana_Config {
    protected $data = [
        'nj646_label' => [
            'limits' => ['photo_mb' => 8, 'photo_per_hour' => 10, 'text_max' => 8000],
            'flags'  => ['study' => TRUE],
        ],
    ];

    public function load($group) { return $this->data[$group] ?? []; }
}

class Kohana_Log {
    public function add($level, $message, $values = []) {}
}

Kohana::$config = new Kohana_Config();
Kohana::$log    = new Kohana_Log();

/**
 * Автозагрузчик как у Kohana: имя класса → файл (нижний регистр имён папок).
 * Здесь — только карта `Service_Nj646_*`, чтобы стенд не тянул весь проект.
 */
spl_autoload_register(function ($class) use ($ROOT) {
    if (strpos($class, 'Service_Nj646_Store_') === 0)
    {
        $file = $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Store/'
            .substr($class, strlen('Service_Nj646_Store_')).'.php';

        if (is_file($file))
        {
            require $file;
        }
    }
});

$check = function ($name, $ok) use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name."\n";

    if ( ! $ok) $fail++;
};

echo "== класс собирается из трейтов ==\n\n";

require $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Store.php';

$check('Store.php подключился, трейты резолвились (иначе был бы фатал)', class_exists('Service_Nj646_Store'));

$dir    = $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Store';
$traits = [];
$trait_methods = [];

foreach (glob($dir.'/*.php') as $file)
{
    $src  = file_get_contents($file);
    $name = basename($file, '.php');

    preg_match('/^trait (\w+)/m', $src, $m);
    $traits[$name] = $m[1] ?? '';

    preg_match_all('/^    (?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)/m', $src, $mm);
    $trait_methods[$name] = $mm[1];
}

$check('трейтов десять: '.implode(', ', array_keys($traits)), count($traits) === 10);

foreach ($traits as $file => $trait)
{
    $check('трейт '.$trait.' объявлен в '.$file.'.php и существует', $trait === 'Service_Nj646_Store_'.$file AND trait_exists($trait));
}

$expected = [];

foreach ($trait_methods as $name => $methods)
{
    $expected = array_merge($expected, $methods);
    $check('трейт '.$name.': методы объявлены ('.count($methods).')', count($methods) > 0);
}

$core_methods = ['db', 'cfg', 'limit', 'flag', '_json', '_text_cut', '_json_cut',
    '_one', '_all', 'ensure_tables', 'ensure_goods', '_goods_ddl'];

// `get_class_methods()` снаружи отдаёт только публичные — для полной сверки
// берём отражение: оно видит и защищённые помощники.
$reflection = new ReflectionClass('Service_Nj646_Store');
$declared   = array_map(function ($m) { return $m->getName(); }, $reflection->getMethods());
$public     = array_map(function ($m) { return $m->getName(); },
    array_filter($reflection->getMethods(), function ($m) { return $m->isPublic(); }));

$missing = array_values(array_diff(array_merge($expected, $core_methods), $declared));
$extra   = array_values(array_diff($declared, array_merge($expected, $core_methods)));

echo 'методов в трейтах: '.count($expected).', в ядре: '.count($core_methods)
    .', у класса: '.count($declared).' (публичных '.count($public).") \n\n";

$check('методы трейтов видны у класса: нет потерянных ('.count($missing).')', $missing === []);
$check('лишних (не наших) методов нет', $extra === []);
$check('итого методов — 126: распил (131) плюс два помощника записи (P2-10) минус мёртвые обёртки (7)',
    count($declared) === 126);
$check('публичных 109 (116 минус 7 мёртвых), защищённых 17',
    count($public) === 109 AND count($declared) - count($public) === 17);

echo "\n== публичный контракт не изменился ==\n\n";

$helpers = ['_one', '_all', '_json', '_json_decode', '_text_cut', '_json_cut'];
$leaked  = array_values(array_intersect($helpers, $public));

$check('помощники остались защищёнными (наружу не торчат): '.($leaked ? implode(', ', $leaked) : 'никто не торчит'),
    $leaked === [] AND array_diff($helpers, $declared) === []);

$check('ядро осталось в Store.php: свойства + помощники, а не разделы',
    strpos(file_get_contents($ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Store.php'), 'trait ') === FALSE
    AND count($core_methods) === 12);   // 10 после распила + пределы записи (P2-10)

foreach (['ingredient_by_id', 'goods_duplicates', 'producer_merge', 'product_merge', 'run_create', 'unknown_queue', 'photo_save', 'profile_save', 'stats'] as $sample)
{
    $check('метод '.$sample.' доступен как Service_Nj646_Store::'.$sample.'()', in_array($sample, $public, TRUE));
}

echo "\n== живые помощники ядра ==\n\n";

$check('cfg(): лимит фото 8 МБ из конфига', (int) Service_Nj646_Store::cfg('limits.photo_mb') === 8);
$check('flag(): «on» и настоящий TRUE — включено, «нет» — нет',
    Service_Nj646_Store::flag('on') === TRUE AND Service_Nj646_Store::flag(TRUE) === TRUE
    AND Service_Nj646_Store::flag('нет') === FALSE);

$json   = new ReflectionMethod('Service_Nj646_Store', '_json');
$decode = new ReflectionMethod('Service_Nj646_Store', '_json_decode');
$json->setAccessible(TRUE);
$decode->setAccessible(TRUE);

$jsonb = (string) $json->invoke(NULL, ['детям', 'диабет']);

$check('_json() собирает jsonb-строку и экранирует её для SQL ('.$jsonb.')',
    strpos($jsonb, '["детям","диабет"]') !== FALSE
    AND substr($jsonb, 0, 1) === "'" AND substr($jsonb, -1) === "'"
    AND $json->invoke(NULL, []) === "'[]'");
$check('_json_decode() читает jsonb из базы', $decode->invoke(NULL, '{"a":1}') === ['a' => 1]);
$check('_json_decode() переживает мусор (в базе всякое бывает)', $decode->invoke(NULL, 'не json') === []);

echo "\n".($fail ? "ПРОВАЛОВ: $fail" : 'провалов: 0')."\n";
