<?php
/**
 * nj646_label: слияние дублей производителей и товаров — подсказка пар, план
 * переноса и рендер страницы слияния, без БД.
 *
 * Зачем стенд. Слияние необратимо (вторая карточка исчезает), поэтому проверять
 * его «на живом» поздно. Здесь всё, что решается ДО записи: правила подозрений
 * (ИНН/ОГРН, штрихкод, «ядро» названия), выбор главной карточки, план переноса
 * («разборов переедет N», «заполнится полей M»), предупреждения (разные ИНН) и
 * страница: пары, причина, две кнопки «которую оставить».
 *
 * Запуск (в песочнице PHP нет) — через PHP-in-WASM:
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj646_merge_stand.php
 */
if ( ! defined('SYSPATH')) define('SYSPATH', dirname(__DIR__).'/system/');

$ROOT = dirname(__DIR__);
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

require $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Merge.php';

$check = function ($name, $ok) use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name."\n";

    if ( ! $ok) $fail++;
};

echo "== «ядро» названия: формы юрлиц, кавычки, слова-наполнители ==\n\n";

$check('ООО «Ромашка» → ромашка',
    Service_Nj646_Merge::producer_hint('ООО «Ромашка»') === 'ромашка');
$check('АО "РОМАШКА-ТРЕЙД" → ромашка трейд',
    Service_Nj646_Merge::producer_hint('АО "РОМАШКА-ТРЕЙД"') === 'ромашка трейд');
$check('«ИП Иванов И.И.» → иванов',
    Service_Nj646_Merge::producer_hint('ИП Иванов И.И.') === 'иванов');
$check('«Молочный комбинат № 1» — служебное слово уходит',
    Service_Nj646_Merge::producer_hint('Молочный комбинат № 1') === 'молочный');
$check('слишком короткое имя не сравниваем (сравнивать нечего)',
    Service_Nj646_Merge::producer_hint('ОАО') === '');

echo "\n== «ядро» товара: вес, упаковка, бренд ==\n\n";

$check('«Молоко 3,2%» → молоко', Service_Nj646_Merge::product_hint('Молоко 3,2%') === 'молоко');
$check('«Печенье овсяное 250 г» → печенье овсяное',
    Service_Nj646_Merge::product_hint('Печенье овсяное 250 г') === 'печенье овсяное');
$check('бренд из названия убран: «Молоко Простоквашино» → молоко',
    Service_Nj646_Merge::product_hint('Молоко Простоквашино', 'Простоквашино') === 'молоко');
$check('«Сметана 15 %» и «сметана 15%» — одно и то же',
    Service_Nj646_Merge::product_hint('Сметана 15 %') === Service_Nj646_Merge::product_hint('сметана 15%'));

echo "\n== пары производителей: причина и вес ==\n\n";

$producers = [
    ['id' => 1, 'name' => 'ООО «Ромашка»', 'inn' => '7701234567', 'ogrn' => '', 'address' => '',
     'country' => 'Россия', 'phone' => '', 'site' => '', 'hits' => 3, 'analyses' => 1, 'products' => 0],
    ['id' => 2, 'name' => 'РОМАШКА', 'inn' => '', 'ogrn' => '', 'address' => 'г. Москва, ул. Полевая, 1',
     'country' => 'Россия', 'phone' => '+7 495 000-00-00', 'site' => 'romashka.example', 'hits' => 9,
     'analyses' => 4, 'products' => 2],
    ['id' => 3, 'name' => 'Ромашка-Трейд', 'inn' => '7701234567', 'ogrn' => '', 'address' => '',
     'country' => '', 'phone' => '', 'site' => '', 'hits' => 1, 'analyses' => 0, 'products' => 1],
    ['id' => 4, 'name' => 'Совсем другой завод', 'inn' => '7809876543', 'ogrn' => '', 'address' => '',
     'country' => '', 'phone' => '', 'site' => '', 'hits' => 0, 'analyses' => 0, 'products' => 0],
];

$pairs = Service_Nj646_Merge::duplicates($producers, 'producer');

$reasons = [];

foreach ($pairs as $pair)
{
    $reasons[] = $pair['keep']['id'].'/'.$pair['drop']['id'].': '.$pair['why'];
}

echo implode("\n", $reasons)."\n\n";

$check('три пары: по ИНН и по названию', count($pairs) === 3);
$check('разные заводы не спарены',
    count(array_filter($pairs, function ($p) { return (int) $p['keep']['id'] === 4 OR (int) $p['drop']['id'] === 4; })) === 0);
$check('«ООО Ромашка» и «Ромашка-Трейд» — по общему ИНН, счёт 4',
    (int) $pairs[0]['score'] === 4 AND $pairs[0]['keep']['id'] === 1 AND $pairs[0]['drop']['id'] === 3);
$check('«РОМАШКА» и «Ромашка-Трейд» — по началу названия, счёт 2',
    (int) $pairs[2]['score'] === 2 AND strpos((string) $pairs[2]['why'], 'часть второго') !== FALSE);
$check('главной предложена карточка «потяжелее» (id 2: 4 разбора, 9 встреч)',
    $pairs[1]['keep']['id'] === 2 AND $pairs[1]['drop']['id'] === 1 AND $pairs[1]['why'] === 'Названия совпадают');

echo "\n== пары товаров: штрихкод и производитель ==\n\n";

$products = [
    ['id' => 11, 'name' => 'Сметана 15%', 'brand' => '', 'barcode' => '4600000000017', 'producer_id' => 2,
     'weight' => '300 г', 'gost' => '', 'shelf_life' => '', 'storage' => '', 'country' => '', 'hits' => 5,
     'analyses' => 3, 'producer_name' => 'РОМАШКА'],
    ['id' => 12, 'name' => 'Сметана 15 %', 'brand' => '', 'barcode' => '', 'producer_id' => 2,
     'weight' => '300г', 'gost' => '', 'shelf_life' => '', 'storage' => '', 'country' => '', 'hits' => 1,
     'analyses' => 1, 'producer_name' => 'РОМАШКА'],
    ['id' => 13, 'name' => 'Сметана 15%', 'brand' => '', 'barcode' => '', 'producer_id' => 4,
     'weight' => '200 г', 'gost' => '', 'shelf_life' => '', 'storage' => '', 'country' => '', 'hits' => 0,
     'analyses' => 0, 'producer_name' => 'Совсем другой завод'],
    ['id' => 14, 'name' => 'Сметана', 'brand' => 'Лакомо', 'barcode' => '4600000000017', 'producer_id' => 0,
     'weight' => '', 'gost' => '', 'shelf_life' => '', 'storage' => '', 'country' => '', 'hits' => 0,
     'analyses' => 0, 'producer_name' => ''],
];

$pairs_p = Service_Nj646_Merge::duplicates($products, 'product');

foreach ($pairs_p as $pair)
{
    echo '  '.$pair['keep']['id'].'/'.$pair['drop']['id'].': '.$pair['why']."\n";
}

echo "\n";

$check('товары разных известных производителей (11 и 13) не спарены',
    count(array_filter($pairs_p, function ($p) {
        $ids = [(int) $p['keep']['id'], (int) $p['drop']['id']];
        sort($ids);

        return $ids === [11, 13];
    })) === 0);
$check('штрихкод важнее названия: 11 и 14 (одинаковый штрихкод) спарены со счётом 4',
    (int) $pairs_p[0]['score'] === 4 AND $pairs_p[0]['why'] === 'Штрихкод совпадает');
$check('«Сметана 15%» и «Сметана 15 %» одного завода — дубль',
    count(array_filter($pairs_p, function ($p) {
        $ids = [(int) $p['keep']['id'], (int) $p['drop']['id']];
        sort($ids);

        return $ids === [11, 12];
    })) === 1);

echo "\n== план переноса: что переедет и о чём предупредить ==\n\n";

$keep = $producers[1];
$drop = $producers[0];

$plan = Service_Nj646_Merge::plan('producer', $keep, $drop, ['analyses' => 1, 'products' => 0, 'glued' => 0]);

// Предупреждение о разных ИНН — на паре, где ИНН действительно разные.
$plan_warn = Service_Nj646_Merge::plan('producer', $producers[0], $producers[3], ['analyses' => 0]);

echo Service_Nj646_Merge::describe($plan)."\n";
foreach ($plan['steps'] as $step) echo '  · '.$step."\n";
foreach ($plan['warnings'] as $w) echo '  ! '.$w."\n";
echo "\n";

$fills = array_column($plan['fields'], 'field');
sort($fills);

$check('переезд: разбор одного производителя', in_array('Разборов переедет: 1.', $plan['steps'], TRUE));
$check('встречи сложатся: 9 + 3 = 12',
    in_array('Встреч (hits) сложатся: 12.', $plan['steps'], TRUE));
$check('у главной заполнится только пустой ИНН (адрес/телефон уже есть)',
    $fills === ['inn']);
$check('разные ИНН — предупреждение, а не тихое слияние',
    count($plan_warn['warnings']) === 1 AND strpos($plan_warn['warnings'][0], 'ИНН различаются (7701234567 и 7809876543)') !== FALSE);
$check('в описании — вид карточки и число полей',
    strpos(Service_Nj646_Merge::describe($plan), 'Объединяем производителя') === 0);

$thin = ['id' => 31, 'name' => 'Сметана 15%', 'brand' => '', 'barcode' => '', 'weight' => '',
         'gost' => '', 'shelf_life' => '', 'storage' => '', 'country' => '', 'producer_id' => 2, 'hits' => 5];

$plan_p = Service_Nj646_Merge::plan('product', $thin, $products[0], ['analyses' => 3]);

$check('товар: разборы в плане, масса и штрихкод заполняются, предупреждений нет',
    $plan_p['counts']['analyses'] === 3
    AND in_array('weight', array_column($plan_p['fields'], 'field'), TRUE)
    AND in_array('barcode', array_column($plan_p['fields'], 'field'), TRUE)
    AND $plan_p['warnings'] === []);

$plan_move = Service_Nj646_Merge::plan('product',
    ['id' => 21, 'name' => 'Сметана', 'brand' => '', 'barcode' => '', 'producer_id' => 0, 'hits' => 0] + $products[1],
    $products[2], ['analyses' => 2]);

$check('пустой производитель главной заполняется из второй',
    in_array('producer_id', array_column($plan_move['fields'], 'field'), TRUE));

echo "\n== страница слияния ==\n\n";

// Для страницы берём одну пару производителей с РАЗНЫМИ ИНН: так видно и
// предупреждение, и заполнение пустых полей, и выбор главной.
$view_pair = ['keep' => $producers[0], 'drop' => $producers[3], 'why' => 'Названия совпадают', 'score' => 3];

$groups = [
    'producers' => [
        ['pair' => $view_pair, 'plan' => Service_Nj646_Merge::plan('producer', $view_pair['keep'], $view_pair['drop'],
            ['analyses' => 4, 'products' => 2, 'glued' => 1])],
    ],
    'products' => [
        ['pair' => $pairs_p[0], 'plan' => Service_Nj646_Merge::plan('product', $pairs_p[0]['keep'], $pairs_p[0]['drop'],
            ['analyses' => 3])],
    ],
];

$html = (string) View::factory('646/admin_merge', [
    'groups' => $groups, 'counts' => ['producers' => 42, 'products' => 117],
    'csrf' => 'token', 'flash' => 'Дублей пока не объединяли.', 'flash_kind' => 'ok',
]);

foreach ([
    'заголовок'                 => 'Слияние дублей',
    'сколько карточек'          => 'производителей: 42',
    'раздел производителей'     => 'Производители',
    'раздел товаров'            => 'Товары',
    'пар-подозрений числом'     => 'пар-подозрений: 1',
    'причина пары'              => 'Названия совпадают',
    'метка предложенной главной' => 'предлагается главной',
    'план переноса'             => 'Товаров переедет: 2.',
    'слияние одноимённых'       => 'Сольются с уже имеющимися у главного товарами: 1.',
    'заполнение полей'          => 'Заполнится у главной:',
    'предупреждение'            => 'ИНН различаются',
    'кнопка «оставить»'         => 'оставить «Совсем другой завод»',
    'вторая исчезнет'           => 'вторая карточка исчезнет',
    'пояснение про необратимость' => 'уже не вернуть',
] as $what => $needle)
{
    $check('страница: '.$what, mb_strpos($html, $needle) !== FALSE);
}

$check('по две кнопки выбора главной на пару (всего 4)',
    substr_count($html, 'name="keep"') === 4);
$check('обе пары идут через один POST-адрес с CSRF',
    substr_count($html, 'action="/nj646/admin/goods_merge"') === 2
    AND substr_count($html, 'name="csrf"') === 2);
$check('страница: без полос-метров',
    mb_strpos($html, 'overflow-hidden rounded-full bg-') === FALSE AND mb_strpos($html, 'style="width') === FALSE);

$empty = (string) View::factory('646/admin_merge', [
    'groups' => ['producers' => [], 'products' => []], 'counts' => ['producers' => 3, 'products' => 5],
    'csrf' => 'token', 'flash' => '', 'flash_kind' => 'ok',
]);

$check('пустое состояние: «дублей не видно» и как ищутся пары',
    mb_strpos($empty, 'Дублей не видно') !== FALSE
    AND mb_strpos($empty, 'по ИНН/ОГРН, штрихкоду') !== FALSE);

// Режим «показать глазами»: N2J_STAND_OUT=1 — печатаем готовые страницы
// маркерами (<!--n2j:file=…-->), а обёртка кладёт их в файлы: прямо в
// виртуальную ФС писать бесполезно, она живёт только внутри прогона.
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
        'pairs.html' => ['Слияние дублей — разбор состава', $html],
        'none.html'  => ['Слияние дублей: пар нет — разбор состава', $empty],
    ] as $file => $one)
    {
        echo "\n<!--n2j:file=".$file."-->\n".$page($one[0], $one[1])."\n<!--n2j:end-->\n";
    }
}

echo "\n".($fail ? "ПРОВАЛОВ: $fail" : 'провалов: 0')."\n";
