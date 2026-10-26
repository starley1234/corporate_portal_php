<?php
/**
 * nj646_label: три находки `.tools/php_ternary_lint.py` — проверка «свой прогон»,
 * предложение про справочник организаций и ключ товара с производителем.
 *
 * Зачем стенд. Все три — один и тот же баг приоритетов (`AND` связывает слабее
 * `?:` и `=`), причём две находки были неочевидны до прогона:
 *   1. `Api::action_analyze()` — `$own = $run AND (…)` присваивал сам прогон,
 *      а проверка «свой ли он» отбрасывалась: в текст разбора попадал
 *      транскрипт ЧУЖОЙ этикетки. Здесь проверяем правило владения;
 *   2. `views/646/producer.php` — вместо фразы печаталось `1`;
 *   3. `Store_Goods::product_upsert()` — в ключ товара уходило `TRUE` («|1») у
 *      всех производителей, и «Мыло без названия» двух заводов слипалось.
 *      Плюс переклейка старых ключей: карточки, заведённые до исправления, не
 *      должны превратиться в дубли.
 *
 * Для ключа товара нужна БД, поэтому здесь стоит маленькая поддельная база: она
 * отвечает на те же SELECT-ы, что задаёт `product_upsert()`, и записывает
 * UPDATE/INSERT — по этому и видно, переклеился ключ или завелась новая строка.
 *
 * Запуск (в песочнице PHP нет) — через PHP-in-WASM:
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj646_fixes_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
$fail = 0;

$php_errors = [];

set_error_handler(function ($no, $str, $file, $line) use (&$fail, &$php_errors) {
    // Внутри View стоит свой ob_start(), поэтому печатать сразу нельзя —
    // замечание уехало бы в HTML страницы. Копим и показываем в конце.
    $php_errors[] = $str.' ('.basename($file).':'.$line.')';
    $fail++;

    return TRUE;
});

class Log { const INFO = 6; const WARNING = 4; const ERROR = 3; }

class Kohana {
    public static $config;
    public static $log;
}

class Kohana_Config {
    protected $data = ['nj646_label' => ['limits' => ['photo_mb' => 8]]];
    public function load($group) { return $this->data[$group] ?? []; }
}

class Kohana_Log {
    public static $events = [];
    public function add($level, $message, $values = []) { Kohana_Log::$events[] = $message; }
}

class HTML {
    public static function chars($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

class View {
    private $name;
    private $data;

    public function __construct($name, array $data) { $this->name = $name; $this->data = $data; }

    public static function factory($name, array $data = []) { return new self($name, $data); }

    public function __toString()
    {
        extract($this->data);
        ob_start();

        require dirname(__DIR__).'/application/modules/nj646_label/views/'.$this->name.'.php';

        return ob_get_clean();
    }
}

/** Поддельная база: одна таблица — товары (`nj_646_products`). */
class Fake_DB
{
    public static $products = [];
    public static $queries  = [];
    public static $writes   = [];
    public static $next_id  = 1;

    public static function reset()
    {
        self::$products = [];
        self::$queries  = [];
        self::$writes   = [];
        self::$next_id  = 1;
    }

    public static function add(array $row)
    {
        $row['id'] = self::$next_id++;
        self::$products[] = $row;

        return $row['id'];
    }

    public static function find($sql)
    {
        if (strpos($sql, 'nj_646_analyses') !== FALSE)
        {
            return ['producer_id' => NULL, 'product_id' => NULL, 'about' => '{}'];
        }

        if (strpos($sql, 'INSERT INTO nj_646_products') !== FALSE)
        {
            // Ключ в INSERT-е идёт первым значением — его и берём.
            preg_match("/VALUES\\s*\\(\\s*'((?:[^']|'')*)'/", $sql, $m);
            $key = str_replace("''", "'", $m[1] ?? '');

            self::$writes[] = 'INSERT key='.$key;

            return ['id' => self::add(['key' => $key, 'producer_id' => NULL, 'name_auto' => TRUE,
                                       'name' => '', 'brand' => '', 'kind' => 'auto', 'barcode' => '',
                                       'weight' => '', 'gost' => '', 'shelf_life' => '', 'storage' => '',
                                       'country' => '', 'product_kind' => '', 'hits' => 0, 'raw' => '{}'])];
        }

        if (strpos($sql, 'nj_646_products') === FALSE)
        {
            return NULL;
        }

        if (preg_match("/key = '((?:[^']|'')*)'/", $sql, $m))
        {
            $key = str_replace("''", "'", $m[1]);

            foreach (self::$products as $row)
            {
                if ((string) $row['key'] === $key)
                {
                    return $row;
                }
            }

            return NULL;
        }

        if (preg_match('/id = (\\d+)/', $sql, $m))
        {
            foreach (self::$products as $row)
            {
                if ((int) $row['id'] === (int) $m[1])
                {
                    return $row;
                }
            }
        }

        return NULL;
    }
}

class Fake_Result
{
    private $row;

    public function __construct($row) { $this->row = $row; }

    public function current() { return $this->row; }

    public function as_array() { return $this->row ? [$this->row] : []; }
}

class Database
{
    const SELECT = 1;
    const INSERT = 2;
    const UPDATE = 3;

    public static function instance() { return new self(); }

    public function quote($value) { return "'".str_replace("'", "''", (string) $value)."'"; }

    public function query($type, $sql, $as_object = NULL)
    {
        Fake_DB::$queries[] = $sql;

        return new Fake_Result(Fake_DB::find($sql));
    }
}

class DB_Expr { public $value; public function __construct($v) { $this->value = $v; } }

class DB_Query
{
    private $table;
    private $set   = [];
    private $where = [];

    public function __construct($table) { $this->table = $table; }

    public function set($fields) { $this->set = $fields; return $this; }

    public function where($column, $op = NULL, $value = NULL) { $this->where[] = [$column, $op, $value]; return $this; }

    public function execute()
    {
        $touched = 0;

        foreach (Fake_DB::$products as $i => $row)
        {
            $hit = TRUE;

            foreach ($this->where as $one)
            {
                if ((string) ($row[$one[0]] ?? '') !== (string) $one[2])
                {
                    $hit = FALSE;
                }
            }

            if ( ! $hit)
            {
                continue;
            }

            foreach ($this->set as $field => $value)
            {
                Fake_DB::$products[$i][$field] = $value instanceof DB_Expr ? (string) $value->value : $value;
            }

            Fake_DB::$writes[] = 'UPDATE '.$this->table.' id='.$row['id'].' set: '.implode(',', array_keys($this->set));
            $touched++;
        }

        return $touched;
    }
}

class DB
{
    public static function expr($value) { return new DB_Expr($value); }
    public static function query($type, $sql) { Fake_DB::$queries[] = $sql; return new DB_Query('(raw)'); }
    public static function update($table) { return new DB_Query($table); }
    public static function insert($table) { return new DB_Query($table); }
    public static function delete($table) { return new DB_Query($table); }
}

Kohana::$config = new Kohana_Config();
Kohana::$log    = new Kohana_Log();

spl_autoload_register(function ($class) use ($ROOT) {
    $path = str_replace('_', '/', $class);

    foreach ([
        $ROOT.'/application/modules/nj646_label/classes/'.$path.'.php',
    ] as $file)
    {
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

require $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Store.php';

echo "== 1. «свой прогон»: правило владения ==\n\n";

$run = ['id' => 5, 'visitor' => 'v-abc', 'user_id' => 0];
$user_run = ['id' => 6, 'visitor' => '', 'user_id' => 12];
$alien = ['id' => 7, 'visitor' => 'v-other', 'user_id' => 99];

$check('свой прогон по метке браузера — видим', Service_Nj646_Store::analysis_owned($run, 'v-abc', NULL) === TRUE);
$check('чужая метка и чужой вход — НЕ видим', Service_Nj646_Store::analysis_owned($alien, 'v-abc', NULL) === FALSE);
$check('тот же пользователь — видим', Service_Nj646_Store::analysis_owned($user_run, '', 12) === TRUE);
$check('другой пользователь — НЕ видим', Service_Nj646_Store::analysis_owned($user_run, '', 13) === FALSE);
$check('вход в систему без своей метки чужой прогон не открывает',
    Service_Nj646_Store::analysis_owned($alien, 'v-abc', 12) === FALSE);
$check('прогон без метки и без пользователя — НЕ видим (пустое ≠ своё)',
    Service_Nj646_Store::analysis_owned(['id' => 8, 'visitor' => '', 'user_id' => 0], 'v-abc', NULL) === FALSE);

$api = (string) file_get_contents($ROOT.'/application/modules/nj646_label/classes/Controller/Nj646/Api.php');

$check('Api: транскрипт берётся только у своего прогона (analysis_owned, не самопал)',
    strpos($api, 'analysis_owned((array) $run') !== FALSE
    AND strpos($api, '$own = $run AND') === FALSE);

echo "\n== 2. Карточка производителя: фраза, а не «1» ==\n\n";

$render = function (array $producer) use ($ROOT) {
    $producer = array_merge([
        'id' => 3, 'name' => 'ООО «Ромашка»', 'role' => 'producer', 'hits' => 4,
        'first_seen' => '2026-09-01 10:00:00', 'address' => '', 'inn' => '', 'ogrn' => '',
        'phone' => '', 'site' => '', 'country' => '', 'raw' => [],
        // Эти два поля готовит `Store::goods_view()` — без них вьюха ругалась бы
        // на неопределённые ключи (замечания ловим ниже).
        'ontology_url' => '', 'org_url' => '',
    ], $producer);

    return (string) View::factory('646/producer', [
        'producer' => $producer,
        'view'     => $producer,
        'products' => [],
        'analyses' => [],
        'admin'    => FALSE,
    ]);
};

$no_reqs   = $render([]);
$with_reqs = $render(['inn' => '7701234567']);

$check('без ИНН и ОГРН: «на этикетке этих реквизитов не было»',
    strpos($no_reqs, 'на этикетке этих реквизитов не было.') !== FALSE);
$check('без реквизитов страница не печатает «нет: 1»', strpos($no_reqs, 'нет: 1') === FALSE);
$check('с ИНН: «там такой организации ещё нет»',
    strpos($with_reqs, 'там такой организации ещё нет.') !== FALSE);

echo "\n== 3. Ключ товара: производитель в ключе ==\n\n";

$about = Service_Nj646_About::class;

$auto_soap = ['name' => 'Мыло без названия', 'brand' => '', 'name_auto' => TRUE];
$plain     = ['name' => 'Сметана 15%', 'brand' => '', 'name_auto' => FALSE];
$barcode   = ['name' => 'Сметана', 'brand' => '', 'name_auto' => TRUE, 'barcode' => '4600000000017'];

$check('рабочее имя: у разных производителей ключи разные (было «|1» у всех)',
    $about::product_key($auto_soap, 'p2') !== $about::product_key($auto_soap, 'p1'));
// Обычное (не рабочее) имя: производителя в ключе быть не должно — проверяем
// делом, через ту же поддельную базу: карточка должна найтись, а не завестись.
Fake_DB::reset();
$plain_id = Fake_DB::add([
    'key' => $about::product_key($plain, ''), 'producer_id' => 2, 'name' => $plain['name'], 'name_auto' => FALSE,
    'brand' => '', 'kind' => 'auto', 'barcode' => '', 'weight' => '', 'gost' => '',
    'shelf_life' => '', 'storage' => '', 'country' => '', 'product_kind' => '', 'hits' => 2, 'raw' => '{}',
]);

$plain_row = Service_Nj646_Store::product_upsert($plain, 2);

$check('обычное имя: карточка нашлась по ключу без производителя (новой не будет)',
    (int) ($plain_row['id'] ?? 0) === $plain_id AND count(Fake_DB::$products) === 1);
$check('штрихкод важнее всего: от производителя ключ не зависит',
    $about::product_key($barcode, 'p1') === $about::product_key($barcode, 'p2')
    AND $about::product_key($barcode, 'p1') === $about::product_key($barcode, ''));

echo "\n== 4. Переклейка старых ключей (поддельная база) ==\n\n";

// Строка, заведённая ДО исправления: ключ с «|1» вместо «|p2».
$legacy_key = $about::product_key($auto_soap, '1');
$good_key   = $about::product_key($auto_soap, 'p2');

Fake_DB::reset();
$legacy_id = Fake_DB::add([
    'key' => $legacy_key, 'producer_id' => 2, 'name' => 'Мыло без названия', 'name_auto' => TRUE,
    'brand' => '', 'kind' => 'auto', 'barcode' => '', 'weight' => '', 'gost' => '',
    'shelf_life' => '', 'storage' => '', 'country' => '', 'product_kind' => '', 'hits' => 3,
    'raw' => '{}', 'last_seen' => '2026-10-01', 'updated_at' => '2026-10-01',
]);

$row = Service_Nj646_Store::product_upsert($auto_soap, 2);

$check('карточка НЕ задублировалась: строка та же (id '.$legacy_id.')', (int) ($row['id'] ?? 0) === $legacy_id);
$check('новых INSERT-ов нет', count(Fake_DB::$products) === 1);
$check('ключ переклеен на верный (с производителем)',
    (string) Fake_DB::$products[0]['key'] === $good_key);
$check('в лог ушло, что ключ переклеен',
    count(array_filter(Kohana_Log::$events, function ($m) { return strpos($m, 'ключ товара') !== FALSE; })) === 1);

// Если верный ключ уже занят другой карточкой — ничего не переклеиваем.
Fake_DB::reset();
$legacy_id = Fake_DB::add([
    'key' => $legacy_key, 'producer_id' => 2, 'name' => 'Мыло без названия', 'name_auto' => TRUE,
    'brand' => '', 'kind' => 'auto', 'barcode' => '', 'weight' => '', 'gost' => '',
    'shelf_life' => '', 'storage' => '', 'country' => '', 'product_kind' => '', 'hits' => 1, 'raw' => '{}',
]);
$good_id = Fake_DB::add([
    'key' => $good_key, 'producer_id' => 2, 'name' => 'Мыло без названия', 'name_auto' => TRUE,
    'brand' => '', 'kind' => 'auto', 'barcode' => '', 'weight' => '', 'gost' => '',
    'shelf_life' => '', 'storage' => '', 'country' => '', 'product_kind' => '', 'hits' => 0, 'raw' => '{}',
]);

$row = Service_Nj646_Store::product_upsert($auto_soap, 2);

$check('верный ключ занят — берём готовую карточку, старую не трогаем',
    (int) ($row['id'] ?? 0) === $good_id
    AND (string) Fake_DB::$products[0]['key'] === $legacy_key
    AND count(Fake_DB::$products) === 2);

if ($php_errors)
{
    echo "\nзамечания PHP (".count($php_errors)."):\n";

    foreach (array_unique($php_errors) as $one)
    {
        echo '  · '.$one."\n";
    }
}

echo "\n".($fail ? "ПРОВАЛОВ: $fail" : 'провалов: 0')."\n";
