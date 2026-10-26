<?php
/**
 * Дымовой стенд nj593_trends (PHP-in-WASM): рендер вьюх и разбор адреса формы.
 *
 * Повод (владелец, 15.10.2026): «/trends — пускай будет перечень обзоров хорошо
 * оформленный, а страницу — форму для нового обзора сделай другую». Здесь
 * проверяется именно это разделение:
 *   * /trends рендерится БЕЗ формы сборки (её поля на списке не появляются),
 *     но с карточками обзоров, поиском, пагинацией и кнопкой на форму;
 *   * /trends/new рендерит форму с параметрами, подстановкой из адреса и
 *     экранированием (фокус с HTML не исполняется);
 *   * страница обзора ведёт «Собрать заново» на форму с прежними настройками;
 *   * маршрут trends/new объявлен ВЫШЕ trends/<code> (иначе «new» — код обзора);
 *   * контроллер проверяет параметры адреса (границы) и пускает только администратора.
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs /tmp/phpwasm/nj593_views_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

class Kohana_Config_Group extends ArrayObject
{
	public function as_array() { return (array) $this; }
	public function get($key, $default = NULL) { $a = (array) $this; return array_key_exists($key, $a) ? $a[$key] : $default; }
}

class Config
{
	public function load($name)
	{
		foreach (['/app/application/modules/nj593_trends/config/'.$name.'.php', '/app/application/config/'.$name.'.php'] as $file)
		{
			if (is_file($file)) { $data = require $file; return new Kohana_Config_Group(is_array($data) ? $data : []); }
		}

		return new Kohana_Config_Group([]);
	}
}

class Kohana
{
	public static $config;
	public static $log;
	public static $charset = 'UTF-8';

	public static function find_file($dir, $file, $ext = 'php')
	{
		if (isset($file[0]) AND $file[0] === '/')
		{
			return is_file($file) ? $file : FALSE;
		}

		$rel = trim($dir, '/').'/'.$file.'.'.$ext;

		foreach ([
			'/app/application/modules/nj593_trends/'.$rel,   // вьюхи модуля (593/*)
			'/app/application/'.$rel,                        // общие partials (partials/pagination)
		] as $path)
		{
			if (is_file($path)) { return $path; }
		}

		return FALSE;
	}
}

Kohana::$config = new Config;
Kohana::$log = new class { public function add($l, $m, $v = []) { } };

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

class URL
{
	public static function site($uri = '', $protocol = FALSE)
	{
		return ($protocol ? 'https://n2j.ru/' : '/').ltrim((string) $uri, '/');
	}
}

require '/app/system/classes/Kohana/View.php';

/** Запись вызовов View::factory — проверяем, какую вьюху и с чем зовёт контроллер. */
class Nj593_View_Calls { public static $calls = []; }

class View extends Kohana_View
{
	public static function factory($file = NULL, array $data = NULL)
	{
		Nj593_View_Calls::$calls[] = [$file, $data];

		return parent::factory($file, $data);
	}
}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj593_trends/classes/Service/Nj593/Trends.php';

class Nj593_Result
{
	public $rows;
	public function __construct($rows) { $this->rows = $rows; }
	public function as_array() { return $this->rows; }
	public function current() { return $this->rows ? $this->rows[0] : FALSE; }
}

class Database
{
	const SELECT = 0;
	const INSERT = 1;
	const UPDATE = 2;
	const DELETE = 3;
	public static function instance() { throw new RuntimeException('База в стенде не нужна'); }
}

class DB
{
	public static function query($type = NULL, $sql = '', $as_object = FALSE)
	{
		return new class { public function execute() { return 1; } };
	}
}

$ok = 0;
$bad = 0;

function check($label, $got, $want)
{
	global $ok, $bad;

	if ($got === $want)
	{
		$ok++;
		echo "ok   $label\n";

		return;
	}

	$bad++;
	echo "ФЕЙЛ $label: получили ".var_export($got, TRUE).", ждали ".var_export($want, TRUE)."\n";
}

function has($label, $haystack, $needle)
{
	global $ok, $bad;

	if (strpos((string) $haystack, $needle) !== FALSE)
	{
		$ok++;
		echo "ok   $label\n";

		return;
	}

	$bad++;
	echo "ФЕЙЛ $label (нет «".mb_substr((string) $needle, 0, 70)."»)\n";
}

function has_not($label, $haystack, $needle)
{
	global $ok, $bad;

	if (strpos((string) $haystack, $needle) === FALSE)
	{
		$ok++;
		echo "ok   $label\n";

		return;
	}

	$bad++;
	echo "ФЕЙЛ $label (лишнее «".mb_substr((string) $needle, 0, 70)."»)\n";
}

function render($file, array $data)
{
	global $ok, $bad;

	try
	{
		$view = new View($file);
		$view->set($data);
		$out = $view->render();
		check('вьюха '.basename($file).' рендерится', strlen($out) > 200, TRUE);

		return $out;
	}
	catch (Throwable $e)
	{
		$bad++;
		echo "ФЕЙЛ ".basename($file).": ".$e->getMessage()." @ строка ".$e->getLine()."\n";

		return '';
	}
}

// Предупреждения PHP (undefined-переменные, null в строковые функции) — падаем
// сразу: именно так ловятся вьюхи, которые «почти работают».
set_error_handler(function ($code, $message) {
	throw new RuntimeException($message);
}, E_WARNING | E_NOTICE);

$V = '/app/application/modules/nj593_trends/views/593/';
$csrf = 'tok-en';

// ------------------------------------------------------------------ данные
function review_row(array $over = [])
{
	return $over + [
		'id' => 12, 'code' => 'trda1b2c3', 'user_id' => 7, 'rdate' => '2026-10-15',
		'period_days' => 1, 'min_stars' => 50, 'language' => 'rust', 'item_count' => 8,
		'focus' => 'инструменты для LLM-агентов', 'status' => 'done', 'error' => NULL,
		'progress' => '', 'title' => 'Обзор трендов за 15 октября',
		'summary' => "Что нового в open source: свежие модели, фреймворки и утилиты.\nВторая строка лида не нужна.",
		'takeaways' => '- взять в работу', 'model' => 'x', 'model_used' => 'x',
		'job_id' => 5, 'created_at' => '2026-10-15 08:12:00', 'updated_at' => '2026-10-15 08:16:00',
		'finished_at' => '2026-10-15 08:16:00', 'published' => TRUE, 'items_ready' => 5,
	];
}

// ------------------------------------------------- 1. /trends — каталог обзоров
echo "== /trends: открытый каталог опубликованных обзоров (главная модуля) ==\n";

$catalog = render($V.'index.php', [
	'rows' => [
		review_row(),
		review_row(['id' => 11, 'code' => 'trd99887766', 'title' => 'Свежий обзор', 'language' => '', 'period_days' => 3,
			'top_names' => 'org/one, org/two, org/three', 'items_ready' => 9]),
	],
	'q' => '',
	'page' => 1,
	'pages' => 2,
	'total' => 14,
	'per' => 10,
	'per_options' => [10, 20, 50, 100],
	'is_admin' => TRUE,
	'flash' => '',
	'mine_url' => '/trends/mine',
]);

has('каталог: заголовок страницы', $catalog, '<h1 class="n2j-h1">Обзоры трендов GitHub</h1>');
has('каталог: счётчик опубликованных', $catalog, 'опубликовано: 14');
has('каталог: кнопка на форму нового обзора', $catalog, 'href="/trends/new"');
has('каталог: кнопка зовёт собрать обзор', $catalog, 'Собрать обзор');
has_not('каталог: формы сборки нет', $catalog, '<form method="post" action="/trends/new"');
has_not('каталог: поля «Позиций в обзоре» нет', $catalog, 'name="count"');
has_not('каталог: поля «Что считать интересным» нет', $catalog, 'name="focus"');
has('каталог: поиск по обзорам на месте', $catalog, 'name="q"');
has('каталог: поиск отправляется на /trends', $catalog, '<form method="get" action="/trends"');
has('каталог: карточка с датой', $catalog, '<time datetime="2026-10-15">15.10.2026</time>');
has('каталог: период словами', $catalog, 'за 3 дня');
has('каталог: настройка обзора в карточке', $catalog, 'от 50 звёзд');
has('каталог: код обзора — ссылка', $catalog, 'href="/trends/trda1b2c3"');
has('каталог: позиции внутри обзора названы', $catalog, 'org/one, org/two, org/three');
has('каталог: счётчик позиций словами', $catalog, '9 позиций в обзоре');
has('каталог: лид обзора в карточке', $catalog, 'свежие модели, фреймворки и утилиты');
has('каталог: действие «Читать обзор»', $catalog, 'Читать обзор');
has('каталог: пагинация общая', $catalog, 'Показано <strong class="font-semibold text-gray-700 dark:text-gray-200">1–10</strong> из');
has('каталог: «Мои обзоры» ведут на свой адрес', $catalog, 'href="/trends/mine"');
has_not('каталог: формы снятия с публикации нет', $catalog, '/publish');
has_not('каталог: корзины удаления нет', $catalog, '/delete');

$catalog_search = render($V.'index.php', [
	'rows' => [], 'q' => 'qwen', 'page' => 1, 'pages' => 1, 'total' => 0, 'per' => 10, 'per_options' => [10, 20, 50],
	'is_admin' => TRUE, 'flash' => '', 'mine_url' => '/trends/mine',
]);
has('каталог: поиск без находок объясняет', $catalog_search, '>qwen</span>» опубликованных обзоров нет');
has('каталог: в поиске без находок есть сброс', $catalog_search, 'href="/trends"');
has_not('каталог: пагинации без находок нет', $catalog_search, 'Показано <strong');

$catalog_empty = render($V.'index.php', [
	'rows' => [], 'q' => '', 'page' => 1, 'pages' => 1, 'total' => 0, 'per' => 10, 'per_options' => [10, 20, 50],
	'is_admin' => TRUE, 'flash' => '', 'mine_url' => '/trends/mine',
]);
has('каталог пуст: зовёт собрать обзор', $catalog_empty, 'Опубликованных обзоров пока нет');
has('каталог пуст: ведёт на форму', $catalog_empty, 'href="/trends/new"');

$catalog_anon = render($V.'index.php', [
	'rows' => [review_row()], 'q' => '', 'page' => 1, 'pages' => 1, 'total' => 1, 'per' => 10, 'per_options' => [10, 20, 50],
	'is_admin' => FALSE, 'flash' => '', 'mine_url' => '/trends/mine',
]);
has_not('каталог гостью: сборка недоступна', $catalog_anon, 'Собрать');
has_not('каталог гостью: своих обзоров не предлагает', $catalog_anon, 'href="/trends/mine"');

foreach ([FALSE, TRUE] as $logged)
{
    foreach ([[], [review_row()]] as $rows)
    {
        $reader = render($V.'index.php', [
            'rows' => $rows, 'q' => '', 'page' => 1, 'pages' => 1, 'total' => count($rows),
            'per' => 10, 'per_options' => [10, 20, 50], 'logged' => $logged, 'is_admin' => FALSE, 'flash' => '',
        ]);
        has_not('читателю: нет формы и приглашения собрать (включая пустой каталог)', $reader, '/trends/new');
        has_not('читателю: нет управления своими обзорами', $reader, '/trends/mine');
    }
}

// --------------------------------------------- 1b. /trends/mine — мои обзоры
echo "\n== /trends/mine: мои обзоры (управление своими сборками) ==\n";

$index = render($V.'mine.php', [
	'my' => [
		review_row(),
		review_row(['id' => 11, 'code' => 'trd99887766', 'status' => 'running', 'title' => 'В работе',
			'items_ready' => 2, 'published' => TRUE, 'language' => '']),
		review_row(['id' => 10, 'code' => 'trd55554444', 'status' => 'done', 'title' => 'Снятый',
			'items_ready' => 3, 'published' => FALSE]),
		review_row(['id' => 9, 'code' => 'trd33332222', 'status' => 'error', 'title' => '',
			'items_ready' => 0, 'error' => 'GitHub не дал кандидатов <за 1 день>', 'published' => TRUE]),
	],
	'latest' => ['code' => 'trda1b2c3', 'rdate' => '2026-10-15', 'title' => 'Обзор трендов за 15 октября',
		'summary' => 'Свежие модели и фреймворки.', 'items_ready' => 5, 'item_count' => 8],
	'catalog_total' => 4,
	'logged' => TRUE,
	'csrf' => $csrf,
	'flash' => 'Обзор удалён полностью',
	'q' => '',
	'page' => 1,
	'pages' => 2,
	'total' => 24,
	'per' => 20,
	'per_options' => [10, 20, 50, 100],
]);

has('мои: заголовок страницы', $index, '<h1 class="n2j-h1">Мои обзоры</h1>');
has('мои: крошки ведут в каталог', $index, 'href="/trends" class="transition hover:text-blue-600 dark:hover:text-blue-400">Тренды GitHub</a>');
has('мои: кнопка на форму нового обзора', $index, 'href="/trends/new"');
has('мои: кнопка подписана «Новый обзор»', $index, 'Новый обзор');
has_not('мои: формы сборки нет (action=/trends/new как формы)', $index, '<form method="post" action="/trends/new"');
has_not('мои: поля «Позиций в обзоре» нет', $index, 'name="count"');
has_not('мои: поля «Что считать интересным» нет', $index, 'name="focus"');
has_not('мои: селекта периода нет', $index, 'name="period"');
has('мои: поиск по обзорам на месте', $index, 'name="q"');
has('мои: поиск отправляется на свой адрес', $index, '<form method="get" action="/trends/mine"');
has('мои: карточка со статусом «готово»', $index, 'готово');
has('мои: счётчик позиций словами', $index, '5 позиций');
has('мои: настройка обзора в карточке', $index, 'от 50 звёзд');
has('мои: язык в карточке', $index, 'Rust');
has('мои: лид обзора в карточке', $index, 'свежие модели, фреймворки и утилиты');
has('мои: код обзора — ссылка', $index, 'href="/trends/trda1b2c3"');
has('мои: действия «Открыть»', $index, '>Открыть</a>');
has('мои: публикация снимается той же кнопкой (двойное нажатие)', $index, 'data-n2j-arm="Точно снять?"');
has('мои: пояснение к снятию — в подсказке кнопки', $index, 'title="Обзор исчезнет из открытого каталога');
has('мои: удаление — окно подтверждения', $index, 'data-n2j-confirm="Удалить обзор trda1b2c3 полностью?');
has('мои: снятый обзор помечен', $index, 'снят с публикации');
has('мои: причина отказа видна в списке', $index, 'GitHub не дал кандидатов &lt;за 1 день&gt;');
has('мои: у идущего обзора корзина выключена', $index, 'Обзор собирается — остановите или дождитесь');
has('мои: пагинация общая', $index, 'Показано <strong class="font-semibold text-gray-700 dark:text-gray-200">1–20</strong> из');
has('мои: дайджест каталога ведёт на /trends', $index, 'href="/trends" class="ml-auto');
has('мои: флеш показан', $index, 'Обзор удалён полностью');

$empty = render($V.'mine.php', [
	'my' => [], 'latest' => NULL, 'catalog_total' => 0, 'logged' => TRUE, 'csrf' => $csrf,
	'flash' => '', 'q' => '', 'page' => 1, 'pages' => 1, 'total' => 0, 'per' => 20, 'per_options' => [10, 20, 50, 100],
]);
has('мои пусто: зовёт собрать первый обзор', $empty, 'Собрать первый обзор');
has('мои пусто: ведёт на форму', $empty, 'href="/trends/new"');
has_not('мои пусто: пагинации нет', $empty, 'Показано');

$search_empty = render($V.'mine.php', [
	'my' => [], 'latest' => NULL, 'catalog_total' => 0, 'logged' => TRUE, 'csrf' => $csrf,
	'flash' => '', 'q' => 'qwen', 'page' => 1, 'pages' => 1, 'total' => 0, 'per' => 20, 'per_options' => [10, 20, 50, 100],
]);
has('мои: поиск без находок: запрос экранирован и показан', $search_empty, '>qwen</span>» ничего не найдено');
has('мои: поиск без находок: есть сброс', $search_empty, 'Показать все обзоры');

$anon = render($V.'mine.php', [
	'my' => [], 'latest' => NULL, 'catalog_total' => 3, 'logged' => FALSE, 'csrf' => $csrf,
	'flash' => '', 'q' => '', 'page' => 1, 'pages' => 1, 'total' => 0, 'per' => 20, 'per_options' => [10, 20, 50, 100],
]);
has('мои гостью: кнопка ведёт на вход с возвратом на форму', $anon, 'href="/auth/login?return_url=trends/new"');
has('мои гостью: видит приглашение войти', $anon, 'Войдите, чтобы видеть свои обзоры');
has_not('мои гостью: поиска по обзорам нет', $anon, 'name="q"');

// ------------------------------------------- 2. /trends/new — форма сборки
echo "\n== /trends/new: форма нового обзора ==\n";

$form = ['rdate' => '2026-10-15', 'period' => 7, 'count' => 12, 'min_stars' => 10, 'language' => 'rust', 'focus' => 'инструменты для LLM-агентов'];
$new = render($V.'new.php', [
	'form' => $form,
	'languages' => ['', 'python', 'typescript', 'javascript', 'rust', 'go'],
	'logged' => TRUE,
	'csrf' => $csrf,
	'flash' => 'Отказано: неверная дата',
]);

has('форма: POST на свой адрес', $new, '<form method="post" action="/trends/new"');
has('форма: крошки ведут в список', $new, 'href="/trends" class="transition hover:text-blue-600 dark:hover:text-blue-400">Тренды GitHub</a>');
has('форма: дата подставлена', $new, 'name="rdate" type="date" value="2026-10-15"');
has('форма: период подставлен', $new, '<option value="7" selected>последнюю неделю</option>');
has('форма: число позиций подставлено', $new, '<option value="12" selected>12</option>');
has('форма: звёзды подставлены', $new, 'name="min_stars" type="number" min="10" max="100000" step="10" value="10"');
has('форма: язык подставлен', $new, '<option value="rust" selected>rust</option>');
has('форма: фокус подставлен в поле', $new, '>инструменты для LLM-агентов</textarea>');
has('форма: csrf на месте', $new, 'name="csrf" value="tok-en"');
has('форма: главная кнопка одна', $new, 'Собрать обзор');
has('форма: объяснено, что получится', $new, 'Что получится');
has('форма: флеш прошлой попытки виден', $new, 'Отказано: неверная дата');
has_not('форма: в списки не уехал блок «Мои обзоры»', $new, 'id="my"');

$lang_any = render($V.'new.php', [
	'form' => ['rdate' => '2026-10-20', 'period' => 1, 'count' => 8, 'min_stars' => 50, 'language' => '', 'focus' => ''],
	'languages' => ['', 'python'], 'logged' => TRUE, 'csrf' => $csrf, 'flash' => '',
]);
has('форма: «любой» язык выбран по умолчанию', $lang_any, '<option value="" selected>любой</option>');
has('форма: подсказка для фокуса на месте', $lang_any, 'placeholder="Например: инструменты для LLM-агентов, что-то для embedded, без форков"');
has_not('форма: пустой фокус не подставляет текст в поле', $lang_any, '>инструменты для LLM-агентов</textarea>');

$danger = render($V.'new.php', [
	'form' => ['rdate' => '2026-10-15', 'period' => 1, 'count' => 8, 'min_stars' => 50, 'language' => '',
		'focus' => '</textarea><script>alert("x")</script>&<b>'],
	'languages' => [''], 'logged' => TRUE, 'csrf' => $csrf, 'flash' => '',
]);
has_not('форма: HTML из адреса не исполняется', $danger, '</textarea><script>alert');
has('форма: HTML из адреса показан текстом', $danger, '&lt;/textarea&gt;&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&amp;&lt;b&gt;');

$anon_form = render($V.'new.php', [
	'form' => ['rdate' => '2026-10-15', 'period' => 1, 'count' => 8, 'min_stars' => 50, 'language' => '', 'focus' => ''],
	'languages' => [''], 'logged' => FALSE, 'csrf' => $csrf, 'flash' => '',
]);
has('форма: гостю поля выключены', $anon_form, 'name="rdate" type="date" value="2026-10-15" disabled');
has('форма: гостя отправляют на вход с возвратом', $anon_form, 'href="/auth/login?return_url=trends/new"');
has('форма: гость не может отправить', $anon_form, '<button type="submit" disabled');

// --------------------------------- 3. страница обзора → «Собрать заново»
echo "\n== страница обзора: «Собрать заново» ведёт на форму с прежними настройками ==\n";

$view = render($V.'view.php', [
	'r' => review_row(['focus' => 'LLM-агенты', 'period_days' => 3, 'item_count' => 10, 'min_stars' => 30, 'language' => 'go']),
	'items' => [[
		'id' => 1, 'review_id' => 12, 'rank' => 1, 'full_name' => 'org/repo', 'url' => 'https://github.com/org/repo',
		'description' => 'что-то', 'language' => 'Go', 'stars' => 1200, 'forks' => 40,
		'gh_created_at' => '2026-10-14 10:00:00', 'topics' => 'llm,agents', 'title' => 'Заголовок позиции',
		'what' => 'Что это', 'why' => 'Почему', 'use_for' => 'Кому', 'score' => 80, 'apply' => 'Как применить',
	]],
	'is_owner' => TRUE, 'is_admin' => TRUE, 'published' => TRUE, 'elapsed' => 0, 'ai_offline' => FALSE,
	'csrf' => $csrf, 'flash' => '',
]);

has('обзор: ссылка «Собрать заново» с периодом', $view, '/trends/new?period=3&amp;count=10&amp;min_stars=30&amp;language=go&amp;focus=LLM-%D0%B0%D0%B3%D0%B5%D0%BD%D1%82%D1%8B');

// ------------------------------------------- 4. маршруты и контроллер
echo "\n== маршруты: trends/new выше trends/<code> ==\n";

$init = file_get_contents('/app/application/modules/nj593_trends/init.php');
check('маршрут new объявлен', strpos($init, "Route::set('nj593_new', 'trends/new')") !== FALSE, TRUE);
check('маршрут new ВЫШЕ trends/<code>', strpos($init, "Route::set('nj593_new'") < strpos($init, "Route::set('nj593_view'"), TRUE);
check('маршрут mine выше trends/<code>', strpos($init, "Route::set('nj593_mine'") < strpos($init, "Route::set('nj593_view'"), TRUE);
check('старого маршрута /trends/catalog больше нет', strpos($init, "Route::set('nj593_catalog'") === FALSE, TRUE);
check('маршрут publish/delete выше', strpos($init, "Route::set('nj593_delete'") < strpos($init, "Route::set('nj593_view'"), TRUE);

echo "\n== контроллер: разбор адреса формы и защита ==\n";

class Log { const WARNING = 4; }
class Log_Plain
{
    public static $events = [];
    public static function event($level, $message, array $values = NULL) { self::$events[] = $message; }
}

class HTTP_Exception
{
	public static function factory($code, $message) { return new RuntimeException($message, $code); }
}

class Auth
{
	private static $i = NULL;
	public static $user = NULL;
	public static $admin = FALSE;
	public static function instance() { return self::$i ?: self::$i = new self; }
	public function logged_in($role = NULL) { return $role === 'admin' ? self::$admin : self::$user !== NULL; }
	public function get_user() { return self::$user; }
}

class Security
{
	public static $token = 'tok-en';
	public static $ok = TRUE;
	public static function token() { return self::$token; }
	public static function check($t) { return self::$ok AND $t === self::$token; }
}

class Request
{
	const POST = 'POST';
	const GET = 'GET';
	public $method = self::GET;
	public function method() { return $this->method; }
	public function post($key = NULL) { return $key === NULL ? $_POST : Arr::get($_POST, $key); }
	public function query($key = NULL) { return $key === NULL ? $_GET : Arr::get($_GET, $key); }
	public function param($key) { return NULL; }
}

class Controller_Erp100_Base
{
	public $request;
	public $response;
	public $template;
	public $location = '';
	public function __construct() { $this->response = new class { public function headers($k, $v = NULL) { return $this; } }; $this->template = new stdClass; }
	public function before() {}
	public function can($ability) { return $ability === 'nj593.admin' AND Auth::$admin; }
	public function set_title($t) {}
	public function set_description($d) {}
	public function set_keywords($k) {}
	public function set_module_nav($n) {}
	public function redirect($url) { $this->location = (string) $url; }
}

require '/app/application/modules/nj593_trends/classes/Controller/Nj593/Trends.php';

class Nj593_Test_Controller extends Controller_Nj593_Trends
{
	public function __construct($get = [], $post = [], $user_id = NULL, $method = Request::GET)
	{
		parent::__construct();
		$_GET = $get;
		$_POST = $post;
		$this->_user_id = $user_id;
		$this->request = new Request;
		$this->request->method = $method;
	}
	public function defaults() { return $this->_form_defaults(); }
	public function new_get() { $this->action_new(); return $this->template->content; }
	public function create() { return $this->_create(); }
	public function index_post() { $this->request->method = Request::POST; $this->action_index(); }
}

check('адрес: плохая дата заменяется сегодняшней', Nj593_Test_Controller::class ? (new Nj593_Test_Controller(['rdate' => '15.10.2026']))->defaults()['rdate'] : '', date('Y-m-d'));

$c = new Nj593_Test_Controller(['rdate' => '2026-10-15', 'period' => '99', 'count' => '99', 'min_stars' => '1', 'language' => 'кобол']);
check('адрес: период вне списка → умолчание', $c->defaults()['period'], 1);
check('адрес: позиции ограничены максимумом', $c->defaults()['count'], 12);
check('адрес: звёзды не ниже минимума', $c->defaults()['min_stars'], 10);
check('адрес: чужой язык отбрасывается', $c->defaults()['language'], '');
check('адрес: дата в формате сохраняется', $c->defaults()['rdate'], '2026-10-15');

$long = str_repeat('я', 700);
$c = new Nj593_Test_Controller(['period' => '7', 'count' => '10', 'min_stars' => '30', 'language' => 'rust', 'focus' => $long]);
$d = $c->defaults();
check('адрес: допустимые значения проходят', [$d['period'], $d['count'], $d['min_stars'], $d['language']], [7, 10, 30, 'rust']);
check('адрес: длинный фокус обрезается до 500', mb_strlen($d['focus']), 500);

$c = new Nj593_Test_Controller(['count' => '0', 'min_stars' => '0', 'period' => '0']);
$d = $c->defaults();
check('адрес: нули заменяются умолчаниями', [$d['period'], $d['count'], $d['min_stars']], [1, 8, 50]);

Auth::$admin = TRUE;
Nj593_View_Calls::$calls = [];
$c = new Nj593_Test_Controller(['period' => '3'], [], 7);
$c->new_get();
check('GET /trends/new: рендерит вьюху формы', Nj593_View_Calls::$calls[0][0], '593/new');
check('GET /trends/new: передаёт форму', isset(Nj593_View_Calls::$calls[0][1]['form']['period']), TRUE);
check('GET /trends/new: передаёт языки', isset(Nj593_View_Calls::$calls[0][1]['languages']), TRUE);
check('GET /trends/new: передаёт признак входа', Nj593_View_Calls::$calls[0][1]['logged'], TRUE);
check('GET /trends/new: передаёт csrf', Nj593_View_Calls::$calls[0][1]['csrf'], 'tok-en');

foreach ([NULL, 7] as $uid)
{
    Auth::$admin = FALSE;
    foreach (['action_new', 'action_mine', 'create', 'index_post', 'action_publish', 'action_delete'] as $action)
    {
        foreach ([Request::GET, Request::POST] as $method)
        {
            $c = new Nj593_Test_Controller([], ['csrf' => 'tok-en'], $uid, $method);
            try { $c->$action(); check('не-администратор: '.$action.' запрещён', FALSE, TRUE); }
            catch (RuntimeException $e) { check('не-администратор: '.$action.' → 403', $e->getCode(), 403); }
        }
    }
}
check('отказ управления записан в лог', count(Log_Plain::$events) > 0, TRUE);
Auth::$admin = TRUE;

Security::$ok = FALSE;
$c = new Nj593_Test_Controller([], ['csrf' => 'чужой'], 7);
$c->create();
Security::$ok = TRUE;
check('плохой CSRF: возврат на форму с объяснением', $c->location, 'trends/new?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

$src = file_get_contents('/app/application/modules/nj593_trends/classes/Controller/Nj593/Trends.php');
check('контроллер: отказ создания возвращает на форму', strpos($src, "trends/new?flash=") !== FALSE, TRUE);
check('контроллер: POST на /trends поддержан для старых закладок', strpos($src, 'public function action_index()') !== FALSE AND substr_count($src, '$this->_create();') >= 2, TRUE);
check('контроллер: свои обзоры на action_mine', strpos($src, 'public function action_mine()') !== FALSE AND strpos($src, "View::factory('593/mine'") !== FALSE, TRUE);
check('контроллер: каталог рисует главная', strpos($src, "View::factory('593/index'") !== FALSE AND strpos($src, 'View::factory(\'593/catalog\'') === FALSE, TRUE);
check('контроллер: действия action_catalog больше нет', strpos($src, 'function action_catalog') === FALSE, TRUE);

Security::$ok = FALSE;
$c = new Nj593_Test_Controller([], ['csrf' => 'чужой'], 7, Request::POST);
$c->index_post();
Security::$ok = TRUE;
check('старый POST на /trends: отказ ведёт на форму, а не в список', $c->location, 'trends/new?flash='.urlencode('Сессия устарела — попробуйте ещё раз'));

$acl = require '/app/application/config/acl.php';
check('ACL: управление разрешено только роли admin', $acl['nj593.admin'], ['admin']);

// Единая политика страницы и API, включая старого владельца и pg bool.
foreach (['done', 'new', 'running', 'error', 'cancelled'] as $status)
{
    foreach ([TRUE, FALSE, 't', 'f'] as $published)
    {
        $r = review_row(['status' => $status, 'published' => $published]);
        check('видимость читателю: '.$status.'/'.var_export($published, TRUE),
            Service_Nj593_Trends::can_read($r), $status === 'done' AND in_array($published, [TRUE, 't'], TRUE));
        check('администратор видит любую сборку', Service_Nj593_Trends::can_read($r, TRUE), TRUE);
    }
}
$reader_view = render($V.'view.php', [
    'r' => review_row(), 'items' => [], 'is_owner' => TRUE, 'is_admin' => FALSE,
    'published' => TRUE, 'elapsed' => 0, 'ai_offline' => FALSE, 'csrf' => $csrf, 'flash' => '',
]);
foreach (['/trends/new', '/publish', '/delete', 'data-csrf="tok-en"'] as $control)
{
    has_not('прежнему владельцу: нет управления '.$control, $reader_view, $control);
}

// Исполняем реальные контроллеры с подменой только БД, без сайта и очереди.
class Nj593_Access_DB
{
    public $row;
    public function quote($v) { return "'".str_replace("'", "''", $v)."'"; }
    public function query($type, $sql, $as_object = FALSE)
    {
        return new Nj593_Result((strpos($sql, 'SELECT * FROM nj_593_reviews') !== FALSE AND $this->row) ? [$this->row] : []);
    }
}
$db = new Nj593_Access_DB;
$prop = new ReflectionProperty(Service_Nj593_Trends::class, '_db');
$prop->setValue(NULL, $db);
class Nj593_Access_Response
{
    public $code = 200;
    public $text = '';
    public function headers($k, $v = NULL) { return $this; }
    public function status($code) { $this->code = $code; return $this; }
    public function body($text) { $this->text = $text; return $this; }
}
require '/app/application/modules/nj593_trends/classes/Controller/Nj593/Api.php';
foreach ([NULL, 7] as $uid)
{
    Auth::$admin = FALSE;
    Auth::$user = $uid === NULL ? NULL : (object) ['id' => $uid];
    foreach ([TRUE, FALSE] as $published)
    {
        foreach (['done', 'new', 'running', 'error'] as $status)
        {
            $db->row = review_row(['published' => $published, 'status' => $status, 'job_id' => NULL]);
            $c = new Nj593_Test_Controller([], [], $uid);
            try { $c->action_view(); $http = 200; }
            catch (RuntimeException $e) { $http = $e->getCode(); }
            $expected = ($status === 'done' AND $published);
            check('страница: чтение только опубликованного готового обзора', $http, $expected ? 200 : 404);
            $api = new Controller_Nj593_Api;
            $api->request = new Request;
            $api->response = new Nj593_Access_Response;
            $api->action_status();
            check('API: та же видимость, что у страницы', $api->response->code, $expected ? 200 : 404);
        }
    }
    $api->response = new Nj593_Access_Response;
    $api->action_cancel();
    check('API: читатель не может остановить сборку', $api->response->code, 403);
}
Auth::$admin = TRUE;
$db->row = review_row(['published' => FALSE, 'job_id' => NULL]);
$c = new Nj593_Test_Controller([], [], 7);
$c->action_view();
check('администратор: доступ к снятому обзору', $c->template->content instanceof View, TRUE);
$api->response = new Nj593_Access_Response;
$api->action_status();
check('администратор: API снятого обзора доступен', $api->response->code, 200);

restore_error_handler();

echo "\nИТОГ NJ-593 (вьюхи и маршруты): $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
