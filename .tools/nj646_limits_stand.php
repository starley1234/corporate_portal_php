<?php
/**
 * nj646_label: пределы ЗАПИСИ в `raw_text` и `result` (P2-10).
 *
 * Зачем стенд. Лимит на входе (`limits.text_max`) живёт в форме, а колонка
 * может получить текст другим путём — с поднятым env или из новой точки
 * приёма. Стенд проверяет именно страховку записи:
 *
 *   1. `Store::_text_cut()` — режет по БАЙТАМ, не ломая UTF-8: обрезка
 *      кириллицы не превращается в «кракозябру», а маленький текст не
 *      трогается вовсе;
 *   2. `analysis_save()` — в колонку уходит не больше предела, а в `about.cut`
 *      и в лог уходит ПРАВДА об обрезке (страница показывает «сохранено N из M»);
 *   3. `run_apply()` — jsonb прогона влезает в предел, остаётся валидным
 *      массивом (дожим `run_watch()` читает результат из этой же колонки),
 *      несёт `_cut` и `_cut_note`, а вложенные строки режутся так же;
 *   4. вырожденный случай (тысячи мелких строк) не раздувает jsonb: остаётся
 *      суть — статус и объяснение.
 *
 * Поддельная база ловит INSERT в `nj_646_analyses` (значения разбираются с
 * учётом кавычек и вложенных скобок) и UPDATE `nj_646_runs` — по ним и видно,
 * что легло бы в Postgres.
 *
 * Запуск (в песочнице PHP нет) — через PHP-in-WASM:
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj646_limits_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
$fail = 0;

$php_errors = [];

set_error_handler(function ($no, $str, $file, $line) use (&$fail, &$php_errors) {
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
    protected $data = ['nj646_label' => ['limits' => []]];

    public function set($group, array $values) { $this->data[$group] = $values; return $this; }

    public function load($group) { return $this->data[$group] ?? []; }
}

class Kohana_Log {
    public static $events = [];

    public function add($level, $message, $values = []) { Kohana_Log::$events[] = $message; }
}

class Fake_Result
{
    private $row;

    public function __construct($row) { $this->row = $row; }

    public function current() { return $this->row; }

    public function as_array() { return $this->row ? [$this->row] : []; }
}

/** Поддельная база: ловит записи в разборы и прогоны. */
class Fake_DB
{
    public static $inserts = [];
    public static $updates = [];
    public static $queries = [];

    public static function reset()
    {
        self::$inserts = [];
        self::$updates = [];
        self::$queries = [];
    }

    public static function query($sql)
    {
        self::$queries[] = $sql;

        if (strpos($sql, 'INSERT INTO nj_646_analyses') !== FALSE)
        {
            self::$inserts[] = [
                'table'   => 'nj_646_analyses',
                'columns' => self::columns($sql),
                'values'  => self::values($sql),
            ];

            return new Fake_Result(['id' => 101]);
        }

        return new Fake_Result(NULL);
    }

    /** Колонки INSERT-а — из перечня перед VALUES. */
    public static function columns($sql)
    {
        $start = strpos($sql, '(');
        $end   = strpos($sql, ')', $start);

        if ($start === FALSE OR $end === FALSE)
        {
            return [];
        }

        return array_map('trim', explode(',', substr($sql, $start + 1, $end - $start - 1)));
    }

    /**
     * Значения `VALUES (…)` верхнего уровня: одинарные кавычки и вложенные
     * скобки JSON-а не считаются разделителями.
     */
    public static function values($sql)
    {
        $pos = strpos($sql, 'VALUES');
        $s   = substr($sql, strpos($sql, '(', $pos) + 1);

        $items = [];
        $buf   = '';
        $depth = 0;
        $quote = FALSE;
        $len   = strlen($s);

        for ($i = 0; $i < $len; $i++)
        {
            $ch = $s[$i];

            if ($quote)
            {
                $buf .= $ch;

                if ($ch === "'")
                {
                    if ($i + 1 < $len AND $s[$i + 1] === "'")
                    {
                        $buf .= $s[++$i];
                        continue;
                    }

                    $quote = FALSE;
                }

                continue;
            }

            if ($ch === "'") { $quote = TRUE; $buf .= $ch; continue; }

            if ($ch === '(') { $depth++; $buf .= $ch; continue; }

            if ($ch === ')')
            {
                if ($depth === 0)
                {
                    $items[] = trim($buf);
                    break;
                }

                $depth--;
                $buf .= $ch;
                continue;
            }

            if ($ch === ',' AND $depth === 0)
            {
                $items[] = trim($buf);
                $buf     = '';
                continue;
            }

            $buf .= $ch;
        }

        return array_map([__CLASS__, 'plain'], $items);
    }

    /** Литерал SQL → значение: кавычки снимаются, `''` → `'`, NULL → NULL. */
    public static function plain($value)
    {
        $value = trim((string) $value);

        if (strcasecmp($value, 'NULL') === 0 OR $value === '')
        {
            return NULL;
        }

        if ($value[0] === "'" AND substr($value, -1) === "'")
        {
            $value = substr($value, 1, -1);
        }

        return str_replace("''", "'", $value);
    }
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
        return Fake_DB::query($sql);
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
        Fake_DB::$updates[] = [
            'table' => $this->table,
            'set'   => $this->set,
            'where' => $this->where,
        ];

        return 1;
    }
}

class DB
{
    public static function expr($value) { return new DB_Expr($value); }
    public static function query($type, $sql) { return new DB_Query('(raw)'); }
    public static function update($table) { return new DB_Query($table); }
    public static function insert($table) { return new DB_Query($table); }
    public static function delete($table) { return new DB_Query($table); }
}

Kohana::$config = new Kohana_Config();
Kohana::$log    = new Kohana_Log();

spl_autoload_register(function ($class) use ($ROOT) {
    $path = str_replace('_', '/', $class);
    $file = $ROOT.'/application/modules/nj646_label/classes/'.$path.'.php';

    if (is_file($file))
    {
        require $file;
    }
});

$check = function ($name, $ok) use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name."\n";

    if ( ! $ok) $fail++;
};

require $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Store.php';

// Пределы — те же, что в бою по умолчанию: 64 КБ на текст, 256 КБ на результат.
Kohana::$config->set('nj646_label', ['limits' => [
    'text_max'    => 8000,
    'raw_text_kb' => 64,
    'result_kb'   => 256,
]]);

/** Значение из set-а UPDATE: DB_Expr разворачиваем в строку. */
$plain = function ($value) {
    $value = $value instanceof DB_Expr ? (string) $value->value : $value;
    $value = (string) $value;

    if ($value !== '' AND $value[0] === "'" AND substr($value, -1) === "'")
    {
        $value = str_replace("''", "'", substr($value, 1, -1));
    }

    return $value;
};

echo "== 1. Обрезка текста по байтам, без «кракозябр» ==\n\n";

$text_cut = new ReflectionMethod('Service_Nj646_Store', '_text_cut');
$text_cut->setAccessible(TRUE);

$small = 'Состав: вода, соль.';
$check('маленький текст не тронут', $text_cut->invoke(NULL, $small, 4096) === $small);

// Каждый символ кириллицы — 2 байта: 3001-й по счёту знак разрежет строку
// ровно посередине символа, если резать substr()-ом.
$cyr   = str_repeat('Ы', 4000);
$cut   = $text_cut->invoke(NULL, $cyr, 6001);

$check('текст обрезан до предела по байтам', strlen($cut) <= 6001);
$check('кириллица не развалилась: символы целые', mb_check_encoding($cut, 'UTF-8') AND $cut === str_repeat('Ы', 3000));
// Минимум в 1 КБ — защита от абсурдного лимита (env со значением 0 или 1).
$check('латиница на границе режется ровно', $text_cut->invoke(NULL, str_repeat('a', 3000), 1024) === str_repeat('a', 1024));
$check('предел ниже килобайта не применяется буквально (защита от нуля)', $text_cut->invoke(NULL, 'короткий текст', 1) === 'короткий текст');

echo "\n== 2. Разбор: текст сверх предела и правда об обрезке ==\n\n";

Fake_DB::reset();
Kohana_Log::$events = [];

$huge     = str_repeat('Консервант Е211, ', 20000);   // ≈ 360 КБ
$analysis = Service_Nj646_Store::analysis_save([
    'visitor'  => 'v-stand', 'kind' => 'auto', 'source' => 'text',
    'title'    => 'Стенд', 'raw_text' => $huge, 'score' => 10, 'verdict' => 'neutral',
    'counts'   => [], 'items' => [], 'unknown' => [], 'profile' => [],
    'about'    => ['product' => ['name' => 'Мыло']],
]);

$insert = Fake_DB::$inserts[0] ?? ['columns' => [], 'values' => []];
$row    = array_combine($insert['columns'], $insert['values']) ?: [];
$about  = json_decode((string) ($row['about'] ?? ''), TRUE);

$check('INSERT в разборы один', count(Fake_DB::$inserts) === 1);
$check('в колонку ушёл текст не больше предела', isset($row['raw_text']) AND strlen((string) $row['raw_text']) <= 64 * 1024);
$check('текст обрезан по границе символа', mb_check_encoding((string) $row['raw_text'], 'UTF-8'));
$check('в `about.cut` — сколько было и сколько осталось',
    isset($about['cut']['raw_text']['was'], $about['cut']['raw_text']['kept'])
    AND (int) $about['cut']['raw_text']['was'] === strlen($huge)
    AND (int) $about['cut']['raw_text']['kept'] === strlen((string) $row['raw_text']));
$check('снимок этикетки при этом не потерян', ($about['product']['name'] ?? '') === 'Мыло');
$check('в лог ушло, что текст обрезан', count(Kohana_Log::$events) === 1);
$check('разбор получил id', $analysis === 101);

Fake_DB::reset();
Kohana_Log::$events = [];

$saved_id = Service_Nj646_Store::analysis_save([
    'visitor' => 'v-stand', 'kind' => 'auto', 'source' => 'text',
    'title' => 'Обычный', 'raw_text' => 'Вода, соль, сахар.', 'score' => 40, 'verdict' => 'useful',
    'counts' => [], 'items' => [], 'unknown' => [], 'profile' => [], 'about' => [],
]);

$row = array_combine(Fake_DB::$inserts[0]['columns'], Fake_DB::$inserts[0]['values']) ?: [];
$about = json_decode((string) ($row['about'] ?? ''), TRUE);

$check('обычный разбор: текст не тронут', ($row['raw_text'] ?? '') === 'Вода, соль, сахар.');
$check('обычный разбор: пометки об обрезке нет', empty($about['cut']));
$check('обычный разбор: в лог ничего не писали', Kohana_Log::$events === []);

echo "\n== 3. Прогон: результат сверх предела ==\n\n";

Fake_DB::reset();
Kohana_Log::$events = [];

$transcript = str_repeat('Состав: сахар, регулятор кислотности лимонная кислота. ', 6000);   // ≈ 300 КБ
$run_id = Service_Nj646_Store::run_apply(55, [
    'status' => 'done', 'mode' => 'photo',
    'text' => 'Вода, сахар',
    'transcript' => $transcript,
    'note' => 'мелкий шрифт',
    'model' => 'qwen-vl',
    'elapsed_ms' => 4200,
]);

$update = Fake_DB::$updates[0] ?? ['set' => [], 'where' => []];
$json   = $plain($update['set']['result'] ?? '');
$saved  = json_decode($json, TRUE);

$check('UPDATE прогона один, статус из столбца — done', $run_id === 'done' AND count(Fake_DB::$updates) === 1);
$check('jsonb прогона влез в предел записи', strlen($json) <= 256 * 1024);
$check('jsonb остался читаемым массивом (дожим не сломается)', is_array($saved));
$check('пометка `_cut` и объяснение на месте',
    ! empty($saved['_cut']) AND ! empty($saved['_cut_note']));
$check('статус и режим сохранены', ($saved['status'] ?? '') === 'done' AND ($saved['mode'] ?? '') === 'photo');
$check('длинный транскрипт сокращён, но не разрушен', mb_check_encoding((string) ($saved['transcript'] ?? ''), 'UTF-8')
    AND strlen((string) $saved['transcript']) < strlen($transcript));
$check('в лог ушло, что результат обрезан', count(Kohana_Log::$events) === 1);

Fake_DB::reset();
Kohana_Log::$events = [];

Service_Nj646_Store::run_apply(56, [
    'status' => 'done', 'mode' => 'photo', 'text' => 'Вода, сахар', 'transcript' => 'Состав: вода.',
    'note' => '', 'model' => 'qwen-vl', 'elapsed_ms' => 900,
]);

$json  = $plain(Fake_DB::$updates[0]['set']['result'] ?? '');
$saved = json_decode($json, TRUE);

$check('обычный прогон: результат не тронут', ($saved['transcript'] ?? '') === 'Состав: вода.' AND empty($saved['_cut']));
$check('обычный прогон: в лог ничего не писали', Kohana_Log::$events === []);

echo "\n== 4. Вложенные строки и вырожденный случай ==\n\n";

$json_cut = new ReflectionMethod('Service_Nj646_Store', '_json_cut');
$json_cut->setAccessible(TRUE);

$deep = $json_cut->invoke(NULL, [
    'status'     => 'done',
    'ingredient' => ['name_ru' => 'Е211', 'summary' => str_repeat('Бензоат натрия. ', 20000), 'sources' => [['text' => str_repeat('источник ', 20000)]]],
], 32 * 1024);

$check('вложенная строка тоже сокращена', strlen((string) $deep['ingredient']['summary']) <= 32 * 1024);
$check('источник во вложенном массиве сокращён', strlen((string) $deep['ingredient']['sources'][0]['text']) <= 32 * 1024);
$check('размер собранного jsonb в пределах предела записи',
    strlen(json_encode($deep, JSON_UNESCAPED_UNICODE)) <= 32 * 1024);
$check('пометка об обрезке стоит', ! empty($deep['_cut']));

$many = ['status' => 'done', 'mode' => 'photo', 'note' => 'ок', 'error' => ''];

for ($i = 0; $i < 40000; $i++)
{
    $many['rows'][$i] = 'строка '.$i;
}

$many = $json_cut->invoke(NULL, $many, 16 * 1024);

$check('тысячи мелких строк: jsonb в пределе', strlen(json_encode($many, JSON_UNESCAPED_UNICODE)) <= 16 * 1024);
$check('тысячи мелких строк: осталась суть — статус и объяснение',
    ! empty($many['_cut']) AND ($many['status'] ?? '') === 'done');

echo "\n== 5. Пределы приходят из конфига ==\n\n";

$config = (string) file_get_contents($ROOT.'/application/modules/nj646_label/config/nj646_label.php');
$env    = (string) file_get_contents($ROOT.'/.env.example');

$check('конфиг: предел raw_text из env', strpos($config, "NJ646_MAX_RAW_TEXT_KB") !== FALSE);
$check('конфиг: предел результата из env', strpos($config, "NJ646_MAX_RESULT_KB") !== FALSE);
$check('пример окружения описывает оба предела', strpos($env, 'NJ646_MAX_RAW_TEXT_KB=64') !== FALSE
    AND strpos($env, 'NJ646_MAX_RESULT_KB=256') !== FALSE);
$check('пределы читаются через Store::limit()',
    Service_Nj646_Store::limit('raw_text_kb', 0) === 64 AND Service_Nj646_Store::limit('result_kb', 0) === 256);

$view = (string) file_get_contents($ROOT.'/application/modules/nj646_label/views/646/analysis.php');

$check('страница разбора говорит об обрезке вслух', strpos($view, "cut']['raw_text']") !== FALSE
    AND strpos($view, 'сохранили') !== FALSE);

if ($php_errors)
{
    echo "\nзамечания PHP (".count($php_errors)."):\n";

    foreach (array_unique($php_errors) as $one)
    {
        echo '  · '.$one."\n";
    }
}

echo "\n".($fail ? "ПРОВАЛОВ: $fail" : 'провалов: 0')."\n";
