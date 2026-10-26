<?php
/**
 * nj646_label: импорт словаря веществ (Service_Nj646_Import) — разбор файла,
 * план правки базы и рендер страницы импорта, без БД.
 *
 * Зачем стенд. Импорт — операция, которая ПИШЕТ в базу тысячами строк, поэтому
 * проверять её «на живом» поздно. Здесь всё, что можно проверить до записи:
 * разбор CSV (шапка, разделитель, кавычки, комментарии, BOM), вывод слага из
 * русского названия, ошибки строк, корзины плана (добавится / обновится / без
 * изменений) и «было → станет» в предпросмотре.
 *
 * Про план отдельно: он обязан быть ЧЕСТНЫМ — «без изменений» не попадает в
 * запись, а пустая ячейка не стирает поле в карточке. Это и есть главные
 * проверки ниже.
 *
 * Запуск (в песочнице PHP нет) — через PHP-in-WASM:
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj646_import_stand.php
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

require $ROOT.'/application/modules/nj646_label/classes/Service/Nj646/Import.php';

$check = function ($name, $ok) use (&$fail) {
    echo ($ok ? '  ок      ' : '  ПРОВАЛ  ').$name."\n";

    if ( ! $ok) $fail++;
};

echo "== разбор CSV: шапка, разделители, мелочи формы ==\n\n";

$csv = "\xEF\xBB\xBF# словарь на проверку\n"
    ."slug;name_ru;name_int;kind;risk;verdict;category;e_code;status;summary;synonyms;avoid;sources\n"
    ."e211;Бензоат натрия;Sodium benzoate;both;3;risky;консерванты;E211;draft;\"Синтетический консервант; спорен\";бензоат натрия|E211;аллергия на салицилаты;https://example.org\n"
    .";Сахар;Sucrose;еда;1;осторожно;сахар и сладости;;опубликовано;Обычный сахар;сахароза, сахарный песок;диабет;\n"
    ."water;Вода;;both;0;useful;вода;;; ;;;\n";

$parsed = Service_Nj646_Import::parse($csv);

echo 'разделитель: '.$parsed['delimiter'].', шапка: '.($parsed['header'] ? 'да' : 'нет')
    .', строк: '.$parsed['total'].', ошибок: '.count($parsed['errors'])."\n\n";

$check('BOM снят, комментарий и шапка съедены', $parsed['total'] === 3 AND $parsed['header']);
$check('разделитель «;» распознан', $parsed['delimiter'] === ';');
$check('кавычки держат «;» внутри пояснения',
    ($parsed['rows'][0]['summary'] ?? '') === 'Синтетический консервант; спорен');
$check('E-код нормализован: «E211» → «e211»', ($parsed['rows'][0]['e_code'] ?? '') === 'e211');
$check('русский вид состава: «еда» → food', ($parsed['rows'][1]['kind'] ?? '') === 'food');
$check('русский вердикт: «осторожно» → caution', ($parsed['rows'][1]['verdict'] ?? '') === 'caution');
$check('русский статус: «опубликовано» → published', ($parsed['rows'][1]['status'] ?? '') === 'published');
$check('списки по «|» и по запятой', ($parsed['rows'][0]['synonyms'] ?? []) === ['бензоат натрия', 'E211']
    AND ($parsed['rows'][1]['synonyms'] ?? []) === ['сахароза', 'сахарный песок']);
$check('пустые ячейки — «не меняем», а не пусто',
    ($parsed['rows'][2]['status'] ?? NULL) === NULL AND ($parsed['rows'][2]['verdict'] ?? '') === 'useful');

echo "\n== слаг: из русского названия, из E-кода, из латиницы ==\n\n";

$check('«Бензоат натрия» → benzoat-natriya',
    Service_Nj646_Import::slugify('Бензоат натрия') === 'benzoat-natriya');
$check('славянские буквы: «Желатин» → zhelatin',
    Service_Nj646_Import::slugify('Желатин') === 'zhelatin');
$check('Ё и Й: «Мёд, Йогурт» → med-yogurt',
    Service_Nj646_Import::slugify('Мёд, Йогурт') === 'med-yogurt');
$check('мягкий и твёрдый знак не ломают слово: «Подъезд, Соль» → podezd-sol',
    Service_Nj646_Import::slugify('Подъезд, Соль') === 'podezd-sol');

$slug_row = Service_Nj646_Import::slug_from(['slug' => '', 'name_ru' => 'Аспартам', 'name_int' => '', 'e_code' => 'E951']);
$check('E-код важнее названия: слаг e951', $slug_row === 'e951');

$slug_row = Service_Nj646_Import::slug_from(['slug' => '', 'name_ru' => 'Аспартам', 'name_int' => 'Aspartame', 'e_code' => '']);
$check('латиница важнее русского: слаг aspartame', $slug_row === 'aspartame');

$check('человеческая запись пустого поля — прочерк, не «»',
    Service_Nj646_Import::human(NULL) === '—' AND Service_Nj646_Import::human('') === '—');
$check('список в предпросмотре читается через запятую',
    Service_Nj646_Import::human(['сахароза', 'sucrose']) === 'сахароза, sucrose');

echo "\n== ошибки строк: в план ошибок, не в базу ==\n\n";

$bad = Service_Nj646_Import::parse(
    "slug;name_ru;risk;status\n"
    ."ok;Сахар;1;published\n"
    ."noname;;2;published\n"
    ."risky;Соль;7;published\n"
    ."strange;Перец;1;нечто\n"
    ."ok;Дубликат;1;published\n");

$messages = array_map(function ($e) { return $e['message']; }, $bad['errors']);
echo 'строк: '.$bad['total'].', ошибок: '.count($bad['errors'])."\n";

foreach ($messages as $one) echo '  · '.$one."\n";
echo "\n";

$check('без названия — ошибка', count(preg_grep('/Нет названия/u', $messages)) === 1);
$check('риск 7 — ошибка', count(preg_grep('/Риск 7/u', $messages)) === 1);
$check('незнакомый статус — ошибка', count(preg_grep('/Статус «нечто»/u', $messages)) === 1);
$check('дубль слага в файле — ошибка', count(preg_grep('/уже есть выше в файле/u', $messages)) === 1);
$check('строка с ошибкой всё равно в плане разбора (админ видит её номер)', count($bad['rows']) === 4);

echo "\n== план: добавится / обновится / без изменений ==\n\n";

$rows = Service_Nj646_Import::parse(
    "slug;name_ru;name_int;kind;risk;verdict;category;e_code;status;summary;synonyms;avoid\n"
    ."novel;Новинка базы;Novelium;both;2;neutral;прочее;;draft;Из файла;синоним-1;\n"
    ."e211;Бензоат натрия;Sodium benzoate;both;4;avoid;консерванты;E211;;Стало опаснее;;аллергия\n"
    ."water;Вода;;both;0;useful;вода;;;;;\n")['rows'];

// База: e211 (риск 3) и water (полностью совпадает с файлом).
$existing = [
    'e211' => ['id' => 10, 'slug' => 'e211', 'name_ru' => 'Бензоат натрия', 'name_int' => 'Sodium benzoate',
               'kind' => 'both', 'risk' => 3, 'verdict' => 'risky', 'category' => 'консерванты',
               'e_code' => 'E211', 'status' => 'draft', 'summary' => 'Было', 'details' => 'Подробности',
               'synonyms' => ['бензоат натрия'], 'avoid' => ['детям'], 'sources' => []],
    'water' => ['id' => 11, 'slug' => 'water', 'name_ru' => 'Вода', 'name_int' => '', 'kind' => 'both',
                'risk' => 0, 'verdict' => 'useful', 'category' => 'вода', 'e_code' => '', 'status' => 'published',
                'summary' => '', 'details' => '', 'synonyms' => [], 'avoid' => [], 'sources' => []],
];

$plan = Service_Nj646_Import::plan($rows, $existing);

echo Service_Nj646_Import::describe($plan)."\n\n";

$check('одна новая карточка', count($plan['create']) === 1 AND ($plan['create'][0]['slug'] ?? '') === 'novel');
$check('одна обновляется', count($plan['update']) === 1 AND ($plan['update'][0]['slug'] ?? '') === 'e211');
$check('одна без изменений', count($plan['same']) === 1 AND ($plan['same'][0]['slug'] ?? '') === 'water');
$check('описание одной строкой', Service_Nj646_Import::describe($plan) === 'добавится: 1, обновится: 1, без изменений: 1, с ошибками: 0');

$changed = (array) ($plan['update'][0]['changed'] ?? []);
$apply   = (array) ($plan['update'][0]['apply'] ?? []);

$check('риск в «было → станет»: 3 → 4', ($changed['risk'] ?? NULL) === ['3', '4']);
$check('вердикт в «было → станет»: risky → avoid', ($changed['verdict'] ?? NULL) === ['risky', 'avoid']);
$check('пустое поле из файла НЕ стирает карточку',
    array_key_exists('status', $apply) === FALSE AND array_key_exists('category', $apply) === FALSE);
$check('в запись уходят только изменённые поля',
    array_keys($apply) === array_keys($changed) AND isset($apply['summary']));
$check('«без изменений» ничего не пишет', ($plan['same'][0]['slug'] ?? '') === 'water');

echo "\n== слаг занят: новая строка уезжает в -2, а не затирает карточку ==\n\n";

$twins = Service_Nj646_Import::parse(
    "slug;name_ru;risk\n"
    .";Сахар тростниковый;1\n"
    .";Сахар тростниковый;2\n")['rows'];

$plan2 = Service_Nj646_Import::plan($twins, []);

$check('первая — sahar-trostnikovyy', ($plan2['create'][0]['slug'] ?? '') === 'sahar-trostnikovyy');
$check('вторая — с номером, обе сохранены', ($plan2['create'][1]['slug'] ?? '') === 'sahar-trostnikovyy-2');

$plan3 = Service_Nj646_Import::plan($twins, ['sahar-trostnikovyy' => ['id' => 5, 'slug' => 'sahar-trostnikovyy', 'name_ru' => 'Сахар тростниковый', 'risk' => 3]]);

$check('занятый слаг в базе — обновление одной карточки, а не вторая',
    count($plan3['create']) === 0 AND count($plan3['update']) === 1);
$check('вторая строка об той же карточке — ошибка плана, а не тихая перезапись',
    count($plan3['errors']) === 1 AND $plan3['counts']['errors'] === 1);
$check('в ошибке назван виновник: строка и карточка',
    strpos((string) $plan3['errors'][0]['message'], 'уже разбирается строкой 2') !== FALSE);

echo "\n== файл без шапки: порядок колонок выгрузки ==\n\n";

$nohead = Service_Nj646_Import::parse("e951;Аспартам;Aspartame;food;4;avoid;сахарозаменители;E951;draft;source;90;0;Сладкий;аспартам;диабет\n");

$check('шапки нет — читаем по порядку выгрузки', $nohead['header'] === FALSE AND $nohead['total'] === 1);
$check('колонки разложены верно',
    ($nohead['rows'][0]['name_ru'] ?? '') === 'Аспартам'
    AND (int) ($nohead['rows'][0]['confidence'] ?? 0) === 90
    AND ($nohead['rows'][0]['summary'] ?? '') === 'Сладкий'
    AND ($nohead['rows'][0]['avoid'] ?? []) === ['диабет']);

echo "\n== вьюха страницы импорта ==\n\n";

$plan_view = $plan;
$plan_view['status'] = 'draft';
$plan_view['file']   = 'slovar.csv';
$plan_view['errors'] = [['line' => 7, 'name' => 'Ромашка', 'message' => 'Риск 9 — допустимо 0–4.']];
$plan_view['counts']['errors'] = 1;

$html = (string) View::factory('646/admin_import', [
    'plan' => $plan_view,
    'csv' => '', 'template' => Service_Nj646_Import::template(),
    'total' => 117, 'csrf' => 'token', 'flash' => 'Проверено: '.Service_Nj646_Import::describe($plan).'.',
    'flash_kind' => 'ok',
]);

foreach ([
    'заголовок страницы'      => 'Импорт словаря веществ',
    'сколько карточек сейчас' => 'Сейчас в базе 117 карточек',
    'план показан'            => 'План импорта',
    'корзины числами'         => 'добавится: 1',
    'ошибки отдельным блоком' => 'Не пройдут проверку: 1',
    'текст ошибки'            => 'Риск 9 — допустимо 0–4.',
    'применить план'          => 'применить план',
    'отменить'                => 'отменить',
    '«было → станет»'         => 'риск:',
    'форма другого файла'     => 'Проверить другой файл',
    'шаблон в подсказке'      => 'e211;Бензоат натрия;Sodium benzoate',
] as $what => $needle)
{
    $check('вьюха: '.$what, mb_strpos($html, $needle) !== FALSE);
}

$check('вьюха: без полос-метров',
    mb_strpos($html, 'overflow-hidden rounded-full bg-') === FALSE AND mb_strpos($html, 'style="width') === FALSE);

// Исправление опечатки: вставленный текст возвращается в поле, а не пропадает.
$again = (string) View::factory('646/admin_import', [
    'plan' => [], 'csv' => "e211;Бензоат натрия;both;7\n", 'template' => Service_Nj646_Import::template(),
    'total' => 117, 'csrf' => 'token', 'flash' => '', 'flash_kind' => 'ok',
]);

$check('вставленный текст возвращается в поле (правка опечатки на месте)',
    mb_strpos($again, 'e211;Бензоат натрия;both;7') !== FALSE);

$empty = (string) View::factory('646/admin_import', [
    'plan' => [], 'csv' => '', 'errors' => [], 'template' => Service_Nj646_Import::template(),
    'total' => 117, 'csrf' => 'token', 'flash' => '', 'flash_kind' => 'ok',
]);

$check('пустое состояние: форма загрузки и объяснение',
    mb_strpos($empty, 'Загрузить словарь') !== FALSE
    AND mb_strpos($empty, 'Пока не нажато «Применить», в базу не записывается ничего') !== FALSE);

// Режим «показать глазами»: N2J_STAND_OUT=1 — печатаем готовые страницы
// маркерами (<!--n2j:file=…-->), а обёртка кладёт их в файлы: прямо в
// виртуальную ФС писать бесполезно, она живёт только внутри прогона.
// CSS — настоящий, из public_html/assets.
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
        'plan.html' => ['План импорта словаря — разбор состава', $html],
        'empty.html' => ['Импорт словаря веществ — разбор состава', $empty],
    ] as $file => $one)
    {
        echo "\n<!--n2j:file=".$file."-->\n".$page($one[0], $one[1])."\n<!--n2j:end-->\n";
    }
}

echo "\n".($fail ? "ПРОВАЛОВ: $fail" : 'провалов: 0')."\n";
