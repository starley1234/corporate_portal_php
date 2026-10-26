<?php
/**
 * nj671_embedbench: логика показа отчёта и обе вьюхи — без PHP-ядра и БД.
 *
 * Зачем стенд. Метрики считает воркер, а страница только объясняет их словами.
 * Ошибка здесь не видна линтеру: опечатка в имени поля jsonb, вызов
 * несуществующего ключа, «0%» у выключенного пути — всё это всплывает уже на
 * живом сервере, когда владелец принимает решение по цифрам. Стенд прогоняет
 * настоящий `Service_Nj671_Bench` на отчёте воркера и рендерит вьюхи, поэтому
 * ловит и логику, и разметку.
 *
 * Запуск через PHP-in-WASM:
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj671_embedbench_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');
if ( ! defined('DOCROOT')) define('DOCROOT', dirname(__DIR__).'/public_html/');

$ROOT = dirname(__DIR__);
$MOD  = $ROOT.'/application/modules/nj671_embedbench';

$fail = 0;

set_error_handler(function ($no, $str, $file, $line) use (&$fail) {
    echo '  ПРОВАЛ  PHP: '.$str.' ('.basename((string) $file).':'.$line.")\n";
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

class Gate {
    public static $logged = TRUE;

    public static function logged_in() { return (bool) self::$logged; }

    public static function worker_action($action) { return FALSE; }

    public static function wall($return_url = '', array $options = []) {
        return View::factory('partials/auth_wall', $options + ['return_url' => (string) $return_url]);
    }
}

class Log { const ERROR = 3; }
class Log_Plain { public static function event($level, $message, array $values = []) {} }

/** Конфиг читаем настоящий: подписи наборов и пороги должны быть те, что в репо. */
class StandConfig {
    protected $files = [];

    public function load($key)
    {
        $parts = explode('.', (string) $key);
        $file  = array_shift($parts);

        if ( ! isset($this->files[$file]))
        {
            $path = dirname(__DIR__).'/application/modules/nj671_embedbench/config/'.$file.'.php';
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

class View {
    private $name;
    private $data;

    public function __construct($name, array $data) { $this->name = $name; $this->data = $data; }

    public static function factory($name, array $data = []) { return new self($name, $data); }

    public function __toString()
    {
        $path = strpos($this->name, 'partials/') === 0
            ? dirname(__DIR__).'/application/views/'.$this->name.'.php'
            : dirname(__DIR__).'/application/modules/nj671_embedbench/views/'.$this->name.'.php';

        extract($this->data);
        ob_start();

        require $path;

        return (string) ob_get_clean();
    }
}

require $ROOT.'/application/classes/Vision.php';
require $MOD.'/classes/Service/Nj671/Bench.php';

$check = function ($name, $ok, $why = '') use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name
        .(( ! $ok AND $why !== '') ? ': '.$why : '')."\n";

    if ( ! $ok) $fail++;
};

// ---------------------------------------------------------------------------
// Отчёт воркера: обе стороны работают, размерности совпали
// ---------------------------------------------------------------------------

$report = [
    'status' => 'done',
    'title' => 'Стенд эмбеддингов · Каталог бытовых услуг',
    'corpus' => ['key' => 'catalog', 'title' => 'Каталог бытовых услуг',
                 'docs' => 14, 'queries' => 10, 'quick' => FALSE],
    'engines' => [
        'text' => [
            'key' => 'text', 'base_url' => 'http://127.0.0.1:1235/v1',
            'model' => 'text-embedding-qwen3-embedding-0.6b',
            'ok' => TRUE, 'error' => '', 'dims' => 1024,
            'notes' => ['пачка до 32 текстов одним запросом (EMBEDDING_BATCH_SIZE)'],
            'quality' => ['recall1' => 0.7, 'recall3' => 0.9, 'mrr' => 0.79,
                          'related' => 0.71, 'unrelated' => 0.33, 'margin' => 0.38],
            'repeat' => 0.9999,
            'latency' => ['total_ms' => 1200, 'per_item_ms' => 48.0],
            'images' => ['supported' => FALSE, 'checked' => FALSE, 'ok' => NULL],
            'rows' => [
                ['query' => 'течёт кран на кухне, нужно заменить смеситель',
                 'top' => 0, 'top_cos' => 0.91, 'ok' => TRUE, 'rank' => 1],
                ['query' => 'поклеить обои в спальне, стены кривые',
                 'top' => 3, 'top_cos' => 0.55, 'ok' => FALSE, 'rank' => 0],
            ],
        ],
        'mm' => [
            'key' => 'mm', 'base_url' => 'https://embed.example/v1',
            'model' => 'Qwen3-VL-Embedding-2B',
            'ok' => TRUE, 'error' => '', 'dims' => 1024,
            'notes' => ['диалект: omni_messages'],
            'quality' => ['recall1' => 0.9, 'recall3' => 1.0, 'mrr' => 0.93,
                          'related' => 0.66, 'unrelated' => 0.21, 'margin' => 0.45],
            'repeat' => 0.997,
            'latency' => ['total_ms' => 24000, 'per_item_ms' => 940.0],
            'images' => ['supported' => TRUE, 'checked' => TRUE, 'ok' => TRUE, 'margin' => 0.31],
            'rows' => [
                ['query' => 'течёт кран на кухне, нужно заменить смеситель',
                 'top' => 0, 'top_cos' => 0.93, 'ok' => TRUE, 'rank' => 1],
                ['query' => 'поклеить обои в спальне, стены кривые',
                 'top' => 5, 'top_cos' => 0.62, 'ok' => TRUE, 'rank' => 2],
            ],
        ],
    ],
    'agreement' => 0.62,
    'decision' => [
        'winner' => 'both',
        'summary' => 'Оба пути нужны и делят работу: текстовый — для поиска по тексту, мультимодальный — там, где есть картинки.',
        'reasons' => [
            ['code' => 'mm_better', 'value' => 0.2],
            ['code' => 'text_faster', 'value' => 19.6],
            ['code' => 'images_ok', 'value' => 0.31],
        ],
        'tasks' => [
            ['task' => 'text_index', 'pick' => 'mm', 'delta' => 0.2, 'speed' => 19.6],
            ['task' => 'cross_modal', 'pick' => 'mm'],
            ['task' => 'one_space', 'pick' => 'text'],
        ],
    ],
    'elapsed_ms' => 26000,
];

$data = Service_Nj671_Bench::shape($report);

// ---------------------------------------------------------------------------
echo "== Приведение отчёта ==\n\n";

$check('оба пути рабочие', $data['ok'] === TRUE);
$check('таблица метрик собрана целиком', count($data['metrics']) === 7);
$check('recall@1 мультимодального — 0.9', $data['sides']['mm']['recall1'] === 0.9);
$check('лучшая сторона по recall@1 — мультимодальная',
    $data['metrics'][0]['best'] === 'mm');
$check('по времени лучшая — текстовая',
    $data['metrics'][5]['key'] === 'per_item_ms' AND $data['metrics'][5]['best'] === 'text');
$check('у выключенного пути в таблице «—»',
    Service_Nj671_Bench::display('pct', NULL, FALSE) === '—');
$check('сравнение строк идёт по тем же запросам', count($data['rows']) === 2);
$check('решение переведено', $data['decision']['label'] === 'Держать оба пути');
$check('задач три', count($data['decision']['tasks']) === 3);
$check('причина «быстрее» названа числом',
    strpos($data['decision']['reasons'][1]['title'], '19.6') !== FALSE);
$check('совпадение размерностей не выдаётся за одно пространство',
    strpos($data['notes'][0]['text'], 'НЕ значит') !== FALSE);

echo "\n== Неполные отчёты ==\n\n";

$off = $report;
$off['engines']['mm']['ok'] = FALSE;
$off['engines']['mm']['error'] = 'EMBEDDING_MM_API_URL не задан в workers/.env — этот путь не настроен';
$off['agreement'] = NULL;
$off['decision'] = ['winner' => 'text', 'summary' => 'Отвечает только один путь.',
                    'reasons' => [['code' => 'only_one', 'side' => 'text',
                                   'error' => 'EMBEDDING_MM_API_URL не задан']],
                    'tasks' => [['task' => 'any', 'pick' => 'text']]];

$off_data = Service_Nj671_Bench::shape($off);

$check('один путь — всё ещё результат', $off_data['ok'] === TRUE);
$check('причина отказа видна', strpos($off_data['sides']['mm']['error'], 'EMBEDDING_MM_API_URL') !== FALSE);
$check('у выключенного пути нет процентов',
    $off_data['metrics'][0]['mm'] === '—');

$wrong_dims = $report;
$wrong_dims['engines']['mm']['dims'] = 2048;
$wrong_dims['agreement'] = NULL;
$wrong_dims_data = Service_Nj671_Bench::shape($wrong_dims);

$check('разные размерности — красное замечание',
    $wrong_dims_data['notes'][0]['tone'] === 'rose');
$check('и отказ смешивать пространства',
    strpos($wrong_dims_data['notes'][0]['text'], 'нельзя') !== FALSE);

$empty = Service_Nj671_Bench::shape([]);

$check('пустой отчёт не роняет страницу', $empty['ok'] === FALSE);
$check('и не обещает сравнения', $empty['decision']['label'] === 'Сравнение не удалось');

// Живой случай 22.10.2026: владелец увидел «Ни один путь не ответил» на
// настроенном сервере. Причина — дожим перезаписал доставленный отчёт короткой
// сводкой из очереди. Состояния обязаны различаться.
$summary_raw = ['summary_only' => TRUE, 'run_id' => 7, 'corpus' => 'catalog',
                'winner' => 'both', 'text_ok' => TRUE, 'mm_ok' => TRUE,
                'agreement' => 0.62];
$summary = Service_Nj671_Bench::shape($summary_raw);

$check('сводка из очереди узнаётся', $summary['state'] === 'summary');
$check('и не выдаёт себя за отчёт с метриками', $summary['ok'] === FALSE);
$check('оба пути в сводке отмечены рабочими',
    $summary['summary']['text_ok'] === TRUE AND $summary['summary']['mm_ok'] === TRUE);
$check('ключ набора из сводки не теряется', $summary['corpus']['key'] === 'catalog');
$check('название набора берётся из конфига', $summary['corpus']['title'] !== '');
$check('полный отчёт — своё состояние', $data['state'] === 'full');
$check('пустой result — третье состояние', $empty['state'] === 'empty');

echo "\n== Короткая строка для истории ==\n\n";

$brief = Service_Nj671_Bench::brief(['result' => $report]);

echo '  '.$brief."\n";
$check('в строке есть оба пути и вывод',
    strpos($brief, 'текст 70%') !== FALSE
    AND strpos($brief, 'мультимодальный 90%') !== FALSE
    AND strpos($brief, 'оба пути') !== FALSE);

// ---------------------------------------------------------------------------
echo "\n== Дожим прогона (Vision::catch_up_plan) ==\n\n";

// Именно здесь родилась жалоба владельца: страница при каждом просмотре
// заменяла доставленный отчёт сводкой из задачи.
$plan = function ($run_status, $stored, $job_status, $job_result, $attempts = 1, $max = 2) {
    return Vision::catch_up_plan(
        ['id' => 1, 'status' => $run_status, 'result' => $stored],
        ['status' => $job_status, 'result' => json_encode($job_result, JSON_UNESCAPED_UNICODE),
         'attempts' => $attempts, 'max_attempts' => $max]
    );
};

$full_report = $report;
$sum_only    = ['summary_only' => TRUE, 'winner' => 'both', 'text_ok' => TRUE, 'mm_ok' => TRUE];

$check('доставленный отчёт не перезаписывается сводкой из задачи',
    $plan('done', $full_report, 'done', $sum_only)['action'] === 'none');

$check('но испорченная запись (сводка вместо отчёта) чинится',
    $plan('done', $sum_only, 'done', $full_report)['action'] === 'heal');

$check('прогон, до которого доставка не дошла, догоняется',
    $plan('running', [], 'done', $full_report)['action'] === 'apply');

$check('закрытый отказом прогон не трогаем',
    $plan('error', [], 'done', $full_report)['action'] === 'none');

$check('провал задачи показывается причиной',
    $plan('queued', [], 'failed', [], 2, 2)['action'] === 'fail');

$check('незавершённую задачу не дёргаем',
    $plan('queued', [], 'running', [], 1, 2)['action'] === 'none');

// ---------------------------------------------------------------------------
echo "\n== Вьюхи ==\n\n";

$corpora = Service_Nj671_Bench::corpora();
$limits  = (array) Kohana::$config->load('nj671_embedbench.limits');

$index = (string) View::factory('671/index', [
    'corpora' => $corpora,
    'limits'  => $limits,
    'history' => [
        ['id' => 7, 'status' => 'done', 'title' => 'Каталог бытовых услуг',
         'result' => $report, 'error' => '', 'elapsed_ms' => 26000,
         'created_at' => '2026-10-22 10:00:00'],
        ['id' => 6, 'status' => 'error', 'title' => 'Каталог бытовых услуг (быстрый)',
         'result' => NULL, 'error' => 'EMBEDDING_MM_API_URL не задан', 'elapsed_ms' => 0,
         'created_at' => '2026-10-21 18:00:00'],
    ],
    'flash'   => 'Лимит прогонов: не больше 6 в час с одного адреса.',
    'csrf'    => 'stand-token',
    'site'    => ['api_url' => 'http://127.0.0.1:1235/v1',
                  'model' => 'text-embedding-qwen3-embedding-0.6b',
                  'dimensions' => 1024, 'enabled' => TRUE, 'mm_url' => 'https://embed.example/v1'],
]);

$checks = [
    'заголовок страницы'        => 'Стенд эмбеддингов',
    'оба адреса названы'        => 'EMBEDDING_MM_API_URL',
    'форма ведёт на запуск'     => 'action="/embedbench/run"',
    'csrf в форме'             => 'name="csrf"',
    'быстрый режим есть'        => 'name="quick"',
    'история показана'          => '/embedbench/result/7',
    'ошибка запуска видна'      => 'Лимит прогонов',
    'подсказка про консоль'     => 'nj671_bench.py',
];

foreach ($checks as $name => $needle)
{
    $check('index: '.$name, strpos($index, $needle) !== FALSE);
}

$result = (string) View::factory('671/result', [
    'run' => ['id' => 7, 'status' => 'done', 'title' => 'Каталог бытовых услуг',
              'error' => '', 'elapsed_ms' => 26000, 'created_at' => '2026-10-22 10:00:00',
              'result' => $report],
    'data' => $data,
    'live' => ['status' => 'done', 'stage' => '', 'stages' => [],
               'elapsed_sec' => 26, 'silent_sec' => NULL, 'attempts' => 1, 'note' => ''],
    'hint' => '',
]);

$checks = [
    'вывод наверху'            => 'Держать оба пути',
    'задачи расписаны'         => 'Что брать для какой задачи',
    'метрика recall@1'         => 'Recall@1',
    'строки запросов'          => 'Где пути разошлись',
    'паспорт пути'             => 'Паспорт путей',
    'сырой jsonb'              => 'Сырой ответ воркера',
    'ссылка на лабораторию'    => '/veclab',
    'никаких полос прогресса'  => 'Считаю два пути',
];

foreach ($checks as $name => $needle)
{
    $present = strpos($result, $needle) !== FALSE;
    $check('result: '.$name, $name === 'никаких полос прогресса' ? ! $present : $present);
}

$running = (string) View::factory('671/result', [
    'run' => ['id' => 8, 'status' => 'queued', 'title' => '', 'error' => '',
              'elapsed_ms' => 0, 'created_at' => '2026-10-22 10:05:00', 'result' => NULL],
    'data' => Service_Nj671_Bench::shape([]),
    'live' => ['status' => 'queued', 'stage' => 'Текстовый путь: документы',
               'stages' => [], 'elapsed_sec' => 3, 'silent_sec' => NULL,
               'attempts' => 0, 'note' => ''],
    'hint' => 'Задача в очереди уже больше 2 минут, но её никто не взял',
]);

$check('result: живой ряд на месте', strpos($running, '/embedbench/api/status?id=8') !== FALSE);
$check('result: подсказка о зависшей задаче видна',
    strpos($running, 'никто не взял') !== FALSE);

// Сводка вместо отчёта: страница обязана сказать «дошла только сводка», а не
// «ни один путь не ответил» — иначе владелец ищет поломку там, где всё цело.
$summary_page = (string) View::factory('671/result', [
    'run' => ['id' => 10, 'status' => 'done', 'title' => 'Стенд эмбеддингов',
              'error' => '', 'elapsed_ms' => 26000, 'created_at' => '2026-10-22 12:00:00',
              'result' => $summary_raw],
    'data' => $summary,
    'live' => ['status' => 'done', 'stage' => '', 'stages' => [], 'elapsed_sec' => 26,
               'silent_sec' => NULL, 'attempts' => 1, 'note' => ''],
    'hint' => '',
]);

$check('сводка: страница не обвиняет серверы',
    strpos($summary_page, 'Ни один путь не ответил') === FALSE);
$check('сводка: названо, что дошло',
    strpos($summary_page, 'дошла только сводка') !== FALSE);
$check('сводка: вывод всё равно назван',
    strpos($summary_page, 'Держать оба пути') !== FALSE);
$check('сводка: отправляет в CLI за цифрами',
    strpos($summary_page, 'nj671_bench.py --quick') !== FALSE);

$error = (string) View::factory('671/result', [
    'run' => ['id' => 9, 'status' => 'error', 'title' => '', 'created_at' => '2026-10-22 11:00:00',
              'elapsed_ms' => 0, 'error' => 'RuntimeError: HTTP 401: сервис эмбеддингов требует токен',
              'result' => NULL],
    'data' => Service_Nj671_Bench::shape([]),
    'live' => ['status' => 'failed', 'stage' => '', 'stages' => [], 'elapsed_sec' => 1,
               'silent_sec' => NULL, 'attempts' => 1, 'note' => ''],
    'hint' => '',
]);

$check('result: ошибка объяснена', strpos($error, 'HTTP 401') !== FALSE);

// Отказ одного пути: рядом с текстом ошибки обязаны быть замечания пути —
// список моделей сервера и факт отправки токена. Без них «HTTP 400» снова
// превращается в загадку, а владелец идёт проверять ключ.
$one_side = $report;
$one_side['engines']['text']['ok'] = FALSE;
$one_side['engines']['text']['rows'] = [];
$one_side['engines']['text']['error'] =
    "документы: EmbeddingServerError: Сервер эмбеддингов ответил HTTP 400 на "
    ."https://llm.toolforge.ru/v1/embeddings (модель 'qwen3-vl-embedding-2b', входов 14, "
    ."символов 2100). Ответ сервера: {\"message\":\"model not found\"}";
$one_side['engines']['text']['notes'] = [
    'токен отправляется: EMBEDDING_API_KEY',
    'сервер отдаёт модели: qwen3-vl-embedding-2b',
];
// Вердикт воркера для этого случая: работает только мультимодальный путь.
$one_side['decision'] = [
    'winner'  => 'mm',
    'summary' => 'Отвечает только один путь — его и берите, второй сейчас нерабочий.',
    'reasons' => [['code' => 'only_one', 'side' => 'mm',
                   'error' => $one_side['engines']['text']['error']]],
    'tasks'   => [['task' => 'any', 'pick' => 'mm']],
];
$one_side_data = Service_Nj671_Bench::shape($one_side);

$one_side_page = (string) View::factory('671/result', [
    'run' => ['id' => 11, 'status' => 'done', 'title' => 'Стенд', 'error' => '',
              'elapsed_ms' => 9000, 'created_at' => '2026-10-22 13:00:00',
              'result' => $one_side],
    'data' => $one_side_data,
    'live' => ['status' => 'done', 'stage' => '', 'stages' => [], 'elapsed_sec' => 9,
               'silent_sec' => NULL, 'attempts' => 1, 'note' => ''],
    'hint' => '',
]);

$check('один путь: отказ назван словами сервера',
    strpos($one_side_page, 'model not found') !== FALSE);
$check('один путь: видно, что токен отправлялся',
    strpos($one_side_page, 'токен отправляется: EMBEDDING_API_KEY') !== FALSE);
$check('один путь: видно список моделей сервера',
    strpos($one_side_page, 'сервер отдаёт модели') !== FALSE);
$check('один путь: вердикт честный, а не «сравнение не удалось»',
    $one_side_data['decision']['label'] === 'Переходить на мультимодальный');
$check('result: совет проверить env', strpos($error, 'workers/.env') !== FALSE);

// Контроллер показывает гостю общую плашку входа (Gate::wall) вместо формы —
// проверяем, что она рендерится и ведёт назад на /embedbench, а не в пустоту.
$wall = (string) Gate::wall('embedbench', [
    'icon'   => 'fas fa-scale-balanced',
    'title'  => 'Стенд эмбеддингов — инструмент владельца',
    'text'   => 'Прогон занимает очередь и время эмбеддинг-сервера.',
    'points' => ['Что сравнивается', 'Что получается', 'Зачем'],
]);

$check('плашка входа рендерится', strpos($wall, 'data-auth-wall') !== FALSE);
$check('и возвращает на /embedbench', strpos($wall, 'return_url=embedbench') !== FALSE);

// Пустой конфиг наборов: форма не должна обещать запуск, которого не будет.
$no_corpora = (string) View::factory('671/index', [
    'corpora' => [], 'limits' => ['per_hour' => 6], 'history' => [], 'flash' => '',
    'csrf' => '', 'site' => [],
]);

$check('без наборов формы нет, а причина названа',
    strpos($no_corpora, 'action="/embedbench/run"') === FALSE
    AND strpos($no_corpora, 'nj671_embedbench.php') !== FALSE);

// ---------------------------------------------------------------------------
echo "\n".($fail === 0
    ? 'ИТОГ: стенд пройден, провалов нет.'
    : 'ИТОГ: ПРОВАЛОВ '.$fail.' — см. выше.')."\n";

exit($fail === 0 ? 0 : 1);
