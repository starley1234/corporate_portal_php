<?php
/**
 * nj654_similar: чистая логика банка картинок и все три вьюхи без БД.
 *
 * Зачем стенд. В песочнице нет ни PHP, ни PostgreSQL, а ошибиться в модуле
 * можно задолго до базы: шкала похожести, отпечаток дубля, литерал вектора,
 * защита пути к файлу. Здесь всё это ВЫПОЛНЯЕТСЯ, а заодно рендерятся три
 * страницы модуля — так ловятся опечатки во вьюхах и обращения к
 * несуществующим переменным, которые линт пропускает.
 *
 * Запуск через PHP-in-WASM:
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj654_similar_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');
if ( ! defined('DOCROOT')) define('DOCROOT', sys_get_temp_dir().'/nj654docroot/');

$ROOT = dirname(__DIR__);
$MOD  = $ROOT.'/application/modules/nj654_similar';

// Предупреждения PHP — тоже провал: в бою это «Undefined variable» посреди
// страницы, и увидеть его надо здесь.
$fail = 0;

set_error_handler(function ($no, $str, $file, $line) use (&$fail) {
    echo '  ПРОВАЛ  PHP: '.$str.' ('.$file.':'.$line.")\n";
    $fail++;

    return TRUE;
});

class Kohana {
    public static $config;
    public static $charset = 'utf-8';
}

class HTML {
    public static function chars($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Замок «только по аккаунту» (application/classes/Gate.php) — заглушка стенда.
 * Стенд запускает вьюхи напрямую, без ядра: здесь повторяем ровно тот контракт,
 * на который опирается вёрстка — `logged_in()` и `wall()`.
 */
class Gate {
    public static $logged = TRUE;

    public static function logged_in() { return (bool) self::$logged; }

    public static function worker_action($action) { return FALSE; }

    public static function wall($return_url = '', array $options = []) {
        return View::factory('partials/auth_wall', $options + ['return_url' => (string) $return_url]);
    }
}


class Log { const ERROR = 3; }

class Log_Plain {
    public static function event($level, $message, array $values = []) {}
}

/** Конфиг модуля читаем настоящий — пороги шкалы проверяются как есть. */
class StandConfig {
    protected $files = [];

    public function load($key)
    {
        $parts = explode('.', (string) $key);
        $file  = array_shift($parts);

        if ( ! isset($this->files[$file]))
        {
            $path = dirname(__DIR__).'/application/modules/nj654_similar/config/'.$file.'.php';
            $this->files[$file] = is_file($path) ? (array) include $path : [];
        }

        $value = $this->files[$file];

        foreach ($parts as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value)) return NULL;

            $value = $value[$part];
        }

        return $value;
    }
}

Kohana::$config = new StandConfig;

/** Заглушка общего слоя: нам важны только разбор картинки и наличие таблиц. */
class Vision {
    public static $tables = TRUE;

    public static function table_exists($table) { return self::$tables; }

    public static function looks_like_image($bytes)
    {
        return strncmp((string) $bytes, "\x89PNG", 4) === 0
            OR strncmp((string) $bytes, "\xFF\xD8\xFF", 3) === 0;
    }

    public static function mime($bytes)
    {
        return strncmp((string) $bytes, "\x89PNG", 4) === 0 ? 'image/png' : 'image/jpeg';
    }

    public static function json_decode($value)
    {
        $out = json_decode((string) $value, TRUE);

        return is_array($out) ? $out : [];
    }
}

/** База, которая ничего не знает: проверяем SQL-сборку, а не выполнение. */
class StandResult {
    protected $rows;

    public function __construct(array $rows) { $this->rows = $rows; }

    public function current() { return $this->rows ? $this->rows[0] : FALSE; }

    public function as_array() { return $this->rows; }
}

class Database {
    const SELECT = 1;
    const UPDATE = 3;
    const DELETE = 4;

    public static $sql = [];
    public static $answer = [];

    public static function instance() { return new self; }

    public function query($type, $sql, $as_object = TRUE)
    {
        self::$sql[] = preg_replace('/\s+/', ' ', trim((string) $sql));

        // Как настоящая Kohana: SELECT отдаёт набор строк, UPDATE и DELETE —
        // число изменённых строк. Иначе стенд не поймает «(int) результат».
        if ($type !== self::SELECT) return 1;

        foreach (self::$answer as $needle => $rows)
        {
            if (strpos($sql, (string) $needle) !== FALSE) return new StandResult($rows);
        }

        return new StandResult([]);
    }

    public function quote($value)
    {
        if ($value === NULL) return 'NULL';

        return "'".str_replace("'", "''", (string) $value)."'";
    }
}

class View {
    private $name;
    private $data;

    public function __construct($name, array $data) { $this->name = $name; $this->data = $data; }

    public static function factory($name, array $data = []) { return new self($name, $data); }

    public function __toString()
    {
        $path = strpos($this->name, 'partials/') === 0
            ? dirname(__DIR__).'/application/views/'.$this->name.'.php'
            : dirname(__DIR__).'/application/modules/nj654_similar/views/'.$this->name.'.php';

        extract($this->data);
        ob_start();

        require $path;

        return ob_get_clean();
    }
}

require $MOD.'/classes/Service/Nj654/Bank.php';

$check = function ($name, $ok, $why = '') use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name
        .(( ! $ok AND $why !== '') ? ': '.$why : '')."\n";

    if ( ! $ok) $fail++;
};

// ---------------------------------------------------------------------------
echo "== Шкала похожести ==\n\n";

foreach ([0.95, 0.65, 0.45, 0.30, 0.05] as $score)
{
    $say = Service_Nj654_Bank::say($score);
    echo '  '.number_format($score, 2).' → '.$say['title']."\n";
}

$check('0,95 — «почти то же самое»',
    Service_Nj654_Bank::say(0.95)['title'] === 'почти то же самое');
$check('0,30 — «что-то общее есть»',
    Service_Nj654_Bank::say(0.30)['title'] === 'что-то общее есть');
$check('0,05 — «непохоже»', Service_Nj654_Bank::say(0.05)['title'] === 'непохоже');
$check('близость больше единицы режется', Service_Nj654_Bank::say(3.0)['value'] === 1.0);
$check('близость меньше минус единицы режется', Service_Nj654_Bank::say(-9.0)['value'] === -1.0);

$previous = 2.0;
$ordered  = TRUE;

foreach ((array) Kohana::$config->load('nj654_similar.scale') as $step)
{
    if ((float) $step['from'] >= $previous) $ordered = FALSE;

    $previous = (float) $step['from'];
}

$check('пороги шкалы убывают и доходят до -1', $ordered AND $previous === -1.0);

// ---------------------------------------------------------------------------
echo "\n== Отпечаток: дубли в банк не кладём ==\n\n";

$a = Service_Nj654_Bank::fingerprint('Рыжий  КОТ ', 'байты');
$b = Service_Nj654_Bank::fingerprint('рыжий кот', 'байты');
$c = Service_Nj654_Bank::fingerprint('рыжий кот', 'другие байты');
$d = Service_Nj654_Bank::fingerprint('рыжий кот');

$check('регистр и лишние пробелы не меняют отпечаток', $a === $b);
$check('другая картинка — другой отпечаток', $b !== $c);
$check('тот же текст без картинки — отдельный отпечаток', $b !== $d);
$check('отпечаток — 64 знака sha256', strlen($a) === 64);

// ---------------------------------------------------------------------------
echo "\n== Путь к файлу: наружу из хранилища не пускаем ==\n\n";

$check('обход каталога отбит', Service_Nj654_Bank::file_path('../../etc/passwd') === '');
$check('абсолютный путь отбит', Service_Nj654_Bank::file_path('/etc/passwd') === '');
$check('пустой путь отбит', Service_Nj654_Bank::file_path('') === '');
$check('нормальный путь принят',
    substr(Service_Nj654_Bank::file_path('202610/abc.jpg'), -19) === 'nj654/202610/abc.jpg'
    OR strpos(Service_Nj654_Bank::file_path('202610/abc.jpg'), '202610') !== FALSE);
$check('публичный адрес ведёт в storage',
    Service_Nj654_Bank::file_url('202610/abc.jpg') === '/storage/nj654/202610/abc.jpg');
$check('пустой файл — пустой адрес', Service_Nj654_Bank::file_url('') === '');

// ---------------------------------------------------------------------------
echo "\n== Литерал вектора ==\n\n";

Database::$answer = ["information_schema.columns" => [['data_type' => 'USER-DEFINED']]];
$literal = Service_Nj654_Bank::vector_literal([0.5, -0.25]);

echo '  pgvector: '.$literal."\n";

$check('pgvector получает квадратные скобки', $literal[0] === '[');
$check('точка как разделитель дроби (локаль не вмешивается)',
    strpos($literal, '0.5000000') !== FALSE);

// ---------------------------------------------------------------------------
echo "\n== Отбор элементов ==\n\n";

Database::$sql = [];
Service_Nj654_Bank::items(['visitor' => "ва'ся", 'status' => 'queued', 'search' => 'кот'], 10);
$sql = end(Database::$sql);

$check('кавычка в метке браузера экранирована', strpos($sql, "''") !== FALSE);
$check('отбор по статусу попал в запрос', strpos($sql, "status = 'queued'") !== FALSE);
$check('поиск по подписи и тексту', strpos($sql, 'title ILIKE') !== FALSE
    AND strpos($sql, 'note ILIKE') !== FALSE);

Database::$sql = [];
Service_Nj654_Bank::items(['ids' => []], 10);

$check('пустой список id не превращается в «всё»',
    strpos(end(Database::$sql), 'FALSE') !== FALSE);

Database::$sql = [];
Service_Nj654_Bank::items(['ids' => ['7; DROP TABLE x', 9]], 10);

$check('id приводятся к числам', strpos(end(Database::$sql), 'id IN (7,9)') !== FALSE);

// ---------------------------------------------------------------------------
echo "\n== Поиск ==\n\n";

Database::$sql = [];
Database::$answer = [
    'information_schema.columns' => [['data_type' => 'USER-DEFINED']],
    'ORDER BY distance' => [
        ['id' => 3, 'title' => 'Кот', 'note' => '', 'kind' => 'image',
         'file' => '202610/a.jpg', 'width' => 800, 'height' => 600, 'distance' => 0.2],
    ],
];

$hits = Service_Nj654_Bank::search([0.1, 0.2], 50);

$check('дистанция 0,2 → близость 0,8', $hits[0]['score'] === 0.8);
$check('перебор ограничен top_max', strpos(end(Database::$sql), 'LIMIT 10') !== FALSE);
$check('ищем только готовые',
    strpos(end(Database::$sql), "status = 'ready'") !== FALSE);
$check('пустой вектор ничего не ищет', Service_Nj654_Bank::search([], 1) === []);

Vision::$tables = FALSE;
$check('без таблиц поиск молчит, а не падает', Service_Nj654_Bank::search([0.1], 1) === []);
$check('без таблиц сводка честно говорит «не готово»',
    Service_Nj654_Bank::stats()['ready'] === FALSE);
Vision::$tables = TRUE;

// ---------------------------------------------------------------------------
echo "\n== Отказ: задача не теряется молча ==\n\n";

Database::$sql = [];
Service_Nj654_Bank::fail('nj_654_items', [4, 'мусор', 6], "сломалось'");
$sql = end(Database::$sql);

$check('отказ проставлен только числовым id', strpos($sql, 'id IN (4,6)') !== FALSE);
$check('причина экранирована', strpos($sql, "''") !== FALSE);
$check('пустой список — ни одного запроса', Service_Nj654_Bank::fail('nj_654_items', [], 'x') === 0);

// ---------------------------------------------------------------------------
echo "\n== Приёмка результата воркера ==\n\n";

Database::$sql = [];
$done = Service_Nj654_Bank::accept(['kind' => 'items', 'model' => 'qwen3-vl',
    'items' => [
        ['id' => 1, 'vector' => [0.1, 0.2]],
        ['id' => 2, 'error' => 'битая картинка'],
    ]]);

$all = implode("\n", Database::$sql);

$check('готов один элемент', $done['note'] === 'готово элементов: 1');
$check('удачному проставлен ready', strpos($all, "status = 'ready'") !== FALSE);
$check('неудачному проставлен error', strpos($all, "status = 'error'") !== FALSE);
$check('размерность сохранена', strpos($all, 'dims = 2') !== FALSE);

Database::$answer['FROM nj_654_queries WHERE id'] = [[
    'id' => 5, 'top' => 3, 'status' => 'queued', 'hits' => '[]', 'text' => 'кот',
    'kind' => 'text', 'error' => '', 'created_at' => '2026-10-05 10:00', 'visitor' => 'v1',
    'client_ip' => '', 'file' => '', 'elapsed_ms' => 0]];

Database::$sql = [];
$query_done = Service_Nj654_Bank::accept(['kind' => 'query', 'id' => 5,
    'model' => 'qwen3-vl', 'vector' => [0.1, 0.2], 'elapsed_ms' => 120]);

$check('поиск закрыт находками', strpos($query_done['note'], 'находок') === 0);
$check('находки записаны в журнал как jsonb',
    strpos(end(Database::$sql), '::jsonb') !== FALSE);

$check('поиск без вектора закрывается отказом, а не висит',
    Service_Nj654_Bank::accept(['kind' => 'query', 'id' => 5, 'error' => 'нет связи'])['note']
        === 'поиск закрыт отказом');
unset(Database::$answer['FROM nj_654_queries WHERE id']);

$check('неизвестный поиск не роняет приёмку',
    Service_Nj654_Bank::accept(['kind' => 'query', 'id' => 999999])['ok'] === FALSE);

// ---------------------------------------------------------------------------
echo "\n== Уменьшение картинки перед сохранением ==\n\n";

$check('не-картинка в банк не попадает', Service_Nj654_Bank::save_image('просто текст') === []);
$check('пустые байты в банк не попадают', Service_Nj654_Bank::save_image('') === []);

if (function_exists('imagecreatetruecolor'))
{
    $img = imagecreatetruecolor(2400, 1200);
    ob_start();
    imagejpeg($img);
    $big = (string) ob_get_clean();
    imagedestroy($img);

    $small = Service_Nj654_Bank::shrink($big);

    echo '  2400 px → '.$small['width']." px\n";

    $check('широкая картинка уменьшена до предела конфига', $small['width'] === 1024);
    $check('уменьшенная картинка легче исходной', strlen($small['bytes']) < strlen($big));
    $check('уменьшенная картинка — JPEG', $small['mime'] === 'image/jpeg');

    $saved = Service_Nj654_Bank::save_image($big, 'фото.jpg');

    $check('файл сохранён под хешем и годом-месяцем',
        (bool) preg_match('~^\d{6}/[0-9a-f]{40}\.jpg$~', (string) ($saved['file'] ?? '')));
    $check('ширина сохранённого файла записана', (int) ($saved['width'] ?? 0) === 1024);

    list($b64, $mime) = Service_Nj654_Bank::file_b64((string) $saved['file']);

    $check('файл читается обратно для воркера', strlen($b64) > 100 AND $mime === 'image/jpeg');
}
else
{
    echo "  (GD нет — проверка уменьшения пропущена)\n";
}

// ---------------------------------------------------------------------------
echo "\n== Вьюхи ==\n\n";

$stats = ['ready' => TRUE, 'pgvector' => TRUE, 'total' => 12, 'waiting' => 1, 'failed' => 1,
    'images' => 10, 'bytes' => 2345678, 'queries' => 4, 'model' => 'qwen3-vl-embedding-2b'];

$items = [
    ['id' => 1, 'user_id' => NULL, 'visitor' => 'v1', 'title' => 'Кот <script>', 'note' => 'рыжий',
     'kind' => 'image', 'file' => '202610/a.jpg', 'mime' => 'image/jpeg', 'width' => 1024,
     'height' => 768, 'bytes' => 120000, 'model' => 'm', 'dims' => 1024, 'status' => 'ready',
     'error' => '', 'created_at' => '2026-10-05 10:00:00'],
    ['id' => 2, 'user_id' => NULL, 'visitor' => 'чужой', 'title' => 'Текст', 'note' => 'закат',
     'kind' => 'text', 'file' => '', 'mime' => '', 'width' => 0, 'height' => 0, 'bytes' => 0,
     'model' => '', 'dims' => 0, 'status' => 'queued', 'error' => '',
     'created_at' => '2026-10-05 10:01:00'],
    ['id' => 3, 'user_id' => NULL, 'visitor' => 'v1', 'title' => 'Битая', 'note' => '',
     'kind' => 'image', 'file' => '', 'mime' => '', 'width' => 0, 'height' => 0, 'bytes' => 0,
     'model' => '', 'dims' => 0, 'status' => 'error', 'error' => 'модель недоступна',
     'created_at' => '2026-10-05 10:02:00'],
];

// ---------------------------------------------------------------------------
// Вьюхи поиска: три варианта оформления и один движок без перезагрузки.
//
// Владелец (08.10.2026): «сделай три варианта интерфейса — потом выберу»;
// «нажал Поиск — форма с полями пропала, вместо неё что ищем и индикатор
// процесса, находки появились плитками ниже, без перезагрузки»; «фокус в поле
// после отправки не возвращается — на телефоне клавиатура закрывает результаты».
//
// Здесь проверяется разметка всех трёх вариантов и договор движка с сервером
// (якоря data-nj654-*). Сам JS гоняется отдельно, на этой же вёрстке:
// `.tools/nj654_similar_ui.mjs` (jsdom). Стенд умеет отдавать ему страницы:
// `php .tools/nj654_similar_stand.php --dump-ui=/tmp/nj654-ui`.
// ---------------------------------------------------------------------------

$hit = ['id' => 1, 'title' => 'Кот <script>', 'kind' => 'image',
    'file' => '202610/a.jpg', 'score' => 0.81];

// Метка свежая: секундомер считается от постановки задачи, и запрос
// трёхдневной давности движок справедливо считает зависшим (300 с).
$query_ready = ['id' => 5, 'text' => 'рыжий кот <b>', 'status' => 'ready', 'error' => '',
    'created_at' => date('Y-m-d H:i:s'), 'elapsed_ms' => 1234, 'top' => 5, 'file' => '', 'hits' => [$hit]];
$query_busy = ['id' => 6, 'text' => 'рыжий кот <b>', 'status' => 'queued', 'error' => '',
    'created_at' => date('Y-m-d H:i:s'), 'top' => 5, 'file' => '', 'hits' => []];
$query_dead = ['id' => 7, 'text' => 'рыжий кот <b>', 'status' => 'error',
    'error' => 'Очередь <недоступна>', 'created_at' => date('Y-m-d H:i:s'),
    'top' => 3, 'file' => '', 'hits' => []];

// Оформление одно: владелец выбрал первое («Рабочая колонка») 09.10.2026,
// вьюхи 654/variants/two и 654/variants/three и переключатель `?ui=` удалены.
// Поэтому стенд отдаёт JS-проверке один набор страниц — ui1-*.html.
$ui_variants = [
    1 => ['view' => '654/search_body', 'name' => 'Рабочая колонка', 'note' => 'форма 2/3 и колонка с состоянием банка'],
];

/** Страница поиска одного варианта: без запроса, в работе, готовая, с отказом. */
function stand_ui_page($ui, $variants, $stats, $query, $hits, $admin = TRUE, $logged = TRUE)
{
    return (string) View::factory('654/search', [
        'logged'      => (bool) $logged,
        'admin'       => $admin,
        'ui'          => $ui,
        'variants'    => $variants,
        'stats'       => $stats,
        'query'       => $query,
        'hits'        => $hits,
        'result_page' => $query !== NULL,
        'top'         => 5,
        'max'         => 10,
        'mb'          => 12,
        'csrf'        => 'tok',
        'flash'       => '',
    ]);
}

$ui_pages = [];

foreach ($ui_variants as $ui => $variant)
{
    $ui_pages[$ui] = [
        'fresh' => stand_ui_page($ui, $ui_variants, $stats, NULL, []),
        'ready' => stand_ui_page($ui, $ui_variants, $stats, $query_ready, [$hit]),
        'busy'  => stand_ui_page($ui, $ui_variants, $stats, $query_busy, []),
        'dead'  => stand_ui_page($ui, $ui_variants, $stats, $query_dead, []),
    ];
}

// Как раньше: «страница поиска» — это готовый поиск в первом варианте.
$search_page  = $ui_pages[1]['ready'];
$search_fresh = $ui_pages[1]['fresh'];
$waiting_page = $ui_pages[1]['busy'];
$dead_page    = $ui_pages[1]['dead'];

/* Отдать готовые страницы JS-проверке движка (jsdom) и выйти: ей нужен ровно
   этот HTML — тот же, что видит браузер, без сборки вёрстки на месте. */
foreach ((array) ($argv ?? []) as $stand_arg)
{
    if (strpos((string) $stand_arg, '--dump-ui=') !== 0) { continue; }

    $dump = rtrim(substr((string) $stand_arg, 10), '/');

    if ($dump === '' OR ! is_dir($dump) AND ! @mkdir($dump, 0777, TRUE)) { continue; }

    foreach ($ui_pages as $ui => $states)
    {
        foreach ($states as $state => $html)
        {
            file_put_contents($dump.'/ui'.$ui.'-'.$state.'.html', $html);
        }
    }

    echo "  HTML для JS-проверки: {$dump}\n";

    exit($fail === 0 ? 0 : 1);
}

$variant_cards = [];

foreach ($ui_pages as $ui => $states)
{
    $fresh = preg_replace('/\s+/', ' ', $states['fresh']);
    $ready = preg_replace('/\s+/', ' ', $states['ready']);

    // Классы блока формы и карточки запроса: по ним видно, что показано сейчас.
    $form_found  = (bool) preg_match('~class="([^"]*)"\s+data-nj654-form~', $states['fresh'], $form_class);
    $panel_found = (bool) preg_match('~class="([^"]*)"\s+data-nj654-panel~', $states['fresh'], $panel_class);
    preg_match('~class="([^"]*)"\s+data-nj654-panel~', $states['ready'], $panel_class_ready);
    preg_match('~class="([^"]*)"\s+data-nj654-form~', $states['ready'], $form_class_ready);
    preg_match('~class="([^"]*)" data-nj654-results~', $states['fresh'], $res_class);
    preg_match('~class="([^"]*)" data-nj654-results~', $states['ready'], $res_class_ready);

    $form_class        = (string) ($form_class[1] ?? '');
    $panel_class       = (string) ($panel_class[1] ?? '');
    $form_class_ready  = (string) ($form_class_ready[1] ?? '');
    $panel_class_ready = (string) ($panel_class_ready[1] ?? '');
    $res_class         = (string) ($res_class[1] ?? '');
    $res_class_ready   = (string) ($res_class_ready[1] ?? '');

    // Движок: скрипт целиком — чтобы проверять именно его, а не общий шаблон.
    $engine = '';

    if (preg_match('~window\.NJ654.*?</script>~s', $states['fresh'], $engine_hit))
    {
        $engine = $engine_hit[0];
    }

    $variant_cards[$ui] = compact('fresh', 'ready', 'engine', 'form_class', 'panel_class',
        'form_class_ready', 'panel_class_ready', 'res_class', 'res_class_ready');

    $check('вариант '.$ui.': форма поиска с CSRF, кнопкой «Найти» и полем текста',
        strpos($fresh, 'id="nj654-form"') !== FALSE
        AND strpos($fresh, 'method="post" action="/similar/find"') !== FALSE
        AND strpos($fresh, 'name="csrf" value="tok"') !== FALSE
        AND strpos($fresh, 'id="nj654-text"') !== FALSE
        AND strpos($fresh, 'id="nj654-go"') !== FALSE);

    $check('вариант '.$ui.': карточка запроса, сетка и заготовка плитки стоят на странице заранее',
        strpos($states['fresh'], 'data-nj654-panel') !== FALSE
        AND strpos($states['fresh'], 'data-nj654-results') !== FALSE
        AND strpos($states['fresh'], 'data-nj654-grid') !== FALSE
        AND strpos($states['fresh'], 'template data-nj654-tile-tpl') !== FALSE
        AND strpos($states['fresh'], 'data-nj654-tile-score') !== FALSE);

    $check('вариант '.$ui.': до поиска форма видна, карточка запроса и находки скрыты',
        $form_found AND strpos($form_class, 'hidden') === FALSE
        AND $panel_found AND strpos($panel_class, 'hidden') !== FALSE
        AND strpos($res_class, 'hidden') !== FALSE);

    $check('вариант '.$ui.': после поиска форма уступает место карточке запроса',
        strpos($form_class_ready, 'hidden') !== FALSE
        AND strpos($panel_class_ready, 'hidden') === FALSE
        AND strpos($res_class_ready, 'hidden') === FALSE);

    $check('вариант '.$ui.': движок без перезагрузки подключён и фокус не возвращает',
        $engine !== ''
        AND strpos($engine, '.blur()') !== FALSE
        AND strpos($engine, '.focus(') === FALSE
        AND strpos($engine, 'XMLHttpRequest') !== FALSE
        AND strpos($engine, 'history.replaceState') !== FALSE);

    $check('вариант '.$ui.': шапка страницы без крошек и без второго ряда ссылок',
        strpos($fresh, 'Хлебные крошки') === FALSE
        AND strpos($fresh, 'w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8') !== FALSE);
}

/* Оформление одно, переключателя нет (владелец, 09.10.2026). Проверка стоит
   здесь не ради истории: движок хранил в себе `?ui=` с несуществующей
   переменной `ui_num`, падал ровно на смене адреса — поиск не запускался, а
   человек вместо результата читал «Нет связи с сервером» (ошибка уходила в
   catch у fetch). Поэтому смотрим и страницу, и текст движка. */
$check('оформление одно: переключателя вариантов и ?ui= нет ни на странице, ни в движке',
    strpos($ui_pages[1]['fresh'], 'data-nj654-ui-switch') === FALSE
    AND strpos($ui_pages[1]['fresh'], '?ui=') === FALSE
    AND strpos($variant_cards[1]['engine'], '?ui=') === FALSE
    AND strpos($variant_cards[1]['engine'], 'ui_num') === FALSE);

$check('готовый поиск показан «Готово» с числом находок',
    strpos($variant_cards[1]['ready'], 'найдено: 1') !== FALSE
    AND strpos($variant_cards[1]['ready'], 'Готово') !== FALSE
    AND strpos($variant_cards[1]['ready'], '0,810') !== FALSE);

$check('запрос в работе: карточка запроса со статус-рядом и без полос хода',
    strpos($waiting_page, 'data-nj654-status="queued"') !== FALSE
    AND strpos($waiting_page, 'data-nj654-id="6"') !== FALSE
    AND strpos($waiting_page, 'Результаты появятся здесь сами') !== FALSE
    AND strpos($waiting_page, 'n2j-spin') !== FALSE
    AND strpos($waiting_page, 'role="progressbar"') === FALSE);

$check('отказ воркера показан причиной и кнопкой возврата к запросу',
    strpos($dead_page, 'Очередь &lt;недоступна&gt;') !== FALSE
    AND strpos($dead_page, 'data-nj654-reset') !== FALSE
    AND strpos($dead_page, 'Поиск не выполнен') !== FALSE);

$check('готовый поиск не запускает опрос состояния зря (статус терминальный)',
    strpos($variant_cards[1]['engine'], "state.status === 'queued' || state.status === 'running'") !== FALSE
    AND strpos($variant_cards[1]['engine'], 'data-nj654-id') !== FALSE);

/* Договор с сервером: движок ждёт JSON, обычная форма — прежний 303; статус
   отдаёт карточки находок, чтобы плитки достраивались без перезагрузки. */
$controller_src = (string) file_get_contents($MOD.'/classes/Controller/Nj654/Similar.php');
$api_src        = (string) file_get_contents($MOD.'/classes/Controller/Nj654/Api.php');

$check('сервер отвечает движку JSON-ом, а форме без JS — прежним 303',
    strpos($controller_src, '_wants_json()') !== FALSE
    AND substr_count($controller_src, "'/similar/result/'.") >= 2
    AND strpos($controller_src, "'cards'") === FALSE);

$check('состояние поиска отдаёт карточки находок для догрузки без перезагрузки',
    strpos($api_src, "'cards'") !== FALSE
    AND strpos($api_src, "'found'") !== FALSE
    AND strpos($api_src, 'Service_Nj654_Bank::cards') !== FALSE);

$cards = Service_Nj654_Bank::cards([$hit]);

$check('карточка находки совпадает с плиткой: подпись, число, словесная оценка',
    isset($cards[0])
    AND $cards[0]['title'] === 'Кот <script>'
    AND $cards[0]['score_text'] === '0,810'
    AND $cards[0]['say'] === 'почти то же самое'
    AND $cards[0]['tone'] === 'emerald'
    AND $cards[0]['file'] === '/storage/nj654/202610/a.jpg');

/* Чипы раздела: у модуля своя навигация, и каталог не подмешивает свои чипы —
   иначе рядом с «Похожие картинки» встаёт «Похожие картинки · журнал». */
$manifest = require $MOD.'/config/nj_module.php';

$check('чипы раздела названы коротко: «Журнал» вместо «Похожие картинки · журнал»',
    ($manifest['admin']['title'] ?? '') === 'Журнал'
    AND ($manifest['header_chips'] ?? NULL) === 'own');

preg_match_all("~\\['title' => '([^']+)', 'href' => '([^']+)'~u", $controller_src, $nav_hits);

$nav_order = array_slice(array_map(function ($t, $h) { return $t.' '.$h; },
    $nav_hits[1] ?? [], $nav_hits[2] ?? []), 0, 3);

$check('ряд чипов: «Похожие картинки», «Журнал», «Банк картинок»',
    $nav_order === ['Похожие картинки /similar', 'Журнал /similar/admin', 'Банк картинок /similar/collection']);

$catalog_src = (string) file_get_contents(
    dirname(__DIR__).'/application/modules/nj110_catalog/classes/Service/Nj110/Catalog.php');

$check("каталог умеет отдавать чипы раздела модулю (header_chips => 'own')",
    strpos($catalog_src, "=== 'own'") !== FALSE
    AND strpos($catalog_src, 'return NULL;') !== FALSE);

/* Гость: вместо формы поиска — плашка входа (замок 08.10.2026,
   partials/auth_wall). Ни формы, ни полей ввода у гостя быть не должно. */
Gate::$logged = FALSE;
$guest_page = stand_ui_page(1, $ui_variants, [], NULL, [], FALSE, FALSE);
Gate::$logged = TRUE;

$check('гость видит плашку входа вместо формы поиска',
    strpos($guest_page, 'data-auth-wall="similar"') !== FALSE
    AND strpos($guest_page, 'id="nj654-form"') === FALSE
    AND strpos($guest_page, 'id="nj654-text"') === FALSE
    AND strpos($guest_page, '/auth/login?return_url=similar') !== FALSE);

$collection_data = [
    'stats' => $stats, 'items' => $items, 'total' => 120,
    'filter' => ['search' => ''], 'mine' => FALSE,
    'grid' => TRUE, 'page' => 2, 'pages' => 3, 'edit' => NULL,
    'limits' => ['bulk' => 20, 'mb' => 12, 'width' => 1024],
    'visitor' => 'v1', 'admin' => FALSE, 'csrf' => 'tok', 'flash' => '',
];

$collection = (string) View::factory('654/collection', $collection_data);
$list_view  = (string) View::factory('654/collection',
    ['grid' => FALSE, 'edit' => $items[0]] + $collection_data);

$check('в банке есть массовая загрузка',
    strpos($collection, 'name="images[]"') !== FALSE AND strpos($collection, 'multiple') !== FALSE);
$check('сказано, до какой ширины ужимаются файлы',
    strpos($collection, '1024 px') !== FALSE);
$flat = preg_replace('/\s+/', ' ', $collection);

$check('свой элемент можно отметить к удалению',
    strpos($flat, 'name="ids[]" value="1"') !== FALSE);
$check('чужой элемент отметить нельзя — галочки у него нет',
    strpos($flat, 'name="ids[]" value="2"') === FALSE);
$check('у своего элемента есть ссылка на правку',
    strpos($collection, 'edit=1') !== FALSE);
$check('у чужого элемента правки нет', strpos($collection, 'edit=2') === FALSE);
$check('в списке у своего элемента есть форма правки строки',
    strpos($list_view, 'id="nj654-row-1"') !== FALSE);
$check('в списке у чужого элемента формы правки нет',
    strpos($list_view, 'id="nj654-row-2"') === FALSE);
$check('подпись элемента экранирована',
    strpos($collection, 'Кот &lt;script&gt;') !== FALSE);
$check('ошибка элемента видна человеку',
    strpos($collection, 'ошибка') !== FALSE
    AND strpos($list_view, 'модель недоступна') !== FALSE);

// --- Сотня картинок: плотность, постранично, массовый отбор ----------------
echo "\n== Банк на сотню картинок ==\n\n";

$flat_grid = preg_replace('/\s+/', ' ', $collection);
$flat_list = preg_replace('/\s+/', ' ', $list_view);

$check('плитка плотная: до восьми в ряд',
    strpos($flat_grid, 'grid-cols-3 gap-2') !== FALSE
    AND strpos($flat_grid, 'xl:grid-cols-8') !== FALSE);
$check('миниатюры квадратные', strpos($flat_grid, 'aspect-square') !== FALSE);
$check('есть переключатель «плиткой/списком»',
    strpos($flat_grid, 'view=grid') !== FALSE AND strpos($flat_list, 'Плиткой') !== FALSE);
$check('список — вид по умолчанию: ссылка на него без параметра view',
    strpos($flat_grid, 'title="Списком"') !== FALSE
    AND ! preg_match('~view=list~u', $flat_grid));
$check('есть постраничная навигация',
    strpos($flat_grid, 'p=1') !== FALSE AND strpos($flat_grid, 'p=3') !== FALSE);
$check('текущая страница выделена (n2j-page + aria-current)',
    (bool) preg_match('~class="n2j-page"\s*aria-current="page"[^>]*>2<~u', $flat_grid));
$check('есть «выбрать все» и счётчик отмеченных',
    strpos($flat_grid, 'data-nj654-all') !== FALSE
    AND strpos($flat_grid, 'data-nj654-count') !== FALSE);
$check('правка возвращает на ту же страницу и в тот же вид',
    strpos($flat_list, 'name="p" value="2"') !== FALSE
    AND strpos($flat_grid, 'name="view" value="grid"') !== FALSE);
$check('в списке подпись правится прямо в строке',
    strpos($flat_list, 'form="nj654-row-1" name="title"') !== FALSE);
$check('открытая правка показывает панель с кнопкой «Сохранить»',
    strpos($flat_list, 'action="/similar/rename"') !== FALSE
    AND strpos($flat_list, 'Отмена') !== FALSE);

$admin = (string) View::factory('654/admin', [
    'stats' => $stats, 'items' => $items, 'failed' => [$items[2]],
    'queries' => [
        ['id' => 5, 'visitor' => 'v1', 'client_ip' => '127.0.0.1', 'kind' => 'both',
         'text' => 'рыжий кот <b>', 'file' => '', 'top' => 1, 'status' => 'ready', 'error' => '',
         'hits' => [['id' => 1, 'title' => 'Кот', 'score' => 0.81]], 'elapsed_ms' => 120,
         'created_at' => '2026-10-05 10:03:00'],
        ['id' => 6, 'visitor' => 'v2', 'client_ip' => '', 'kind' => 'image', 'text' => '',
         'file' => '202610/b.jpg', 'top' => 1, 'status' => 'error', 'error' => 'нет связи',
         'hits' => [], 'elapsed_ms' => 0, 'created_at' => '2026-10-05 10:04:00'],
    ],
    'flash' => '', 'csrf' => 'tok',
]);

$check('админ видит, что ищут', strpos($admin, 'Что ищут') !== FALSE);
$check('админ видит, что добавляют', strpos($admin, 'Что добавляют') !== FALSE);
$check('поиск по картинке подписан словами', strpos($admin, 'текст и картинка') !== FALSE);
$check('текст запроса экранирован', strpos($admin, 'рыжий кот &lt;b&gt;') !== FALSE);
$check('неудавшийся поиск показан причиной', strpos($admin, 'нет связи') !== FALSE);
$check('есть уборка несчитанных', strpos($admin, '/similar/admin/drop_failed') !== FALSE);
$check('есть очистка журнала', strpos($admin, '/similar/admin/clear_log') !== FALSE);

// --- Дизайн-эталон /pages/required_design_sample ---------------------------
echo "\n== Дизайн-эталон ==\n\n";

$pages = ['поиска' => $search_page, 'банка' => $collection, 'админа' => $admin];

foreach ($pages as $name => $html)
{
    $flat = preg_replace('/\s+/', ' ', $html);

    // Скругления дают и компоненты v6: .n2j-card — rounded-xl, кнопки и поля — rounded-lg.
    $check('страница '.$name.': карточки rounded-xl (n2j-card), контролы rounded-lg (n2j-btn/n2j-input)',
        (strpos($flat, 'rounded-xl') !== FALSE OR strpos($flat, 'n2j-card') !== FALSE)
        AND (strpos($flat, 'rounded-lg') !== FALSE OR preg_match('~n2j-(btn-|input)~', $flat) === 1));
    // Переходы по разделу стоят чипами в шапке сайта — ни крошек, ни
    // второго ряда ссылок рядом с заголовком быть не должно.
    $check('страница '.$name.': шапка без крошек и дублей навигации',
        strpos($flat, 'Хлебные крошки') === FALSE);
    $check('страница '.$name.': заголовок модуля по эталону',
        strpos($flat, 'class="n2j-h1') !== FALSE);  // эталон v6: .n2j-h1
    $check('страница '.$name.': полос прогресса нет',
        strpos($flat, 'role="progressbar"') === FALSE AND strpos($flat, 'progress-bar') === FALSE);
    $check('страница '.$name.': обёртка страницы по эталону',
        strpos($flat, 'w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8') !== FALSE);
}

foreach (['поиска' => $search_page, 'банка' => $collection] as $name => $html)
{
    $flat = preg_replace('/\s+/', ' ', $html);

    $check('страница '.$name.': сетка 2/3 + 1/3',
        strpos($flat, 'md:grid md:grid-cols-3') !== FALSE
        AND strpos($flat, 'md:col-span-2') !== FALSE);
    $check('страница '.$name.': на телефоне скрыты только вторичные общие советы',
        strpos($flat, 'Быстрые советы') !== FALSE
        AND strpos($flat, 'hidden rounded-xl') !== FALSE
        AND strpos($flat, 'md:block') !== FALSE);
    // 44 px дают и утилита, и полноразмерные компоненты эталона (n2j-input,
    // n2j-btn-* без компактного n2j-btn-sm — высота из токена --n2j-control).
    $check('страница '.$name.': тач-таргеты 44 px',
        substr_count($flat, 'min-h-[44px]') + substr_count($flat, 'n2j-input')
        + preg_match_all('/class="n2j-btn-(cta|process|secondary|danger)(?![^"]*n2j-btn-sm)/', $flat) >= 2);
}

$flat_search = preg_replace('/\s+/', ' ', $waiting_page);

$check('форма поиска сразу отправляется POST-запросом с CSRF-защитой',
    strpos($flat_search, 'method="post" action="/similar/find"') !== FALSE
    AND strpos($flat_search, 'name="csrf" value="tok"') !== FALSE);
$go_html = substr($flat_search, (int) strpos($flat_search, 'id="nj654-go"'), 400);

$check('кнопка «Найти» остаётся лаконичной — её текст не меняется, спиннера на ней нет',
    strpos($flat_search, 'id="nj654-go"') !== FALSE
    AND strpos($go_html, 'Секундомер') === FALSE
    AND strpos($go_html, 'fa-spinner') === FALSE
    AND strpos($go_html, 'data-nj654-elapsed') === FALSE
    AND strpos($flat_search, 'data-wait=') === FALSE);

$check('ход работы живёт в карточке запроса, а не в отдельном блоке ожидания',
    strpos($waiting_page, 'data-nj654-id="6"') !== FALSE
    AND strpos($waiting_page, 'data-nj654-status="queued"') !== FALSE
    AND strpos($waiting_page, 'data-live-url=') === FALSE
    AND strpos($variant_cards[1]['engine'], "'/similar/api/status?q=' + encodeURIComponent(state.id)") !== FALSE);
$check('большого блока ожидания сбоку больше нет',
    strpos($flat_search, 'В работе') === FALSE);
$combo_hint_pos = strpos($flat_search, 'Текст и картинку можно отправить вместе.');
$tips_pos = strpos($flat_search, 'Быстрые советы');
$check('совместный поиск текстом и картинкой объяснён до вторичных советов',
    $combo_hint_pos !== FALSE AND ($tips_pos === FALSE OR $combo_hint_pos < $tips_pos));
$collection_text_pos = strpos($collection, 'Картинка необязательна — можно добавить одно текстовое описание.');
$collection_tips_pos = strpos($collection, 'Быстрые советы');
$check('добавление одного текста объяснено у формы и доступно на телефоне',
    $collection_text_pos !== FALSE AND ($collection_tips_pos === FALSE OR $collection_text_pos < $collection_tips_pos));

$flat_res = preg_replace('/\s+/', ' ', $search_page);
$flat_adm = preg_replace('/\s+/', ' ', $admin);

$check('находки показаны компактной плиткой, а не во весь экран',
    strpos($flat_res, 'sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5') !== FALSE
    AND strpos($flat_res, 'aspect-square') !== FALSE);

// Подтверждение — общий data-n2j-arm (app.js); видимость днём держит общее
// правило [data-n2j-arm].is-armed в сборке, своей перекраски в модуле нет.
$check('подтверждение — общий data-n2j-arm, своей перекраски кнопки нет',
    strpos($flat_grid, 'ARMED') === FALSE AND strpos($flat_adm, 'ARMED') === FALSE);

$flat_coll = preg_replace('/\s+/', ' ', $collection);

$check('удаление в два шага на странице банка', strpos($flat_coll, 'data-n2j-arm="Точно убрать?"') !== FALSE);
$check('уборка в два шага в админке', substr_count($flat_adm, 'data-n2j-arm="') >= 3);
$check('таблицы админки плотностью py-2.5 / text-sm',
    strpos($flat_adm, 'py-2.5') !== FALSE AND strpos($flat_adm, 'text-sm') !== FALSE);
$check('таблицы админки прокручиваются по горизонтали',
    strpos($flat_adm, 'n2j-scrollbar overflow-x-auto') !== FALSE);

$card = (string) View::factory('nj654/card', []);

$check('карточка каталога ведёт на обе страницы',
    strpos($card, 'href="/similar"') !== FALSE AND strpos($card, '/similar/collection') !== FALSE);

// --- Картинка целиком: модальное окно -------------------------------------
echo "\n== Картинка целиком ==\n\n";

$check('на странице поиска есть окно просмотра',
    strpos($flat_res, 'id="nj654-zoom"') !== FALSE);
$check('в банке есть окно просмотра',
    strpos($flat_grid, 'id="nj654-zoom"') !== FALSE
    AND strpos($flat_list, 'id="nj654-zoom"') !== FALSE);
$check('находка открывается окном, а не новой вкладкой',
    (bool) preg_match('~data-nj654-zoom="[^"]*nj654[^"]*"~u', $flat_res)
    AND strpos($flat_res, 'cursor-zoom-in') !== FALSE);
$check('плитка банка открывается окном',
    strpos($flat_grid, 'data-nj654-zoom=') !== FALSE);
$check('в списке банка миниатюра тоже открывается',
    strpos($flat_list, 'data-nj654-zoom=') !== FALSE);
$check('из окна есть ссылка на сам файл',
    strpos($flat_res, 'data-nj654-zoom-link') !== FALSE
    AND strpos($flat_res, 'Файл целиком') !== FALSE);
$check('окно закрывается крестиком и Escape',
    strpos($flat_res, 'data-nj654-zoom-close') !== FALSE
    AND strpos($flat_res, "'Escape'") !== FALSE);
$check('подпись картинки экранирована',
    (bool) preg_match('~data-nj654-zoom-title="[^"<>]*"~u', $flat_res)
    AND strpos($flat_res, 'data-nj654-zoom-title="<') === FALSE);
$check('у карточки поиска нет заголовка-повтора',
    strpos($flat_res, 'Найти похожее') === FALSE);

// Маршрут результата не должен принимать произвольный сегмент вместо id.
class Route {
    public static $routes = [];
    protected $name;

    public function __construct($name) { $this->name = $name; }
    public static function set($name, $uri, array $regex = []) {
        self::$routes[$name] = ['uri' => $uri, 'regex' => $regex, 'defaults' => []];
        return new self($name);
    }
    public function defaults(array $defaults) {
        self::$routes[$this->name]['defaults'] = $defaults;
        return $this;
    }
}
require $MOD.'/init.php';
$query_route = Route::$routes['nj654_result'] ?? [];
$id_regex = (string) ($query_route['regex']['id'] ?? '');
$check('маршрут /similar/result принимает цифровой id',
    ($query_route['uri'] ?? '') === 'similar/result/<id>'
    AND $id_regex !== '' AND preg_match('~^'.$id_regex.'$~', '42') === 1
    AND preg_match('~^'.$id_regex.'$~', '4x') === 0);

class Service_Nj120_JobQueue {
    public static $return_id = 777;
    public static $throw = FALSE;
    public static function push($channel, array $payload, array $opts = []) {
        if (self::$throw) throw new RuntimeException('test queue failure');
        return (int) self::$return_id;
    }
}
$push = new ReflectionMethod('Service_Nj654_Bank', 'push');
$push->setAccessible(TRUE);
Service_Nj120_JobQueue::$return_id = 0;
Database::$sql = [];
$push_result = $push->invoke(NULL, ['kind' => 'query', 'id' => 77]);
$push_sql = implode("\n", Database::$sql);
$check('нулевой ID очереди закрывает поиск ошибкой, а не оставляет вечную очередь',
    $push_result === 0 AND strpos($push_sql, "UPDATE nj_654_queries SET status = 'error'") !== FALSE
    AND strpos($push_sql, 'WHERE id IN (77)') !== FALSE);

// ---------------------------------------------------------------------------
echo "\n== Итог ==\n\n";
echo $fail === 0 ? "  Все проверки пройдены\n" : "  Провалов: {$fail}\n";

exit($fail === 0 ? 0 : 1);
