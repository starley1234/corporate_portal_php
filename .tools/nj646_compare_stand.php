<?php
/**
 * nj646_label: сравнение двух составов — логика и обе вьюхи без БД.
 *
 * Зачем стенд. `compare()` — чистая функция: база приходит индексом, значит её
 * можно выполнить целиком — и увидеть настоящий результат, а не только
 * синтаксис. Здесь же рендерятся обе страницы сравнения (полная и «с кем
 * сравнить»): так ловятся опечатки во вьюхах и обращения к несуществующим
 * переменным, которые синтаксический линт пропускает.
 *
 * Запуск через PHP-in-WASM (в песочнице PHP нет):
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj646_compare_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);

// Предупреждения и замечания PHP — тоже провал: в бою это «Notice: Undefined
// variable» в середине страницы, и увидеть их надо здесь, а не у человека.
$fail = 0;

set_error_handler(function ($no, $str, $file, $line) use (&$fail) {
    echo '  ПРОВАЛ  PHP: '.$str.' ('.$file.':'.$line.")\n";
    $fail++;

    return TRUE;
});

class Kohana {
    public static $config;
    public static $log;
}

class HTML {
    public static function chars($value) {
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

/** Заглушка Store: конфиг читаем настоящий, флаги — как в оригинале. */
class Service_Nj646_Store {
    protected static $cfg = NULL;

    public static function cfg($key = NULL, $default = NULL, $file = 'nj646_label')
    {
        if (self::$cfg === NULL)
        {
            self::$cfg = (array) include dirname(__DIR__).'/application/modules/nj646_label/config/nj646_label.php';
        }

        if ($key === NULL) return self::$cfg;

        $value = self::$cfg;

        foreach (explode('.', $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value)) return $default;

            $value = $value[$part];
        }

        return $value === NULL ? $default : $value;
    }

    public static function flag($value) { return $value === TRUE OR $value === 1 OR $value === '1' OR $value === 't'; }
    public static function nutrition_of($product) { return []; }
}

require $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Label.php';
require $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/About.php';

/** Строка индекса веществ — как её строит Store::index(). */
function row($id, $slug, $name, $risk, $verdict, $category, $e_code = '', array $synonyms = [])
{
    $keys = [];

    foreach (array_merge([$name, str_replace('-', ' ', $slug)], $synonyms) as $n)
    {
        $key = Service_Nj646_Label::normalize($n);

        if ($key !== '') $keys[] = $key;
    }

    if ($e_code !== '') $keys[] = strtolower(str_replace(' ', '', $e_code));

    return [
        'id' => $id, 'slug' => $slug, 'name_ru' => $name, 'name_int' => '',
        'risk' => $risk, 'verdict' => $verdict, 'category' => $category, 'e_code' => $e_code,
        'synonyms' => array_values($synonyms), 'keys' => array_values(array_unique($keys)),
    ];
}

$index = ['rows' => [], 'map' => []];

foreach ([
    row(1, 'sugar',           'Сахар',            0, 'useful',  'сахар и сладости'),
    row(2, 'salt',            'Соль',             1, 'neutral', 'соль и специи'),
    row(3, 'citric-acid',     'Лимонная кислота', 1, 'useful',  'кислоты'),
    row(4, 'sodium-benzoate', 'Бензоат натрия',   3, 'risky',   'консерванты', 'E211'),
    row(5, 'water',           'Вода',             0, 'useful',  'вода'),
    row(6, 'aspartame',       'Аспартам',         4, 'avoid',   'сахарозаменители', 'E951'),
] as $r)
{
    $index['rows'][$r['slug']] = $r;

    foreach ($r['keys'] as $key)
    {
        if ( ! isset($index['map'][$key])) $index['map'][$key] = $r['slug'];
    }
}

$check = function ($name, $ok) use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name."\n";

    if ( ! $ok) $fail++;
};

echo "== compare(): чистый прогон на выдуманной базе ==\n\n";

// --- 1. Разные составы: у A опасная добавка и незнакомое вещество -----------
$a_items = Service_Nj646_Label::parse('Сахар, соль, лимонная кислота, Е211, экстракт ромашки');
$b_items = Service_Nj646_Label::parse('Сахар, соль, вода');

$cmp = Service_Nj646_Label::compare($a_items, $b_items, $index);

echo "A: известных ".count($cmp['a']['known']).", незнакомых ".count($cmp['a']['unknown'])
    .", оценка ".$cmp['a']['score'].", средний риск ".$cmp['a']['risk_mean']
    .", высокий риск ".$cmp['a']['high']."\n";
echo "B: известных ".count($cmp['b']['known']).", незнакомых ".count($cmp['b']['unknown'])
    .", оценка ".$cmp['b']['score'].", средний риск ".$cmp['b']['risk_mean']
    .", высокий риск ".$cmp['b']['high']."\n";
echo 'лидер: '.$cmp['leader'].', разница '.$cmp['diff'].' пунктов'."\n";
echo 'общих: '.implode(', ', array_map(function ($r) { return $r['name_ru']; }, $cmp['common']))."\n";
echo 'только у A: '.implode(', ', array_map(function ($r) { return $r['name_ru']; }, $cmp['only_a']))."\n";
echo 'только у B: '.implode(', ', array_map(function ($r) { return $r['name_ru']; }, $cmp['only_b']))."\n\n";

// Кириллическая «Е» в E-коде должна находить вещество так же, как латинская.
$check('«Е211» (кириллица) найдена в базе', count($cmp['a']['known']) === 4);

$check('обе стороны посчитаны', $cmp['a']['score'] > 0 AND $cmp['b']['score'] > 0);
$check('A: оценка 77 (штраф за добавку и незнакомца)', $cmp['a']['score'] === 77);
$check('B: оценка 96 (сахар, соль, вода)', $cmp['b']['score'] === 96);
$check('B чище, разница 19 пунктов', $cmp['leader'] === 'b' AND $cmp['diff'] === -19);
$check('A: средний риск 1,3', $cmp['a']['risk_mean'] === 1.3);
$check('A: высокий риск — 1 позиция', $cmp['a']['high'] === 1);
$check('B: высокого риска нет', $cmp['b']['high'] === 0);
$check('незнакомое вещество — только у A', $cmp['a']['unknown'] === ['экстракт ромашки'] AND $cmp['b']['unknown'] === []);
$check('общих — 2 (сахар, соль)', count($cmp['common']) === 2);

// Порядок: сначала то, к чему больше поводов присмотреться.
$check('в «только у A» первым идёт бензоат (риск 3)',
    ($cmp['only_a'][0]['slug'] ?? '') === 'sodium-benzoate' AND count($cmp['only_a']) === 2);
$check('в общих первым идёт соль (риск 1 выше сахара)',
    ($cmp['common'][0]['slug'] ?? '') === 'salt');

// --- 2. Дубликат вещества в составе считается один раз ---------------------
$dup = Service_Nj646_Label::compare(
    Service_Nj646_Label::parse('Сахар, сахар, соль'),
    Service_Nj646_Label::parse('Вода'),
    $index);

$check('дубликат «сахар, сахар» — одна позиция', count($dup['a']['known']) === 2);
$check('дубликат не попал в «только у A» дважды', count($dup['only_a']) === 2);

// --- 3. Одинаковые составы — ничья ----------------------------------------
$same = Service_Nj646_Label::compare($a_items, $a_items, $index);

$check('равные составы — ничья', $same['leader'] === 'draw' AND $same['diff'] === 0);
$check('у ничьей 4 общих и пусто в различиях',
    count($same['common']) === 4 AND $same['only_a'] === [] AND $same['only_b'] === []);

// --- 4. Пустой состав не роняет страницу ----------------------------------
$empty = Service_Nj646_Label::compare([], $b_items, $index);

// У пустого состава всё содержимое второго — «только у B»; страница от такого
// не должна падать (такой разбор возможен, если состав не распознан).
$check('пустой состав: известных 0, всё ушло в «только у B»',
    $empty['a']['known'] === [] AND $empty['only_a'] === [] AND count($empty['only_b']) === 3);

// ==========================================================================
// Вьюхи: полная страница сравнения и страница выбора пары
// ==========================================================================

echo "\n== вьюхи: рендер без PHP-замечаний ==\n\n";

$product = function ($id, $name, $kind, $score, $verdict, $producer, $auto = FALSE) {
    return [
        'id' => $id, 'name' => $name, 'name_auto' => $auto, 'product_kind' => $kind,
        'producer_name' => $producer, 'last_score' => $score, 'last_verdict' => $verdict,
        'analyses_count' => 3, 'last_seen' => date('Y-m-d H:i:s', time() - 3600),
        'last_analysis_id' => 10 + $id,
    ];
};

$a = $product(11, 'Печенье овсяное', 'cookies', 77, 'neutral', 'ООО «Сладкий дом»');
$b = $product(12, 'Сметана 15%',     'dairy',   96, 'useful',  'ООО «Молочная река»');

$html = (string) View::factory('646/compare', ['a' => $a, 'b' => $b, 'compare' => $cmp]);

foreach ([
    'заголовок сравнения'          => 'Что чище: Печенье овсяное или Сметана 15%',
    'вывод про лидера'             => 'Чище состав у «Сметана 15%»',
    'разница в пунктах'            => 'разница 19 пунктов',
    'карточки товаров'             => 'средний риск',
    'блок общих веществ'           => 'У обоих',
    'список только у первого'      => 'Только у «Печенье овсяное»',
    'список только у второго'      => 'Только у «Сметана 15%»',
    'незнакомцы базе'              => 'Чего база ещё не знает',
    'экстракт ромашки в списке'    => 'экстракт ромашки',
    'как считаем — пояснение'      => 'Как мы считаем',
    'ссылка на карточку товара'    => '/label/product/11',
    'ссылка на вещество'           => '/label/ingredient/sodium-benzoate',
] as $what => $needle)
{
    $check('вьюха сравнения: '.$what, mb_strpos($html, $needle) !== FALSE);
}

// Ни одного индикатора-полосы: владелец убрал их по всему проекту.
$check('вьюха сравнения: без полос-метров',
    mb_strpos($html, 'overflow-hidden rounded-full bg-') === FALSE
    AND mb_strpos($html, 'style="width') === FALSE);

// Страница выбора пары — два состояния: с выбранным товаром и с нуля.
$pick_one = (string) View::factory('646/compare_pick', [
    'product' => $a,
    'pool'    => [$b, $product(13, 'Вафли', 'cookies', 54, 'caution', 'ИП Петров', TRUE)],
    'note'    => '',
]);

$check('выбор пары: заголовок про первый товар',
    mb_strpos($pick_one, 'С кем сравнить «Печенье овсяное»') !== FALSE);
$check('выбор пары: ссылка на пару товаров',
    mb_strpos($pick_one, '/label/compare/11/12') !== FALSE);
$check('выбор пары: рабочее имя помечено', mb_strpos($pick_one, 'имя рабочее') !== FALSE);

$pick_none = (string) View::factory('646/compare_pick', [
    'product' => NULL,
    'pool'    => [],
    'note'    => '',
]);

$check('выбор пары без товаров: понятная пустота',
    mb_strpos($pick_none, 'Разобранных составов пока нет') !== FALSE);

$history = (string) View::factory('646/my_analysis', [
    'history_total' => 1,
    'history' => [[
        'id' => 78, 'title' => 'Крем для рук', 'title_auto' => FALSE,
        'product_name' => 'Крем для рук', 'product_name_auto' => FALSE,
        'product_kind_slug' => 'cream', 'last_verdict' => 'caution', 'last_score' => 73,
        'counts' => ['risky' => 1], 'unknown' => [['name' => 'Новый компонент']],
        'producer_name' => 'Тестовый производитель', 'created_at' => date('Y-m-d H:i:s', time() - 3600),
    ]],
]);

$profile = (string) View::factory('646/profile', [
    'profile' => ['skin' => 'sensitive', 'diet' => 'vegetarian', 'conditions' => ['allergy'],
        'allergies' => 'лимонен'],
    'csrf' => 'csrf-token',
]);

$check('мои разборы: макет 2/3 + 1/3, подсказки не скрыты на телефоне',
    mb_strpos($history, 'md:grid-cols-3') !== FALSE
    AND mb_strpos($history, 'md:col-span-2') !== FALSE
    AND mb_strpos($history, '<aside class="space-y-5">') !== FALSE);
$check('мои разборы: рабочая область не центрируется узкой обёрткой',
    mb_strpos($history, 'mx-auto max-w-6xl') === FALSE);
$check('мои разборы: иконка типа товара подключает семейство Font Awesome',
    mb_strpos($history, 'class="fas fa-jar"') !== FALSE);
$check('мои разборы: товар, производитель и тезисы видны',
    mb_strpos($history, 'Крем для рук') !== FALSE
    AND mb_strpos($history, 'Тестовый производитель') !== FALSE
    AND mb_strpos($history, 'высокий риск: 1') !== FALSE);
$check('профиль: макет следует эталону и показывает сохранённые настройки',
    mb_strpos($profile, 'md:grid-cols-3') !== FALSE
    AND mb_strpos($profile, 'data-condition="allergy"') !== FALSE
    AND mb_strpos($profile, 'value="лимонен"') !== FALSE);
$check('профиль: рабочая область не центрируется узкой обёрткой',
    mb_strpos($profile, 'mx-auto max-w-6xl') === FALSE);
$check('профиль: запрос удаления защищён csrf',
    mb_strpos($profile, 'data-csrf="csrf-token"') !== FALSE);
$check('профиль: конфиденциальность и срок хранения доступны на телефоне',
    mb_strpos($profile, '<aside class="space-y-5">') !== FALSE
    AND mb_strpos($profile, 'После года без обращений') !== FALSE);
$check('Label: локальные блоки не дублируют модульную навигацию',
    mb_strpos($history, 'href="/label/profile"') === FALSE
    AND mb_strpos($profile, 'href="/label/my_analysis"') === FALSE);


// ==========================================================================
// По желанию — сохранить страницы как HTML (N2J_STAND_OUT=<каталог>).
// Нужен, когда стенда мало и хочется посмотреть глазами: PHP в песочнице нет,
// а приложение поднять нечем. CSS — настоящий, из public_html/assets.
// ==========================================================================

// Режим «показать глазами»: N2J_STAND_OUT=1 — печатаем готовые страницы
// маркерами (<!--n2j:file=…-->), а обёртка кладёт их в файлы. Прямо в
// виртуальную ФС писать бесполезно — она живёт только внутри прогона.
if (getenv('N2J_STAND_OUT'))
{
    $page = function ($title, $body) {
        return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title>'
            .'<link rel="stylesheet" href="assets/css/app.css">'
            .'<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">'
            .'</head><body class="bg-gray-50 dark:bg-gray-900">'
            .'<div class="min-h-screen bg-white py-6 dark:bg-gray-900">'.$body.'</div>'
            .'</body></html>';
    };

    foreach ([
        'index.html' => ['Что чище: Печенье овсяное или Сметана 15%', $html],
        'pick.html'  => ['С кем сравнить: Печенье овсяное', $pick_one],
    ] as $file => $one)
    {
        echo "\n<!--n2j:file=".$file."-->\n".$page($one[0], $one[1])."\n<!--n2j:end-->\n";
    }
}

echo "\n".($fail ? "ПРОВАЛОВ: $fail" : 'провалов: 0')."\n";
