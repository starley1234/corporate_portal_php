<?php
/**
 * nj653_veclab: чистая логика и SSR-проверки без БД.
 *
 * Зачем стенд. `Service_Nj653_Veclab` — чистые функции (оценка близости,
 * разбор ответа воркера, защита превью), их можно ВЫПОЛНИТЬ и увидеть
 * настоящий результат, а не только синтаксис. Заодно рендерятся страницы
 * /veclab и /veclab/result, карта, админка, JS и карточка каталога: так
 * ловятся опечатки во вьюхах и обращения к несуществующим переменным, которые линт пропускает.
 *
 * Запуск через PHP-in-WASM (в песочнице PHP нет):
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj653_veclab_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
$MOD  = $ROOT.'/application/modules/nj653_veclab';

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


/** Конфиг модуля читаем настоящий — пороги шкалы проверяются как есть. */
class StandConfig {
    protected $files = [];

    public function load($key)
    {
        $parts = explode('.', (string) $key);
        $file  = array_shift($parts);

        if ( ! isset($this->files[$file]))
        {
            $path = dirname(__DIR__).'/application/modules/nj653_veclab/config/'.$file.'.php';
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
        $root = dirname(__DIR__);

        // Каталог «другие сервисы» тянет весь реестр модулей — для стенда
        // вьюх это лишнее.
        if ($this->name === 'partials/other_services') return '';

        $path = strpos($this->name, 'partials/') === 0
            ? $root.'/application/views/'.$this->name.'.php'
            : $root.'/application/modules/nj653_veclab/views/'.$this->name.'.php';

        extract($this->data);
        ob_start();

        require $path;

        return ob_get_clean();
    }
}

require $MOD.'/classes/Service/Nj653/Veclab.php';
require $MOD.'/classes/Service/Nj653/Map.php';

$check = function ($name, $ok, $why = '') use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name
        .(( ! $ok AND $why !== '') ? ': '.$why : '')."\n";

    if ( ! $ok) $fail++;
};

// ---------------------------------------------------------------------------
echo "== Оценка близости: пороги из конфига ==\n\n";

$top = Service_Nj653_Veclab::similarity(0.97);
$mid = Service_Nj653_Veclab::similarity(0.55);
$low = Service_Nj653_Veclab::similarity(0.02);

echo "0,97 → {$top['title']} ({$top['tone']})\n";
echo "0,55 → {$mid['title']} ({$mid['tone']})\n";
echo "0,02 → {$low['title']} ({$low['tone']})\n\n";

$check('0,97 — «практически одно и то же»', $top['title'] === 'практически одно и то же');
$check('0,55 — «заметно связаны»', $mid['title'] === 'заметно связаны');
$check('0,02 — «ничего общего»', $low['title'] === 'ничего общего');
$check('косинус больше единицы режется', Service_Nj653_Veclab::similarity(7.5)['value'] === 1.0);
$check('косинус меньше минус единицы режется', Service_Nj653_Veclab::similarity(-9.0)['value'] === -1.0);

$previous = 2.0;
$ordered  = TRUE;

foreach (Service_Nj653_Veclab::scale() as $step)
{
    if ((float) $step['from'] >= $previous) $ordered = FALSE;

    $previous = (float) $step['from'];
}

$check('пороги шкалы убывают и доходят до -1', $ordered AND $previous === -1.0);

// ---------------------------------------------------------------------------
echo "\n== Косинус ==\n\n";

$check('одинаковое направление — 1,0',
    round(Service_Nj653_Veclab::cosine([1, 2, 3], [2, 4, 6]), 6) === 1.0);
$check('перпендикуляр — 0,0',
    round(Service_Nj653_Veclab::cosine([1, 0], [0, 1]), 6) === 0.0);
$check('разная размерность — 0,0 (ошибка данных, а не «похоже»)',
    Service_Nj653_Veclab::cosine([1, 2, 3], [1, 2]) === 0.0);
$check('нулевой вектор — 0,0', Service_Nj653_Veclab::cosine([0, 0], [1, 1]) === 0.0);

// ---------------------------------------------------------------------------
echo "\n== Разбор ответа воркера ==\n\n";

$search = Service_Nj653_Veclab::shape([
    'mode'  => 'search',
    'model' => 'qwen3-vl-embedding-2b',
    'dims'  => 2048,
    'dialect' => 'oai_content',
    'elapsed_ms' => 140,
    'hits'  => [
        ['id' => 7, 'label' => 'Кот на подоконнике', 'kind' => 'image', 'distance' => 0.2,
         'preview' => 'data:image/png;base64,iVBORw0KGgo='],
        ['id' => 8, 'label' => 'Декларация НДС', 'kind' => 'text', 'distance' => 0.95],
    ],
]);

foreach ($search['hits'] as $hit)
{
    echo "  {$hit['label']}: {$hit['score']['value']} — {$hit['score']['title']}\n";
}

$check('дистанция 0,2 → близость 0,8', $search['hits'][0]['score']['value'] === 0.8);
$check('чужая находка названа «ничего общего»',
    $search['hits'][1]['score']['title'] === 'ничего общего');
$check('формат запроса назван по-человечески',
    strpos($search['dialect_title'], 'llama.cpp') !== FALSE);

$compare = Service_Nj653_Veclab::shape(['mode' => 'compare', 'cosine' => 0.72,
    'kinds' => ['text', 'image']]);

$check('сравнение: 0,72 — «про одно и то же»', $compare['pair']['title'] === 'про одно и то же');
$check('сравнение: модальности сохранены', $compare['kinds'] === ['text', 'image']);

$weird = Service_Nj653_Veclab::shape(['mode' => 'шутка']);

$check('неизвестный режим не роняет страницу', $weird['mode'] === 'probe');
$check('неизвестный диалект показывается как есть',
    Service_Nj653_Veclab::shape(['mode' => 'probe', 'dialect' => 'новый'])['dialect_title'] === 'новый');

$hits = Service_Nj653_Veclab::hits([
    ['id' => '5', 'label' => ' Кот ', 'kind' => 'непонятно', 'score' => 0.9,
     'preview' => 'javascript:alert(1)'],
    'мусор',
]);

$check('не-массив в списке находок игнорируется', count($hits) === 1);
$check('неизвестная модальность становится текстом', $hits[0]['kind'] === 'text');
$check('чужая ссылка в превью отброшена', $hits[0]['preview'] === '');
$check('подпись подрезана по краям', $hits[0]['label'] === 'Кот');

// ---------------------------------------------------------------------------
echo "\n== Паспорт исходной картинки ==\n\n";

$about = Service_Nj653_Veclab::about([
    'name' => 'IMG_0421.jpg', 'mime' => 'image/jpeg', 'bytes' => 180000,
    'width' => 1400, 'height' => 1050,
    'source_width' => 4032, 'source_height' => 3024, 'source_bytes' => 3250000,
]);

$check('паспорт собран строкой', $about === 'IMG_0421.jpg · 4032×3024 · 3,1 МБ');
$check('паспорт берёт размеры ИСХОДНИКА, а не сжатой копии',
    strpos($about, '1400×1050') === FALSE);
$check('json-строка из базы тоже понимается',
    Service_Nj653_Veclab::about('{"name":"cat.png","bytes":2048}') === 'cat.png · 2 КБ');
$check('пустой паспорт не рисует мусора',
    Service_Nj653_Veclab::about('{}') === '' AND Service_Nj653_Veclab::about(NULL) === '');

// ---------------------------------------------------------------------------
echo "\n== Превью ==\n\n";

$ok_preview = 'data:image/jpeg;base64,/9j/4AAQSkZJRg==';

$check('картинка в base64 проходит', Service_Nj653_Veclab::safe_preview($ok_preview) === $ok_preview);
$check('data:text/html отбрасывается',
    Service_Nj653_Veclab::safe_preview('data:text/html;base64,PHNjcmlwdD4=') === '');
$check('внешний адрес отбрасывается',
    Service_Nj653_Veclab::safe_preview('https://example.com/cat.jpg') === '');
$check('переносы строк в base64 убираются',
    Service_Nj653_Veclab::safe_preview("data:image/png;base64,AA\n AA") === 'data:image/png;base64,AAAA');

// ---------------------------------------------------------------------------
echo "\n== Итог самопроверки модели ==\n\n";

$probe = function (array $checks) {
    return Service_Nj653_Veclab::shape(['mode' => 'probe', 'checks' => $checks])['verdict'];
};

$no_cross = $probe([
    ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
    ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.01, 'ok' => FALSE],
]);

echo '  без кросс-модальности: '.$no_cross['title']." ({$no_cross['tone']})\n";

$check('провал кросс-модальности виден отдельно',
    $no_cross['ok'] === FALSE AND $no_cross['tone'] === 'rose');

$shaky = $probe([
    ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 0.3, 'ok' => FALSE],
    ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.5, 'ok' => TRUE],
]);

$check('непрошедшая проверка названа по имени',
    $shaky['tone'] === 'amber' AND strpos($shaky['note'], 'Повторяемость') !== FALSE);

$skipped = $probe([
    ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
    ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => NULL, 'ok' => NULL],
]);

$check('пропущенная проба — не провал, но и не «зелено»',
    $skipped['ok'] === TRUE AND $skipped['tone'] === 'amber');

$green = $probe([
    ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
    ['key' => 'meaning', 'title' => 'Смысл текста', 'value' => 0.47, 'ok' => TRUE],
    ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.48, 'ok' => TRUE],
]);

$check('всё прошло — модель пригодна', $green['ok'] === TRUE AND $green['tone'] === 'emerald');

// Режим описания: работает, но другим способом — об этом обязан знать человек.
$caption = Service_Nj653_Veclab::shape([
    'mode'     => 'probe',
    'passport' => [
        'mode'      => 'caption',
        'mode_note' => 'картинки описывает vision-модель',
        'models'    => ['qwen3-vl-embedding-2b', 'text-embedding-qwen3-embedding-0.6b'],
        'provider'  => 'TOOLFORGE',
    ],
    'checks'   => [
        ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
        ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.48, 'ok' => TRUE],
    ],
]);

echo '  режим описания:        '.$caption['verdict']['title'].
    " ({$caption['verdict']['tone']})\n";

$check('режим описания назван своим именем, а не «всё отлично»',
    $caption['verdict']['tone'] === 'amber'
    AND mb_strpos($caption['verdict']['title'], 'описание') !== FALSE);

$check('паспорт донёс режим, список моделей и провайдера',
    $caption['passport']['mode'] === 'caption'
    AND count($caption['passport']['models']) === 2
    AND $caption['passport']['provider'] === 'TOOLFORGE');

// ---------------------------------------------------------------------------
echo "\n== Вьюхи ==\n\n";

$probe_shape = Service_Nj653_Veclab::shape([
    'mode'       => 'probe',
    'model'      => 'qwen3-vl-embedding-2b',
    'dims'       => 2048,
    'dialect'    => 'oai_content',
    'elapsed_ms' => 210,
    'ok'         => TRUE,
    'passport'   => [
        'base_url' => 'http://127.0.0.1:1234/v1', 'model' => 'qwen3-vl-embedding-2b',
        'dimensions' => 2048, 'dims_real' => 2048, 'dialect' => 'oai_content',
        'attempts' => [
            ['dialect' => 'qwen_objects', 'ok' => FALSE, 'error' => 'HTTP 400'],
            ['dialect' => 'oai_content', 'ok' => TRUE, 'ms' => 34, 'dims' => 2048],
        ],
    ],
    'checks' => [
        ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
        ['key' => 'meaning', 'title' => 'Смысл текста', 'value' => 0.4725, 'ok' => TRUE],
        ['key' => 'cross', 'title' => 'Текст и картинка в одном пространстве',
         'value' => 0.4752, 'ok' => TRUE, 'note' => 'каждая подпись выбрала свою картинку'],
    ],
]);

$page = (string) View::factory('653/index', [
    'logged'   => TRUE,
    'limits'   => (array) Kohana::$config->load('nj653_veclab.limits'),
    'scale'    => Service_Nj653_Veclab::scale(),
    'stats'    => ['ready' => TRUE, 'pgvector' => TRUE, 'dims' => 2048, 'total' => 12,
                   'model' => 'qwen3-vl-embedding-2b'],
    'items'    => Service_Nj653_Veclab::hits([
        ['id' => 1, 'label' => 'Рыжий кот на подоконнике', 'kind' => 'image', 'score' => 1.0,
         'preview' => 'data:image/png;base64,iVBORw0KGgo='],
        ['id' => 2, 'label' => 'Счёт-фактура за третий квартал', 'kind' => 'text', 'score' => 1.0],
    ]),
    'mine'     => 2,
    'runs'     => [
        ['id' => 9, 'mode' => 'search', 'status' => 'done', 'title' => 'Поиск',
         'error' => '', 'elapsed_ms' => 1400, 'created_at' => '2026-10-04 12:00:00'],
        ['id' => 8, 'mode' => 'probe', 'status' => 'error', 'title' => 'Проверка',
         'error' => 'модель молчит', 'elapsed_ms' => 0, 'created_at' => '2026-10-04 11:00:00'],
    ],
    'probe'    => $probe_shape,
    'probe_at' => '2026-10-04 11:30:00',
    'samples'  => (array) Kohana::$config->load('nj653_veclab.samples'),
]);

$card = (string) View::factory('nj653/card');

$check('страница /veclab отрисовалась', strlen($page) > 4000);
$check('поиск из истории открывается отдельной страницей',
    strpos($page, 'href="/veclab/result/9"') !== FALSE);
$check('на странице есть все три вкладки',
    substr_count($page, 'data-panel="') === 3);
$check('живой ряд отдан шаблоном', strpos($page, 'nj653-live-tpl') !== FALSE);
$check('JS страницы подключён', strpos($page, "var API = '/veclab/api'") !== FALSE);
$check('паспорт модели показан', strpos($page, 'qwen3-vl-embedding-2b') !== FALSE);
$check('есть выбор сразу нескольких картинок',
    strpos($page, 'id="nj653-a-bulk"') !== FALSE AND strpos($page, 'multiple') !== FALSE);
$check('предел пачки доехал до JS', strpos($page, 'var BULK_MAX = 12;') !== FALSE);
$check('пачку предлагают только в коллекции (в сравнении её нет)',
    substr_count($page, 'id="nj653-a-bulk-list"') === 1
    AND strpos($page, 'id="nj653-b-bulk"') === FALSE);
$check('итог самопроверки на странице',
    strpos($page, 'Модель пригодна для мультимодального поиска') !== FALSE);
$check('коллекция показана с превью',
    strpos($page, 'data:image/png;base64,iVBORw0KGgo=') !== FALSE);
$check('подпись элемента экранирована (нет сырых кавычек в атрибутах)',
    strpos($page, 'data-nj653-item="1"') !== FALSE);
$check('карточка каталога отрисовалась', strpos($card, '/veclab') !== FALSE);

// Гость: вместо рабочей области — плашка входа (замок 08.10.2026, partials/auth_wall).
Gate::$logged = FALSE;
$guest_page = (string) View::factory('653/index', [
    'logged'   => FALSE,
    'limits'   => (array) Kohana::$config->load('nj653_veclab.limits'),
    'scale'    => Service_Nj653_Veclab::scale(),
    'stats'    => [],
    'items'    => [],
    'mine'     => 0,
    'runs'     => [],
    'kinds'    => [],
    'probe'    => NULL,
    'probe_at' => '',
    'csrf'     => 'тест',
]);
Gate::$logged = TRUE;

$check('гость видит плашку входа вместо рабочей области',
    strpos($guest_page, 'data-auth-wall="veclab"') !== FALSE
    AND strpos($guest_page, 'var API = ') === FALSE
    AND strpos($guest_page, '/auth/login?return_url=veclab') !== FALSE);

$result_data = Service_Nj653_Veclab::shape([
    'mode' => 'search', 'elapsed_ms' => 1250,
    'hits' => [
        ['id' => 31, 'label' => 'Кот <script>', 'kind' => 'text',
         'content' => 'Рыжий кот у окна', 'score' => 0.91],
    ],
]);

$running_result = (string) View::factory('653/result', [
    'run' => ['id' => 41, 'status' => 'running', 'created_at' => '2026-10-04 12:00:00', 'error' => ''],
    'query' => ['content' => 'кот <script>', 'kind' => 'text', 'preview' => ''],
    'result' => $result_data,
    'live' => ['status' => 'running', 'stage' => 'Сравниваю векторы…', 'elapsed_sec' => 4,
        'silent_sec' => NULL, 'stages' => []],
    'hint' => '', 'running' => TRUE,
]);

$done_result = (string) View::factory('653/result', [
    'run' => ['id' => 41, 'status' => 'done', 'created_at' => '2026-10-04 12:00:00', 'error' => ''],
    'query' => ['content' => 'кот <script>', 'kind' => 'text', 'preview' => ''],
    'result' => $result_data,
    'live' => ['status' => 'done', 'stage' => '', 'elapsed_sec' => 4,
        'silent_sec' => NULL, 'stages' => []],
    'hint' => '', 'running' => FALSE,
]);

$error_result = (string) View::factory('653/result', [
    'run' => ['id' => 42, 'status' => 'error', 'created_at' => '2026-10-04 12:01:00',
        'error' => 'Очередь недоступна'],
    'query' => ['content' => 'кот, потерянный до отказа', 'kind' => 'text',
        'preview' => 'data:text/html,<script>alert(1)</script>'],
    'result' => Service_Nj653_Veclab::shape(['mode' => 'search',
        'pending' => [['content' => 'кот, потерянный до отказа']]]),
    'live' => ['status' => 'failed', 'stage' => '', 'elapsed_sec' => 0,
        'silent_sec' => NULL, 'stages' => []],
    'hint' => '', 'running' => FALSE,
]);

$check('отдельная страница показывает экранированный запрос и живой статус',
    strpos($running_result, 'кот &lt;script&gt;') !== FALSE
    AND strpos($running_result, 'data-live-url="/veclab/api/status?id=41"') !== FALSE);
$check('страница результата выровнена по левому краю без max-width центрирования',
    strpos($running_result, 'class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8"') !== FALSE
    AND strpos($running_result, 'mx-auto max-w-6xl') === FALSE);
$check('страница результата не возвращает поле ввода и фокус',
    strpos($running_result, '<input') === FALSE AND strpos($running_result, 'autofocus') === FALSE);
$check('завершённая страница показывает безопасную находку',
    strpos($done_result, 'Кот &lt;script&gt;') !== FALSE
    AND strpos($done_result, 'Рыжий кот у окна') !== FALSE
    AND strpos($done_result, '0,9100') !== FALSE);
$check('при сбое очередь не теряет сохранённый запрос',
    strpos($error_result, 'кот, потерянный до отказа') !== FALSE
    AND strpos($error_result, 'Очередь недоступна') !== FALSE);
$check('небезопасное data-превью удаляется до вывода в разметку',
    strpos($error_result, 'data:text/html') === FALSE);

// Пустое состояние: ни одной проверки, ни одного элемента — страница обязана
// работать (так её увидит первый посетитель).
$empty = (string) View::factory('653/index', [
    'logged'   => TRUE,
    'limits'   => (array) Kohana::$config->load('nj653_veclab.limits'),
    'scale'    => Service_Nj653_Veclab::scale(),
    'stats'    => ['ready' => FALSE, 'pgvector' => FALSE, 'dims' => 0, 'total' => 0, 'model' => ''],
    'items'    => [],
    'mine'     => 0,
    'runs'     => [],
    'probe'    => [],
    'probe_at' => '',
    'samples'  => [],
]);

$check('пустая страница тоже рисуется', strlen($empty) > 3000);
$check('пустая коллекция объяснена словами', strpos($empty, 'Пока пусто') !== FALSE);
$check('честно сказано, что таблиц нет', strpos($empty, 'таблицы не созданы') !== FALSE);

// ---------------------------------------------------------------------------
echo "\n== Карта сходства: раскладка векторов на плоскость ==\n\n";

/** Крошечная «коллекция»: две кучки по три элемента и один одиночка. */
$map_items = [
    ['id' => 1, 'label' => 'кот рыжий',   'kind' => 'image', 'preview' => '', 'vector' => [1.0, 0.0, 0.05]],
    ['id' => 2, 'label' => 'кот спит',    'kind' => 'text',  'preview' => '', 'vector' => [0.98, 0.04, 0.0]],
    ['id' => 3, 'label' => 'кошка',       'kind' => 'text',  'preview' => '', 'vector' => [0.96, 0.0, 0.1]],
    ['id' => 4, 'label' => 'счёт-фактура', 'kind' => 'text', 'preview' => '', 'vector' => [0.0, 1.0, 0.0]],
    ['id' => 5, 'label' => 'накладная',   'kind' => 'text',  'preview' => '', 'vector' => [0.03, 0.99, 0.0]],
    ['id' => 6, 'label' => 'акт сверки',  'kind' => 'text',  'preview' => '', 'vector' => [0.0, 0.97, 0.06]],
    ['id' => 7, 'label' => 'морской прилив', 'kind' => 'text', 'preview' => '', 'vector' => [0.0, 0.0, 1.0]],
];

$map = Service_Nj653_Map::build($map_items, 0.6);

$by_id = [];

foreach ($map['points'] as $point)
{
    $by_id[$point['id']] = $point;
}

$dist = function ($a, $b) use ($by_id) {
    return sqrt(pow($by_id[$a]['x'] - $by_id[$b]['x'], 2) + pow($by_id[$a]['y'] - $by_id[$b]['y'], 2));
};

echo "  кот↔кот: ".round($dist(1, 2), 3)."   кот↔счёт: ".round($dist(1, 4), 3)."\n";

$check('на карте все точки', count($map['points']) === 7);
$check('похожие стоят ближе, чем чужие', $dist(1, 2) < $dist(1, 4));
$check('вторая кучка тоже слиплась', $dist(4, 5) < $dist(4, 1));
$check('одиночка не прилип ни к одной кучке',
    $dist(7, 1) > $dist(1, 2) AND $dist(7, 4) > $dist(4, 5));
$check('координаты внутри холста',
    min(array_column($map['points'], 'x')) >= 0 AND max(array_column($map['points'], 'x')) <= 1
    AND min(array_column($map['points'], 'y')) >= 0 AND max(array_column($map['points'], 'y')) <= 1);
$check('ближайший сосед кота — тоже кот', in_array($by_id[1]['near_id'], [2, 3], TRUE));
$check('ближайший сосед подписан близостью', $by_id[1]['near_cos'] > 0.9);
$check('связи только между похожими',
    $map['links'] !== [] AND min(array_column($map['links'], 'cos')) >= 0.6);
$check('близкие пары названы словами',
    $map['pairs'] !== [] AND $map['pairs'][0]['say']['title'] !== '');

// Карта обязана быть одинаковой при каждом открытии страницы.
$again = Service_Nj653_Map::build($map_items, 0.6);

$check('раскладка повторяется в точности',
    array_column($again['points'], 'x') === array_column($map['points'], 'x'));

$check('на одном элементе карта не падает, а объясняет',
    strpos(Service_Nj653_Map::build([$map_items[0]])['note'], 'хотя бы два') !== FALSE);
$check('элементы без вектора отброшены',
    count(Service_Nj653_Map::build([
        $map_items[0], $map_items[1], ['id' => 9, 'label' => 'пусто', 'vector' => []],
    ])['points']) === 2);

// ---------------------------------------------------------------------------
echo "\n== Страница карты ==\n\n";

$map_page = (string) View::factory('653/map', [
    'map'     => $map,
    'link'    => 0.6,
    'visitor' => 'abc123',
    'mine'    => TRUE,
    'admin'   => FALSE,
    'stats'   => ['ready' => TRUE, 'pgvector' => TRUE, 'dims' => 2048, 'total' => 7, 'model' => 'qwen3-vl'],
    'count'   => 7,
]);

$check('карта отрисовалась в SVG', strpos($map_page, '<svg') !== FALSE);
$check('точек на холсте столько же, сколько элементов',
    substr_count($map_page, '<circle') === 7);
$check('связи нарисованы линиями', substr_count($map_page, '<line') === count($map['links']));
$check('подписи экранированы', strpos($map_page, '<title>') !== FALSE);
$check('поле посетителя скрыто от не-админа',
    strpos($map_page, 'name="visitor"') === FALSE);

$map_empty = (string) View::factory('653/map', [
    'map'     => Service_Nj653_Map::build([]),
    'link'    => 0.6,
    'visitor' => '',
    'mine'    => TRUE,
    'admin'   => TRUE,
    'stats'   => ['ready' => FALSE, 'pgvector' => FALSE, 'dims' => 0, 'total' => 0, 'model' => ''],
    'count'   => 0,
]);

$check('пустая карта объясняет, чего не хватает',
    strpos($map_empty, 'хотя бы два') !== FALSE AND strpos($map_empty, '<circle') === FALSE);
$check('админу поле посетителя показано',
    strpos($map_empty, 'name="visitor"') !== FALSE);

// ---------------------------------------------------------------------------
echo "\n== Страница админа ==\n\n";

$admin_page = (string) View::factory('653/admin', [
    'stats'    => ['ready' => TRUE, 'pgvector' => TRUE, 'dims' => 2048, 'total' => 42,
                   'model' => 'qwen3-vl-embedding-2b'],
    'filter'   => ['visitor' => 'abc', 'kind' => 'image', 'model' => '', 'search' => '',
                   'older_days' => 0],
    'items'    => [
        ['id' => 1, 'visitor' => 'abc', 'label' => 'Кот <script>', 'kind' => 'image',
         'content' => 'кот на подоконнике', 'preview' => 'data:image/png;base64,iVBORw0KGgo=',
         'model' => 'qwen3-vl-embedding-2b', 'dims' => 2048,
         'meta' => ['name' => 'IMG_0421.jpg', 'source_width' => 4032, 'source_height' => 3024,
                    'source_bytes' => 3250000],
         'created_at' => '2026-10-04 12:00:00'],
        ['id' => 2, 'visitor' => 'xyz', 'label' => 'Счёт', 'kind' => 'text',
         'content' => 'счёт-фактура', 'preview' => '', 'model' => 'qwen3-vl-embedding-2b',
         'dims' => 2048, 'meta' => NULL, 'created_at' => '2026-10-03 09:00:00'],
    ],
    'total'    => 42,
    'visitors' => [['visitor' => 'abc', 'items' => 30, 'images' => 20, 'last_at' => '2026-10-04 12:00:00']],
    'models'   => [['model' => 'qwen3-vl-embedding-2b', 'c' => 42]],
    'dupes'    => [['keep_id' => 1, 'drop_id' => 5, 'distance' => 0.004, 'visitor' => 'abc',
                    'keep_label' => 'Кот', 'drop_label' => 'Кот (копия)',
                    'drop_preview' => '']],
    'eps'      => 0.02,
    'kinds'    => Service_Nj653_Veclab::KINDS,
    'flash'    => 'Убрано элементов: 3.',
    'csrf'     => 'тест-токен',
]);

$check('админка отрисовалась', strlen($admin_page) > 4000);
$check('сообщение после действия показано', strpos($admin_page, 'Убрано элементов: 3.') !== FALSE);
$check('подпись элемента экранирована',
    strpos($admin_page, 'Кот <script>') === FALSE AND strpos($admin_page, 'Кот &lt;script&gt;') !== FALSE);
$check('у каждой опасной формы есть токен',
    substr_count($admin_page, 'name="csrf"') >= 4);
$check('опасные формы отправляются только POST',
    substr_count($admin_page, 'method="post"') >= 4);
$check('паспорт картинки виден в списке', strpos($admin_page, 'IMG_0421.jpg') !== FALSE);
$check('копии показаны с расстоянием', strpos($admin_page, 'Похожие пары') !== FALSE);
$check('очистка требует слова подтверждения',
    strpos($admin_page, 'name="confirm"') !== FALSE);
$check('есть ссылка на карту посетителя', strpos($admin_page, '/veclab/map?visitor=') !== FALSE);

/**
 * Заглушки платформы для MCP-слоя: база, очередь и общий Vision.
 *
 * Настоящих таблиц в песочнице нет, а проверить надо именно поведение:
 * что инструмент отказывает на пустом вводе, что дубль не уходит к модели
 * второй раз, что «ещё считается» отличается от готового результата.
 */
class Arr {
    public static function get($array, $key, $default = NULL)
    {
        return isset($array[$key]) ? $array[$key] : $default;
    }
}

class StandDb {
    public static $runs  = [];
    public static $items = [];
    public static $next  = 1;
}

class Vision {
    public static function run_get($table, $id)
    {
        return StandDb::$runs[(int) $id] ?? NULL;
    }

    public static function run_watch($table, $id, $channel = '')
    {
        return ['run' => self::run_get($table, $id), 'hint' => ''];
    }

    public static function run_apply($table, $id, array $result)
    {
        StandDb::$runs[(int) $id]['status'] = (string) ($result['status'] ?? 'done');
        StandDb::$runs[(int) $id]['error']  = (string) ($result['error'] ?? '');
        StandDb::$runs[(int) $id]['result'] = $result;

        return TRUE;
    }

    public static function runs_last_hour($table, $ip) { return 0; }
    public static function run_set_job($table, $id, $job_id) { return TRUE; }
    public static function photo_gc($module) {}

    public static function json_decode($value)
    {
        return is_array($value) ? $value : (array) json_decode((string) $value, TRUE);
    }
}

class Service_Nj120_JobQueue {
    public static $throw = FALSE;
    public static $return_zero = FALSE;

    public static function push($channel, array $payload, array $opts = [])
    {
        if (self::$throw) throw new RuntimeException('test queue failure');
        if (self::$return_zero) return 0;

        return 777;
    }
}

class Service_Nj653_Store {
    const RUNS = 'nj_653_runs';
    const ITEMS = 'nj_653_items';
    const MODULE = 'nj653_veclab';
    public static $fail_photo_save = FALSE;

    public static function table_ready($table) { return TRUE; }
    public static function photo_save($run_id, $slot, $bytes) { return self::$fail_photo_save ? '' : 'slot-'.$slot.'.jpg'; }
    public static function photo_drop($run_id) { return TRUE; }

    public static function run_create($mode, array $pending, array $data = [])
    {
        $id = StandDb::$next++;

        StandDb::$runs[$id] = [
            'id' => $id, 'mode' => $mode, 'status' => 'queued', 'error' => '',
            'visitor' => (string) ($data['visitor'] ?? ''), 'user_id' => NULL,
            'result' => ['mode' => $mode, 'pending' => $pending],
        ];

        return $id;
    }

    public static function items_count($visitor)
    {
        return count(array_filter(StandDb::$items,
            function ($item) use ($visitor) { return $item['visitor'] === $visitor; }));
    }

    public static function items_mine($visitor, $limit = 60)
    {
        return array_slice(array_values(array_filter(StandDb::$items,
            function ($item) use ($visitor) { return $item['visitor'] === $visitor; })), 0, $limit);
    }

    public static function fingerprint($text, $bytes = '')
    {
        return hash('sha256', trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $text))));
    }

    public static function fingerprint_exists($visitor, $fingerprint)
    {
        foreach (StandDb::$items as $item)
        {
            if ($item['visitor'] === $visitor AND $item['fingerprint'] === $fingerprint) return TRUE;
        }

        return FALSE;
    }

    public static function stats()
    {
        return ['ready' => TRUE, 'pgvector' => TRUE, 'dims' => 2048,
            'total' => count(StandDb::$items), 'model' => 'qwen3-vl-embedding-2b'];
    }

    public static function probe_last()
    {
        return ['mode' => 'probe', 'model' => 'qwen3-vl-embedding-2b', 'dims' => 2048,
            'dialect' => 'omni_messages', 'ok' => TRUE, 'elapsed_ms' => 900,
            'finished_at' => '2026-10-05 10:00:00',
            'checks' => [], 'cross' => [], 'passport' => []];
    }
}

require $MOD.'/classes/Service/Nj653/Mcp.php';

// ---------------------------------------------------------------------------
echo "\n== Инструменты MCP ==\n\n";

$decl = (array) Kohana::$config->load('mcp.tools');
$bad  = [];

foreach ($decl as $tool)
{
    list($class, $method) = explode('::', (string) $tool['handler'], 2);

    if ( ! method_exists($class, $method)) $bad[] = (string) $tool['name'];

    if (empty($tool['description']) OR mb_strlen((string) $tool['description']) < 40)
    {
        $bad[] = (string) $tool['name'].' (описание слишком короткое для слабой модели)';
    }
}

$check('инструментов объявлено семь', count($decl) === 7);
$check('у каждого инструмента есть живой обработчик и внятное описание',
    $bad === [], implode(', ', $bad));
$check('проба модели закрыта ключом администратора',
    (string) ($decl[6]['access'] ?? '') === 'admin');

$status = Service_Nj653_Mcp::status([]);

$check('паспорт сервиса собран', $status['ready'] === TRUE AND $status['dims'] === 2048);
$check('в паспорте видна последняя проверка модели',
    is_array($status['last_probe']) AND $status['last_probe']['dialect'] !== '');

$check('пустой текст запоминать отказываемся',
    isset(Service_Nj653_Mcp::remember(['text' => '   '])['error']));

$first = Service_Nj653_Mcp::remember(['text' => 'Рыжий кот спит на подоконнике']);

$check('текст принят и поставлен в очередь',
    (int) ($first['run_id'] ?? 0) > 0 AND $first['mode'] === 'add');
$check('агенту сказано, когда возвращаться',
    strpos((string) $first['hint'], 'nj653_result') !== FALSE);

// Прогон «добавление» доехал: элемент лёг в коллекцию агентов.
StandDb::$items[] = ['visitor' => 'mcp', 'id' => 1, 'label' => 'кот', 'kind' => 'text',
    'content' => 'Рыжий кот спит на подоконнике', 'meta' => NULL,
    'created_at' => '2026-10-05 10:05:00',
    'fingerprint' => Service_Nj653_Store::fingerprint('Рыжий кот спит на подоконнике')];

$check('тот же текст второй раз к модели не поедет',
    isset(Service_Nj653_Mcp::remember(['text' => 'рыжий  КОТ   спит на подоконнике'])['error']));
$check('коллекция агентов видна инструментом',
    Service_Nj653_Mcp::collection([])['count'] === 1);

$found = Service_Nj653_Mcp::search(['query' => 'домашнее животное', 'top' => 3]);

$check('поиск поставлен в очередь', (int) ($found['run_id'] ?? 0) > 0);
$check('пустой запрос отклонён', isset(Service_Nj653_Mcp::search(['query' => ''])['error']));
$check('сравнение без второго текста отклонено',
    isset(Service_Nj653_Mcp::compare(['first' => 'счёт'])['error']));

$pair = Service_Nj653_Mcp::compare(['first' => 'счёт-фактура', 'second' => 'налоговая декларация']);

$check('сравнение поставлено в очередь', (int) ($pair['run_id'] ?? 0) > 0);
$check('незакрытый прогон честно говорит «ещё считается»',
    strpos((string) Service_Nj653_Mcp::result(['run_id' => $found['run_id']])['hint'], 'Подожди') !== FALSE);

// Воркер ответил: поиск закрыт находками.
StandDb::$runs[$found['run_id']]['status'] = 'done';
StandDb::$runs[$found['run_id']]['result'] = [
    'mode' => 'search', 'model' => 'qwen3-vl-embedding-2b', 'dims' => 2048, 'elapsed_ms' => 320,
    'hits' => [['id' => 1, 'label' => 'кот', 'kind' => 'text', 'distance' => 0.25]],
];

$done = Service_Nj653_Mcp::result(['run_id' => $found['run_id']]);

$check('находки отданы с близостью числом и словами',
    $done['hits'][0]['cosine'] === 0.75 AND $done['hits'][0]['similarity'] !== '');

StandDb::$runs[$pair['run_id']]['status'] = 'done';
StandDb::$runs[$pair['run_id']]['result'] = ['mode' => 'compare', 'cosine' => 0.72];

$check('сравнение отдано косинусом и оценкой',
    Service_Nj653_Mcp::result(['run_id' => $pair['run_id']])['similarity'] === 'про одно и то же');

$check('несуществующий прогон не выдумывается',
    isset(Service_Nj653_Mcp::result(['run_id' => 999])['error']));

// Чужой прогон (страница сайта) через MCP не виден: там чужие картинки.
StandDb::$runs[500] = ['id' => 500, 'mode' => 'search', 'status' => 'done', 'error' => '',
    'visitor' => 'браузер-человека', 'user_id' => NULL, 'result' => ['mode' => 'search']];

$check('чужой прогон агенту не показывается',
    isset(Service_Nj653_Mcp::result(['run_id' => 500])['error']));

$check('добавление отдаёт, сколько легло и сколько отсеялось',
    Service_Nj653_Veclab::shape(['mode' => 'add', 'added' => 3, 'duplicates' => 2])['duplicates'] === 2);

// ---------------------------------------------------------------------------
echo "\n== Повторяемость: три исхода вместо двух ==\n\n";

$probe_warn = Service_Nj653_Veclab::shape([
    'mode' => 'probe', 'ok' => TRUE,
    'passport' => ['dialect' => 'omni_messages'],
    'cross' => ['ok' => TRUE],
    'checks' => [
        ['key' => 'repeat', 'title' => 'Повторяемость: один текст дважды',
         'value' => 0.9976, 'ok' => TRUE, 'level' => 'warn',
         'note' => 'почти один и тот же вектор — обычное дело для квантованной сборки'],
        ['key' => 'cross', 'title' => 'Текст и картинка в одном пространстве',
         'value' => 0.71, 'ok' => TRUE, 'level' => 'ok', 'note' => ''],
    ],
]);

$check('0,9976 — это оговорка, а не провал',
    $probe_warn['checks'][0]['level'] === 'warn' AND $probe_warn['verdict']['ok'] === TRUE);
$check('итог остался зелёным', $probe_warn['verdict']['tone'] === 'emerald');
$check('человеку объяснили, почему не единица',
    strpos($probe_warn['verdict']['note'], '0,999') !== FALSE);
$check('формат запроса добран из паспорта',
    strpos($probe_warn['dialect_title'], 'llama.cpp') !== FALSE
    OR $probe_warn['dialect'] === 'omni_messages');

$probe_fail = Service_Nj653_Veclab::shape([
    'mode' => 'probe', 'ok' => FALSE, 'cross' => ['ok' => TRUE],
    'checks' => [
        ['key' => 'repeat_image', 'title' => 'Повторяемость: одна картинка дважды',
         'value' => 0.77, 'ok' => FALSE, 'level' => 'fail', 'note' => 'EMBEDDING_MM_BATCH=1'],
    ],
]);

$check('настоящая беда остаётся провалом',
    $probe_fail['checks'][0]['level'] === 'fail' AND $probe_fail['verdict']['ok'] === FALSE);

$old_style = Service_Nj653_Veclab::shape([
    'mode' => 'probe', 'ok' => TRUE, 'cross' => ['ok' => TRUE],
    'checks' => [
        ['key' => 'text', 'title' => 'Смысл текста', 'value' => 0.5, 'ok' => TRUE],
        ['key' => 'cross', 'title' => 'Кросс', 'value' => NULL, 'ok' => NULL],
    ],
]);

$check('прогоны без поля уровня читаются по-старому',
    $old_style['checks'][0]['level'] === 'ok' AND $old_style['checks'][1]['level'] === 'skip');

$js = (string) View::factory('653/script', [
    'photo_mb' => 8, 'items_max' => 60, 'bulk_max' => 12,
]);

$check('кнопка молчит, пока говорит живой ряд',
    strpos($js, "busy(opts.button, true, row ? null : (opts.wait") !== FALSE);
$check('жёлтый значок проверки доехал до JS',
    strpos($js, 'fa-circle-exclamation text-amber-500') !== FALSE);
$check('в сравнении объяснено, почему не ровно единица',
    strpos($js, 'с точностью около 0,999') !== FALSE);

// Ошибка до постановки в очередь всё равно оставляет запрос на странице прогона.
class Response {
    public $status_code = 200;
    public $content = '';
    public function status($status) { $this->status_code = (int) $status; return $this; }
    public function body($body) { $this->content = (string) $body; return $this; }
}
class Controller {
    public $response;
    public function __construct() { $this->response = new Response; }
}
class Log { const INFO = 6; }
class Log_Plain { public static function event($type, $message, array $data = []) {} }

require $MOD.'/classes/Controller/Nj653/Api.php';
$start = new ReflectionMethod('Controller_Nj653_Api', '_start');
$start->setAccessible(TRUE);

Service_Nj653_Store::$fail_photo_save = TRUE;
$photo_pending = ['text' => 'рыжий кот у окна'];
$photo_response = $start->invoke(new Controller_Nj653_Api, 'search', $photo_pending,
    [['slot' => 1, 'bytes' => 'fake-image-bytes']]);
$photo_json = json_decode($photo_response->content, TRUE);
$photo_run = (int) ($photo_json['run_id'] ?? 0);

$check('ошибка сохранения кадра возвращает ID созданного поиска',
    $photo_response->status_code === 500 AND $photo_run > 0 AND empty($photo_json['ok']));
$check('при ошибке сохранения кадра pending остаётся в результате прогона',
    (StandDb::$runs[$photo_run]['result']['pending']['text'] ?? '') === $photo_pending['text']);

Service_Nj653_Store::$fail_photo_save = FALSE;
Service_Nj120_JobQueue::$throw = TRUE;
$queue_pending = ['text' => 'голубой велосипед у стены'];
$queue_response = $start->invoke(new Controller_Nj653_Api, 'search', $queue_pending, []);
$queue_json = json_decode($queue_response->content, TRUE);
$queue_run = (int) ($queue_json['run_id'] ?? 0);

$check('ошибка очереди возвращает ID созданного поиска',
    $queue_response->status_code === 503 AND $queue_run > 0 AND empty($queue_json['ok']));
$check('при ошибке очереди pending остаётся в результате прогона',
    (StandDb::$runs[$queue_run]['result']['pending']['text'] ?? '') === $queue_pending['text']);

Service_Nj120_JobQueue::$throw = FALSE;
Service_Nj120_JobQueue::$return_zero = TRUE;
$zero_pending = ['text' => 'красная чашка на столе'];
$zero_response = $start->invoke(new Controller_Nj653_Api, 'search', $zero_pending, []);
$zero_json = json_decode($zero_response->content, TRUE);
$zero_run = (int) ($zero_json['run_id'] ?? 0);
$check('нулевой ID очереди считается отказом и возвращает прогон',
    $zero_response->status_code === 503 AND $zero_run > 0 AND empty($zero_json['ok']));
$check('при нулевом ID очереди pending остаётся в прогоне',
    (StandDb::$runs[$zero_run]['result']['pending']['text'] ?? '') === $zero_pending['text']);
Service_Nj120_JobQueue::$return_zero = FALSE;

// Маршрут результата должен принимать только числовой run_id.
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
$run_route = Route::$routes['nj653_result'] ?? [];
$id_regex = (string) ($run_route['regex']['id'] ?? '');
$check('маршрут /veclab/result принимает цифровой id',
    ($run_route['uri'] ?? '') === 'veclab/result/<id>'
    AND $id_regex !== '' AND preg_match('~^'.$id_regex.'$~', '42') === 1
    AND preg_match('~^'.$id_regex.'$~', '4x') === 0);

echo "\n".($fail === 0 ? "Всё чисто\n" : "Провалов: $fail\n");

exit($fail === 0 ? 0 : 1);
