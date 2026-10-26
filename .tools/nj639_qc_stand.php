<?php
/**
 * nj639_qc: чистая логика контроля качества и страница /qc без БД.
 *
 * Демо-снимки (public_html/assets/demo/nj639/*.jpg) стенд не проверяет:
 * в виртуальную ФС PHP-in-WASM попадают только исходники, бинарники туда
 * не копируются. Их наличие проверяется обычным ls перед коммитом.
 *
 * Зачем стенд. У модуля нет ни одного теста, который ВЫПОЛНЯЕТ его код:
 * линт видит синтаксис, но не видит, что схема не разбирается, а вьюха
 * падает на несуществующей переменной. Здесь выполняются чистые функции
 * сервиса (разбор JSON-схемы, валидация вердикта, разбор data URL, резюме
 * для журнала) и рендерятся страница /qc со всеми новыми пресетами и её JS.
 *
 * Запуск через PHP-in-WASM (в песочнице PHP нет):
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj639_qc_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
$MOD  = $ROOT.'/application/modules/nj639_qc';

$fail = 0;

// Предупреждение PHP — тоже провал: в бою это «Undefined variable» посреди
// страницы контроля качества.
set_error_handler(function ($no, $str, $file, $line) use (&$fail) {
    echo '  ПРОВАЛ  PHP: '.$str.' ('.$file.':'.$line.")\n";
    $fail++;

    return TRUE;
});

class Kohana {
    public static $config;
    public static $log;
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


class StandConfig {
    protected $files = [];

    public function load($key)
    {
        $parts = explode('.', (string) $key);
        $file  = array_shift($parts);

        if ( ! isset($this->files[$file]))
        {
            $path = dirname(__DIR__).'/application/modules/nj639_qc/config/'.$file.'.php';
            $this->files[$file] = is_file($path) ? (array) include $path : [];
        }

        $value = $this->files[$file];

        foreach ($parts as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value)) return NULL;

            $value = $value[$part];
        }

        return new StandConfigValue($value);
    }
}

/** Kohana отдаёт объект с get(); конфиг модуля читается именно так. */
class StandConfigValue {
    protected $value;

    public function __construct($value) { $this->value = $value; }

    public function get($key, $default = NULL)
    {
        $value = $this->value;

        foreach (explode('.', (string) $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value)) return $default;

            $value = $value[$part];
        }

        return $value;
    }
}

Kohana::$config = new StandConfig;

class View {
    private $name;
    private $data;

    public function __construct($name, array $data) { $this->name = $name; $this->data = $data; }

    public static function factory($name, array $data = []) { return new self($name, $data); }

    public function __toString()
    {
        $root = dirname(__DIR__);

        if ($this->name === 'partials/other_services') return '';

        $path = strpos($this->name, 'partials/') === 0
            ? $root.'/application/views/'.$this->name.'.php'
            : $root.'/application/modules/nj639_qc/views/'.$this->name.'.php';

        extract($this->data);
        ob_start();

        require $path;

        return ob_get_clean();
    }
}

require $MOD.'/classes/Service/Nj639/Qc.php';

$check = function ($name, $ok) use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name."\n";

    if ( ! $ok) $fail++;
};

// ---------------------------------------------------------------------------
echo "== Выходная JSON-схема ==\n\n";

$empty = Service_Nj639_Qc::parse_schema('');
$check('пустая схема разрешена (схема не обязательна)', $empty['ok'] AND $empty['schema'] === NULL);

$basic = Service_Nj639_Qc::parse_schema(
    '{"type":"object","required":["verdict","defects"],"properties":{"verdict":{"type":"string"},"defects":{"type":"array"},"confidence":{"type":"number"}}}');
$check('схема пресета «Царапины и сколы» разбирается', $basic['ok'] AND $basic['schema']['type'] === 'object');

$count = Service_Nj639_Qc::parse_schema(
    '{"type":"object","required":["verdict","count"],"properties":{"verdict":{"type":"string"},"count":{"type":"integer"},"confidence":{"type":"number"}}}');
$check('схема пресета «Пересчёт» разбирается', $count['ok']);

$label = Service_Nj639_Qc::parse_schema(
    '{"type":"object","required":["verdict","label_present"],"properties":{"verdict":{"type":"string"},"label_present":{"type":"boolean"},"skew_deg":{"type":"number"},"defects":{"type":"array"}}}');
$check('схема пресета «Этикетка» разбирается', $label['ok']);

$broken = Service_Nj639_Qc::parse_schema('{это не json');
$check('кривой JSON отбивается с причиной', ! $broken['ok'] AND $broken['error'] !== '');

// ---------------------------------------------------------------------------
echo "\n== Вердикт против схемы ==\n\n";

[$ok, $v, $errs] = Service_Nj639_Qc::validate_verdict(
    ['verdict' => 'брак: трещина', 'defects' => ['трещина у правого верхнего угла'], 'confidence' => 0.82],
    $basic['schema']);
$check('полный ответ модели проходит схему', $ok AND $errs === []);

[$ok2, $v2, $errs2] = Service_Nj639_Qc::validate_verdict(['verdict' => 'годен'], $basic['schema']);
$check('без обязательного поля — провал с объяснением', ! $ok2 AND count($errs2) === 1);

[$ok3, , $errs3] = Service_Nj639_Qc::validate_verdict(
    ['verdict' => 'годен', 'defects' => 'нет'], $basic['schema']);
$check('строка вместо массива ловится', ! $ok3 AND strpos($errs3[0], 'defects') !== FALSE);

[$ok4] = Service_Nj639_Qc::validate_verdict(['что угодно' => 1], NULL);
$check('без схемы принимается любой объект', $ok4);

// ---------------------------------------------------------------------------
echo "\n== Кадр из браузера ==\n\n";

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

$dec = Service_Nj639_Qc::decode_data_url('data:image/png;base64,'.base64_encode($png));
$check('data URL кадра разбирается', $dec['ok'] AND $dec['media_type'] === 'image/png');
$check('тип кадра определяется по байтам', Service_Nj639_Qc::sniff_media_type($png) === 'image/png');

$bad = Service_Nj639_Qc::decode_data_url('data:image/png;base64,не-картинка');
$check('мусор вместо картинки отбивается', ! $bad['ok']);

$notimg = Service_Nj639_Qc::decode_data_url('data:text/plain;base64,'.base64_encode('просто текст'));
$check('текстовый файл не принимается за кадр', ! $notimg['ok']);

$refs = Service_Nj639_Qc::refs_payload([
    'data:image/png;base64,'.base64_encode($png),
    'мусор',
    'data:text/plain;base64,'.base64_encode('нет'),
]);
$check('в эталоны попадают только картинки', count($refs) === 1 AND $refs[0]['media_type'] === 'image/png');

// ---------------------------------------------------------------------------
echo "\n== Резюме вердикта для журнала ==\n\n";

echo '  '.Service_Nj639_Qc::verdict_summary(['verdict' => 'брак: скол', 'defects' => ['скол угла']])."\n";

$check('резюме непустое', Service_Nj639_Qc::verdict_summary(['verdict' => 'брак: скол']) !== '');
$check('пустой вердикт не роняет журнал', Service_Nj639_Qc::verdict_summary(NULL) === ''
    OR is_string(Service_Nj639_Qc::verdict_summary(NULL)));

// ---------------------------------------------------------------------------
echo "\n== Страница /qc ==\n\n";

$page = (string) View::factory('639/index', [
    'logged'  => TRUE,
    'rules'   => [['id' => 4, 'name' => 'Царапины и сколы корпуса']],
    'sources' => [['id' => 2, 'code' => 'qcdeadbeef', 'name' => 'Демо-кадр с этого сайта',
                   'kind' => 'http', 'trigger' => 'timer', 'enabled' => TRUE]],
    'recent'  => [['id' => 11, 'mode' => 'manual', 'trigger' => 'manual', 'status' => 'done',
                   'error' => '', 'verdict' => ['verdict' => 'годен'], 'summary' => 'годен',
                   'created_at' => '2026-10-05 12:00:00', 'finished_at' => '2026-10-05 12:00:14']],
    'kinds'      => Service_Nj639_Qc::source_kinds(),
    'triggers'   => Service_Nj639_Qc::source_triggers(),
    'rate_text'  => '5 операций в минуту',
    'max_img_mb' => '15',
    'csrf'       => 'тест',
]);

echo '  длина страницы: '.strlen($page)." байт\n";

// Гость: вместо рабочей формы — плашка входа (замок 08.10.2026, partials/auth_wall).
Gate::$logged = FALSE;
$guest_page = (string) View::factory('639/index', [
    'logged'  => FALSE,
    'stats'   => [],
    'rules'   => [],
    'sources' => [],
    'recent'  => [],
    'kinds'      => Service_Nj639_Qc::source_kinds(),
    'triggers'   => Service_Nj639_Qc::source_triggers(),
    'rate_text'  => '5 операций в минуту',
    'max_img_mb' => '15',
    'csrf'       => 'тест',
]);
Gate::$logged = TRUE;

$check('гость видит плашку входа вместо формы контроля качества',
    strpos($guest_page, 'data-auth-wall="qc"') !== FALSE
    AND strpos($guest_page, 'id="nj639-form"') === FALSE
    AND strpos($guest_page, '/auth/login?return_url=qc') !== FALSE);


$check('страница отрисовалась', strlen($page) > 4000);
$check('блок пресетов на месте', substr_count($page, 'data-nj639-preset=') === 7);
$check('демо-кнопки на месте', strpos($page, 'data-nj639-preset="demo_defect"') !== FALSE
    AND strpos($page, 'data-nj639-preset="demo_ok"') !== FALSE);
$check('демо-источник на месте', strpos($page, 'id="nj639-src-demo"') !== FALSE);
$check('журнал рисуется списком с id (для живого добавления строк)',
    strpos($page, 'id="nj639-history-list"') !== FALSE AND strpos($page, 'id="nj639-history-empty"') !== FALSE);
$check('общий N2JVision подключён (сжатие кадра в браузере)',
    strpos($page, 'window.N2JVision') !== FALSE);
$check('JS страницы подключён', strpos($page, "'/qc/api/run'") !== FALSE);
$check('кнопка «Тест» источника зовёт существующее действие API',
    strpos($page, '/qc/api/source_fire') !== FALSE AND strpos($page, '/qc/api/source-fire') === FALSE);
$check('демо-снимки берутся с этого же сайта',
    strpos($page, "'/assets/demo/nj639/'") !== FALSE
    AND strpos($page, "'part-defect.jpg'") !== FALSE
    AND strpos($page, "'ref-ok.jpg'") !== FALSE);
$check('страницу не перезагружает поверх вердикта', strpos($page, 'location.reload(); // обновить историю') === FALSE);

echo "\n".($fail ? "ПРОВАЛОВ: $fail\n" : "Все проверки пройдены\n");

exit($fail ? 1 : 0);
