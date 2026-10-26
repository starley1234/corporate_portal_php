<?php
/**
 * Стенд nj667_graph3d («Графовый 3D-конструктор»): чистые функции сервиса
 * и рендер страниц views/nj667/.
 *
 *   N2J_ROOT=$(pwd) node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj667_graph3d_stand.php
 *
 * Проверяется то, что нельзя увидеть глазами и что легче всего сломать правкой
 * «по мелочи»:
 *   - graph_clean: модель прислала мусор (ребро без конца, дубли id, «x» в
 *     виде строки, координаты за пределами сцены) — граф всё равно годный;
 *   - graph_validate (AI-Guard): пустой граф, узлы без связей, превышение
 *     лимитов, связи без узлов;
 *   - RFC 6902: diff → apply даёт тот же граф; патч от воркера с левым
 *     путём не роняет проект, а возвращает понятную ошибку;
 *   - merge (инпейнтинг): новые узлы приклеиваются по id, существующие
 *     заменяются, а не дублируются;
 *   - окрестность узла: в модель уезжает выбранное и соседи, а не весь граф;
 *   - топологический отпечаток: одинаковая конструкция с другими подписями
 *     даёт тот же хеш;
 *   - SVG-превью считается без модели и без БД.
 *
 * Сети и БД в стенде нет: всё, что до них дотягивается, обязано деградировать,
 * а не падать.
 */

// Корень репозитория: в php-wasm файлы лежат в /app, на обычном PHP —
// на уровень выше .tools/. Так стенд гоняется и в CI (`php .tools/...`),
// и в песочнице без PHP CLI.
if ( ! defined('N2J_STAND_ROOT'))
{
	define('N2J_STAND_ROOT', is_dir('/app/application') ? '/app' : dirname(__DIR__));
}


if ( ! defined('SYSPATH'))
{
	define('SYSPATH', N2J_STAND_ROOT.'/system/');
}

if ( ! defined('DOCROOT'))
{
	define('DOCROOT', N2J_STAND_ROOT.'/public_html/');
}

// ---------------------------------------------------------------- заглушки

class Kohana_Config_Group extends ArrayObject
{
	public function as_array() { return (array) $this; }

	public function get($key, $default = NULL)
	{
		$array = (array) $this;

		return array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

class Config
{
	public function load($name)
	{
		foreach ([
			N2J_STAND_ROOT.'/application/modules/nj667_graph3d/config/'.$name.'.php',
			N2J_STAND_ROOT.'/application/config/'.$name.'.php',
		] as $path)
		{
			if (is_file($path))
			{
				$data = require $path;

				return new Kohana_Config_Group(is_array($data) ? $data : []);
			}
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
		$path = N2J_STAND_ROOT.'/application/modules/nj667_graph3d/'.trim($dir, '/').'/'.$file.'.'.$ext;

		if (is_file($path)) { return $path; }

		$path = N2J_STAND_ROOT.'/application/'.trim($dir, '/').'/'.$file.'.'.$ext;

		return is_file($path) ? $path : FALSE;
	}
}

Kohana::$config = new Config;
Kohana::$log    = new class { public function add($l, $m, $v = []) { } };

class Log
{
	const WARNING = 3;
	const ERROR   = 4;
}

class Log_Plain
{
	public static $events = [];

	public static function event($level, $message, array $values = NULL, array $additional = NULL)
	{
		self::$events[] = [$level, $message];
	}
}

class UTF8
{
	public static function substr($str, $offset, $length = NULL)
	{
		return $length === NULL ? mb_substr($str, $offset) : mb_substr($str, $offset, $length);
	}

	public static function strlen($str) { return mb_strlen($str); }

	public static function strpos($haystack, $needle, $offset = 0)
	{
		return mb_strpos($haystack, $needle, $offset);
	}

	public static function strrpos($haystack, $needle, $offset = 0)
	{
		return mb_strrpos($haystack, $needle, $offset);
	}
}

class Database
{
	const SELECT = 1;
	const INSERT = 2;

	public static function instance() { return new Database; }

	public function quote($value)
	{
		return "'".str_replace("'", "''", (string) $value)."'";
	}

	/** БД в стенде нет: всё, что до неё дотягивается, должно это пережить. */
	public function query($type, $sql, $as_object = NULL)
	{
		throw new Exception('БД недоступна');
	}
}

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

class Visitor
{
	public static function uid() { return 'stand-visitor'; }
}

/** Дата и время: в системе это хелпер с часовым поясом, в стенде — date(). */
class Date
{
	public static function formatted_time($time = NULL, $format = NULL)
	{
		$ts = is_numeric($time) ? (int) $time : strtotime((string) $time);

		return date($format ?: 'd.m.Y H:i', $ts ?: time());
	}
}

require N2J_STAND_ROOT.'/system/classes/Kohana/Arr.php';
require N2J_STAND_ROOT.'/system/classes/Arr.php';

class Security
{
	public static function token($new = FALSE) { return 'stand-csrf-token'; }

	public static function check($token) { return $token === 'stand-csrf-token'; }
}

class Gate
{
	public static function wall($return_url = '', array $options = [])
	{
		return '<div class="nj-wall">'.HTML::chars((string) Arr::get($options, 'title', 'Нужен аккаунт')).'</div>';
	}
}

require N2J_STAND_ROOT.'/system/classes/Kohana/View.php';

class View extends Kohana_View {}

require N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph.php';
require N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Drawing.php';
require N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Build.php';
require N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php';
require N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Storage.php';

// ---------------------------------------------------------------- проверки
$ok  = 0;
$bad = 0;

function check($label, $got, $want)
{
	global $ok, $bad;
	$g = is_bool($got) ? ($got ? 'true' : 'false') : (is_array($got) ? json_encode($got, JSON_UNESCAPED_UNICODE) : (string) $got);
	$w = is_bool($want) ? ($want ? 'true' : 'false') : (is_array($want) ? json_encode($want, JSON_UNESCAPED_UNICODE) : (string) $want);

	if ($g === $w)
	{
		$ok++;
		echo "ok   $label\n";
	}
	else
	{
		$bad++;
		echo "ФЕЙЛ $label\n     получили: $g\n     ожидалось: $w\n";
	}
}

function check_true($label, $condition)
{
	check($label, $condition ? 'true' : 'false', 'true');
}

/** Граф для стенда: велосипед, если не сказано иначе. */
function nj667_graph(array $opt = [])
{
	$graph = [
		'meta'  => ['title' => 'Трёхколёсный велосипед', 'units' => 'unit'],
		'nodes' => [
			['id' => 'n1', 'type' => 'hub',       'x' => 0,   'y' => 0,    'z' => 0,  'label' => 'Рама'],
			['id' => 'n2', 'type' => 'endpoint',  'x' => -6,  'y' => -8,   'z' => 4,  'label' => 'Заднее колесо левое'],
			['id' => 'n3', 'type' => 'endpoint',  'x' => 6,   'y' => -8,   'z' => 4,  'label' => 'Заднее колесо правое'],
			['id' => 'n4', 'type' => 'endpoint',  'x' => 0,   'y' => -8,   'z' => -6, 'label' => 'Переднее колесо'],
			['id' => 'n5', 'type' => 'panel',     'x' => 0,   'y' => 4,    'z' => 2,  'label' => 'Сиденье'],
			['id' => 'n6', 'type' => 'endpoint',  'x' => 0,   'y' => 8,    'z' => -5, 'label' => 'Руль'],
			['id' => 'n7', 'type' => 'connector', 'x' => 0,   'y' => 6,    'z' => 7,  'label' => 'Корзина'],
		],
		'edges' => [
			['id' => 'e1', 'source' => 'n1', 'target' => 'n2', 'type' => 'structural', 'label' => 'ось'],
			['id' => 'e2', 'source' => 'n1', 'target' => 'n3', 'type' => 'structural', 'label' => 'ось'],
			['id' => 'e3', 'source' => 'n1', 'target' => 'n4', 'type' => 'rigid',      'label' => 'вилка'],
			['id' => 'e4', 'source' => 'n1', 'target' => 'n5', 'type' => 'rigid',      'label' => 'подседел'],
			['id' => 'e5', 'source' => 'n1', 'target' => 'n6', 'type' => 'hinge',      'label' => 'колонка'],
			['id' => 'e6', 'source' => 'n1', 'target' => 'n7', 'type' => 'rigid',      'label' => 'крепление'],
		],
	];

	return $opt + $graph;
}

// --- локальные шаблоны: создание без LLM -------------------------------------
$quota_limits = Service_Nj667_Graph3d::quota_limits(42);
check('квоты: пять LLM-операций в сутки', (int) $quota_limits['llm_operations_per_day'], 5);
check('квоты: пять активных проектов', (int) $quota_limits['active_projects'], 5);
check('квоты: три исторические ревизии', (int) $quota_limits['historical_revisions'], 3);
check('квоты: до 30 узлов за запуск', (int) $quota_limits['build_parts_per_run'], 30);
$quota_state = Service_Nj667_Graph3d::llm_quota_state(4, 5);
check_true('квоты: пятая операция разрешена', $quota_state['allowed']);
check('квоты: после четвёртой остаётся одна', $quota_state['remaining'], 1);
$quota_state = Service_Nj667_Graph3d::llm_quota_state(5, 5);
check_true('квоты: шестая операция запрещена', ! $quota_state['allowed']);
check('квоты: ручные и не-графовые операции не тарифицируются',
	Service_Nj667_Graph3d::counts_toward_llm_quota(Service_Nj667_Graph3d::CH_SUMMARY), FALSE);
check_true('квоты: генерация графа тарифицируется',
	Service_Nj667_Graph3d::counts_toward_llm_quota(Service_Nj667_Graph3d::CH_GENERATE));
check_true('квоты: LLM-достройка тарифицируется',
	Service_Nj667_Graph3d::counts_toward_llm_quota(Service_Nj667_Graph3d::CH_INPAINT));

$local_templates = Service_Nj667_Graph3d::simple_templates();
check('шаблоны без LLM: количество вариантов', count($local_templates), 4);

foreach ($local_templates as $template_key => $template_info)
{
	$template_graph = Service_Nj667_Graph3d::simple_graph((string) $template_key);
	$template_guard = is_array($template_graph)
		? Service_Nj667_Graph3d::graph_validate($template_graph)
		: ['ok' => FALSE];
	$template_geom = is_array($template_graph)
		? Service_Nj667_Graph3d::build_geometry($template_graph,
			Service_Nj667_Graph3d::build_opt(['type' => 'flat', 'unit' => 'mm', 'unit_size' => 10]))
		: ['ok' => FALSE];

	check_true('шаблон '.$template_key.': граф проходит AI-Guard', Arr::get($template_guard, 'ok'));
	check_true('шаблон '.$template_key.': палочки собираются в спецификацию', Arr::get($template_geom, 'ok'));
	check('шаблон '.$template_key.': узлов по паспорту', count((array) Arr::get($template_graph, 'nodes', [])),
		(int) Arr::get($template_info, 'nodes', 0));
	check('шаблон '.$template_key.': рёбер по паспорту', count((array) Arr::get($template_graph, 'edges', [])),
		(int) Arr::get($template_info, 'edges', 0));
}

check('шаблон без LLM: неизвестный ключ отклонён', Service_Nj667_Graph3d::simple_graph('other'), NULL);

// --- graph_clean: модель прислала мусор ---------------------------------------
$dirty = [
	'meta'  => ['title' => '  Стул  ', 'units' => 'unit'],
	'nodes' => [
		['id' => 'n1', 'type' => 'hub', 'x' => '0', 'y' => 0, 'z' => 0, 'label' => 'Сиденье'],
		['id' => 'n1', 'type' => 'hub', 'x' => 5, 'y' => 0, 'z' => 0, 'label' => 'Дубль'],
		['id' => '', 'type' => 'неведомый', 'x' => 'пять', 'y' => 999, 'z' => -0.004, 'label' => ''],
	],
	'edges' => [
		['id' => 'e1', 'source' => 'n1', 'target' => 'нетакого', 'type' => 'rigid'],
		['id' => 'e2', 'source' => 'n1', 'target' => 'n1', 'type' => 'rigid'],
	],
];

$clean = Service_Nj667_Graph3d::graph_clean($dirty);

check('clean: дубль id выброшен', count($clean['nodes']), 2);
check('clean: пустой id получил имя', $clean['nodes'][1]['id'], 'n3');
check('clean: неизвестный тип → connector', $clean['nodes'][1]['type'], 'connector');
check('clean: «пять» → 0', $clean['nodes'][1]['x'], 0.0);
check('clean: 999 обрезано до радиуса', $clean['nodes'][1]['y'], 24.0);
check('clean: -0.004 округлено', $clean['nodes'][1]['z'], 0.0);
check('clean: ребро без конца выброшено', count($clean['edges']), 0);
check('clean: заголовок обрезан по краям', Arr::get($clean['meta'], 'title'), 'Стул');
check('clean: подпись узла выкинута', $clean['nodes'][0]['label'], '');

// --- graph_validate (AI-Guard) -------------------------------------------------
$guard = Service_Nj667_Graph3d::graph_validate(nj667_graph());
check_true('guard: нормальный граф проходит', Arr::get($guard, 'ok'));
check('guard: узлов 7', Arr::get(Arr::get($guard, 'stats', []), 'nodes'), 7);
check('guard: без связей нет', Arr::get(Arr::get($guard, 'stats', []), 'isolated'), 0);

$guard = Service_Nj667_Graph3d::graph_validate(Service_Nj667_Graph3d::graph_new(''));
check_true('guard: пустой граф не проходит', ! Arr::get($guard, 'ok'));

$guard = Service_Nj667_Graph3d::graph_validate(nj667_graph([
	'nodes' => [
		['id' => 'n1', 'type' => 'hub', 'x' => 0, 'y' => 0, 'z' => 0, 'label' => 'Рама'],
		['id' => 'n2', 'type' => 'endpoint', 'x' => 4, 'y' => 0, 'z' => 0, 'label' => 'Хвост'],
	],
	'edges' => [
		['id' => 'e1', 'source' => 'n1', 'target' => 'n2', 'type' => 'rigid'],
		['id' => 'e2', 'source' => 'n1', 'target' => 'n9', 'type' => 'rigid'],
	],
]));

check_true('guard: связь без узла — отказ', ! Arr::get($guard, 'ok'));
check('guard: битых связей 1', Arr::get(Arr::get($guard, 'stats', []), 'broken'), 1);

$guard = Service_Nj667_Graph3d::graph_validate([
	'nodes' => [
		['id' => 'n1', 'type' => 'hub', 'x' => 0, 'y' => 0, 'z' => 0, 'label' => 'Рама'],
		['id' => 'n2', 'type' => 'endpoint', 'x' => 4, 'y' => 0, 'z' => 0, 'label' => 'Висит'],
	],
	'edges' => [['id' => 'e1', 'source' => 'n1', 'target' => 'n1', 'type' => 'rigid']],
]);

check_true('guard: узел без связей — отказ', ! Arr::get($guard, 'ok'));
check('guard: изолированных 1', Arr::get(Arr::get($guard, 'stats', []), 'isolated'), 1);

$guard = Service_Nj667_Graph3d::graph_validate(Service_Nj667_Graph3d::graph_clean(nj667_graph()));
check_true('guard: граф без подписей проходит', Arr::get($guard, 'ok'));

// --- RFC 6902: diff → apply ------------------------------------------------------
$before = nj667_graph();
$after  = nj667_graph();
$after['nodes'][0]['y'] = 3.5;
$after['nodes'][1]['label'] = 'Заднее колесо (левое)';
array_splice($after['nodes'], 2, 1);
$after['nodes'][] = ['id' => 'n8', 'type' => 'endpoint', 'x' => 2, 'y' => -2, 'z' => -2, 'label' => 'Фара'];
$after['edges'][] = ['id' => 'e7', 'source' => 'n1', 'target' => 'n8', 'type' => 'rigid', 'label' => 'крепление'];
array_splice($after['edges'], 5, 1);
$after['meta']['title'] = 'Велосипед с фарой';

$ops = Service_Nj667_Graph3d::patch_diff($before, $after);

check_true('patch: diff не пустой', count($ops) > 0);

$applied = Service_Nj667_Graph3d::patch_apply($before, $ops);
check_true('patch: применился', Arr::get($applied, 'ok'));
check('patch: граф совпал с новым',
	json_encode(Service_Nj667_Graph3d::graph_clean(Arr::get($applied, 'graph'))),
	json_encode(Service_Nj667_Graph3d::graph_clean($after)));

check('patch: в историю попала правка координаты',
	(bool) array_filter($ops, function ($op) {
		return $op['op'] === 'replace' AND $op['path'] === '/nodes/0/y';
	}), TRUE);

// Патч от воркера с левым путём — понятная ошибка, а не падение.
$broken = Service_Nj667_Graph3d::patch_apply($before, [['op' => 'replace', 'path' => 'nodes/0/y', 'value' => 1]]);
check_true('patch: путь не по RFC — отказ', ! Arr::get($broken, 'ok'));

$broken = Service_Nj667_Graph3d::patch_apply($before, [['op' => 'remove', 'path' => '/nodes/99']]);
check_true('patch: нет такого узла — отказ', ! Arr::get($broken, 'ok'));

$broken = Service_Nj667_Graph3d::patch_apply($before, [['op' => 'copy', 'path' => '/nodes/-']]);
check_true('patch: неподдержанная операция — отказ', ! Arr::get($broken, 'ok'));

$moved = Service_Nj667_Graph3d::patch_apply($before, [['op' => 'move', 'from' => '/nodes/0', 'path' => '/nodes/-']]);
check_true('patch: move поддержан', Arr::get($moved, 'ok'));
check('patch: move — первый узел уехал в конец',
	Arr::get(Arr::get($moved, 'graph'), 'nodes')[0]['id'], 'n2');

// --- merge (инпейнтинг) ----------------------------------------------------------
$current  = nj667_graph();
$incoming = [
	'nodes' => [
		['id' => 'n6', 'type' => 'endpoint', 'x' => 0, 'y' => 9, 'z' => -7, 'label' => 'Руль-баран'],
		['id' => 'n9', 'type' => 'endpoint', 'x' => -2, 'y' => 9, 'z' => -7, 'label' => 'Ручка левая'],
	],
	'edges' => [
		['id' => 'e8', 'source' => 'n6', 'target' => 'n9', 'type' => 'rigid', 'label' => 'сварка'],
	],
];

$merged = Service_Nj667_Graph3d::graph_merge($current, $incoming);

check('merge: узлов 8', count($merged['nodes']), 8);
check('merge: связей 7', count($merged['edges']), 7);
check('merge: существующий узел заменён, а не продублирован',
	count(array_filter($merged['nodes'], function ($n) { return $n['id'] === 'n6'; })), 1);
check('merge: координата существующего узла обновлена',
	Arr::get(Arr::path($merged, ['nodes'])[5] ?: [], 'y'), 9.0);

$second = Service_Nj667_Graph3d::graph_merge($merged, $incoming);
check('merge: повторная склейка не плодит узлы', count($second['nodes']), 8);

// --- окрестность узла (в модель уезжает не весь граф) -----------------------------
$near = Service_Nj667_Graph3d::graph_neighborhood(nj667_graph(), ['n1'], 1);
check('окрестность: хаб и все его связи', count($near['nodes']), 7);

$near = Service_Nj667_Graph3d::graph_neighborhood(nj667_graph(), ['n2'], 1);
check('окрестность: колесо + рама', count($near['nodes']), 2);
check('окрестность: связь колеса с рамой', count($near['edges']), 1);

// Второй шаг от колеса идёт через раму-хаб, а у хаба связей много: так и
// задумано — окрестность растёт по связям, а не по геометрии.
$near = Service_Nj667_Graph3d::graph_neighborhood(nj667_graph(), ['n2'], 2);
check('окрестность: второй шаг через хаб дотянулся до всех', count($near['nodes']), 7);

$near = Service_Nj667_Graph3d::graph_neighborhood(nj667_graph(), [], 1);
check('окрестность: пустой выбор — пустой граф', count($near['nodes']), 0);

// --- топологический отпечаток -----------------------------------------------------
$hash_a = Service_Nj667_Graph3d::topology_hash(nj667_graph());

$renamed = nj667_graph();
$renamed['nodes'][0]['label'] = 'Рама новая';
$renamed['nodes'][0]['x'] = 1.5;

check('topo: подписи и сдвиг не меняют отпечаток',
	Service_Nj667_Graph3d::topology_hash($renamed), $hash_a);

$other = nj667_graph();
$other['edges'][] = ['id' => 'e9', 'source' => 'n2', 'target' => 'n3', 'type' => 'rigid'];

check('topo: новая связь меняет отпечаток',
	Service_Nj667_Graph3d::topology_hash($other) !== $hash_a, TRUE);
check('topo: длина 32 (md5)', UTF8::strlen(Service_Nj667_Graph3d::topology_hash(nj667_graph())), 32);

check('topo-search: пустой хеш не ходит в базу', Service_Nj667_Graph3d::similar_by_topology(''), []);
check('topo-search: мусор не ходит в базу', Service_Nj667_Graph3d::similar_by_topology('not-an-md5'), []);

// --- few-shot: топология важнее вектора и текста ------------------------------------
$merged = Service_Nj667_Graph3d::few_shot_merge(
	[['id' => 5, 'title' => 'Стул']],
	[['id' => 6, 'title' => 'Кресло'], ['id' => 5, 'title' => 'дубль']],
	[['id' => 7, 'title' => 'Табурет']],
	2,
	0
);
check('few-shot: лимит 2', count($merged), 2);
check('few-shot: сначала топология', Arr::get($merged[0], 'id'), 5);
check('few-shot: топология помечена', Arr::get($merged[0], 'why'), 'topology');
check('few-shot: потом вектор', Arr::get($merged[1], 'id'), 6);
check('few-shot: вектор помечен', Arr::get($merged[1], 'why'), 'embed');

$merged = Service_Nj667_Graph3d::few_shot_merge(
	[['id' => 10, 'title' => 'свой']],
	[['id' => 11, 'title' => 'чужой']],
	[['id' => 12, 'title' => 'текст']],
	3,
	10
);
check('few-shot: свой id выкинут', count($merged), 2);
check('few-shot: после исключения — вектор', Arr::get($merged[0], 'id'), 11);
check('few-shot: текст на третьем месте', Arr::get($merged[1], 'id'), 12);
check('few-shot: текст помечен', Arr::get($merged[1], 'why'), 'text');

$merged = Service_Nj667_Graph3d::few_shot_merge([], [], [['id' => 0, 'title' => 'без id']], 2, 0);
check('few-shot: строка без id не попадает', count($merged), 0);

// --- replay RFC 6902 и сжатие истории ----------------------------------------------
$g0 = Service_Nj667_Graph3d::graph_new('тест');
$g1 = $g0;
$g1['nodes'][] = ['id' => 'n1', 'type' => 'hub', 'x' => 0, 'y' => 0, 'z' => 0, 'label' => 'A'];
$g1 = Service_Nj667_Graph3d::graph_clean($g1);
$g2 = $g1;
$g2['nodes'][] = ['id' => 'n2', 'type' => 'endpoint', 'x' => 2, 'y' => 0, 'z' => 0, 'label' => 'B'];
$g2['edges'][] = ['id' => 'e1', 'source' => 'n1', 'target' => 'n2', 'type' => 'rigid', 'label' => 'связь'];
$g2 = Service_Nj667_Graph3d::graph_clean($g2);

$ops1 = Service_Nj667_Graph3d::patch_diff($g0, $g1);
$ops2 = Service_Nj667_Graph3d::patch_diff($g1, $g2);
$revs = [
	['id' => 1, 'no' => 1, 'kind' => 'commit', 'patch_json' => json_encode($ops1)],
	['id' => 2, 'no' => 2, 'kind' => 'commit', 'patch_json' => json_encode($ops2)],
];

$r1 = Service_Nj667_Graph3d::replay_revisions($revs, 1, $g0);
check_true('replay: первая ревизия ок', Arr::get($r1, 'ok'));
check('replay: после №1 один узел', count(Arr::get(Arr::get($r1, 'graph'), 'nodes', [])), 1);

$r2 = Service_Nj667_Graph3d::replay_revisions($revs, 2, $g0);
check_true('replay: вторая ревизия ок', Arr::get($r2, 'ok'));
check('replay: после №2 два узла', count(Arr::get(Arr::get($r2, 'graph'), 'nodes', [])), 2);
check('replay: после №2 одно ребро', count(Arr::get(Arr::get($r2, 'graph'), 'edges', [])), 1);

$history_plan = Service_Nj667_Graph3d::history_window_plan([
	['id' => 1, 'no' => 1, 'kind' => 'commit'],
	['id' => 2, 'no' => 2, 'kind' => 'commit'],
	['id' => 3, 'no' => 3, 'kind' => 'commit'],
	['id' => 4, 'no' => 4, 'kind' => 'commit'],
	['id' => 5, 'no' => 5, 'kind' => 'commit'],
	['id' => 6, 'no' => 6, 'kind' => 'commit'],
], 4);
check('история: окно оставляет три прошлые и текущую ревизию', $history_plan['delete_ids'], [1, 2]);
check('история: граничный commit становится milestone', $history_plan['convert_id'], 3);
check('история: граничный номер сохранён', $history_plan['convert_no'], 3);
$history_plan = Service_Nj667_Graph3d::history_window_plan([
	['id' => 1, 'no' => 1, 'kind' => 'commit'],
	['id' => 2, 'no' => 2, 'kind' => 'milestone'],
	['id' => 3, 'no' => 3, 'kind' => 'commit'],
	['id' => 4, 'no' => 4, 'kind' => 'commit'],
	['id' => 5, 'no' => 5, 'kind' => 'commit'],
], 4);
check('история: существующий milestone — граница окна', $history_plan['convert_id'], 0);
check('история: старые строки до milestone удаляются', $history_plan['delete_ids'], [1]);

$plan = Service_Nj667_Graph3d::compact_plan($revs, 10);
check('compact: мало коммитов — ничего не трогаем', $plan['delete_ids'], []);
check('compact: convert_id пуст', $plan['convert_id'], 0);

$plan = Service_Nj667_Graph3d::compact_plan([
	['id' => 1, 'no' => 1, 'kind' => 'commit'],
	['id' => 2, 'no' => 2, 'kind' => 'commit'],
	['id' => 3, 'no' => 3, 'kind' => 'commit'],
	['id' => 4, 'no' => 4, 'kind' => 'commit'],
	['id' => 5, 'no' => 5, 'kind' => 'commit'],
], 2);
sort($plan['delete_ids']);
check('compact: удаляем старшие, границу оставляем под снимок', $plan['delete_ids'], [1, 2, 3]);
check('compact: последний удаляемый → milestone', $plan['convert_id'], 3);
check('compact: номер снимка', $plan['convert_no'], 3);

$plan = Service_Nj667_Graph3d::compact_plan([
	['id' => 1, 'no' => 1, 'kind' => 'commit'],
	['id' => 2, 'no' => 2, 'kind' => 'commit'],
	['id' => 3, 'no' => 3, 'kind' => 'milestone'],
	['id' => 4, 'no' => 4, 'kind' => 'commit'],
	['id' => 5, 'no' => 5, 'kind' => 'commit'],
], 2);
sort($plan['delete_ids']);
check('compact: milestone в зазоре покрывает откат — снимок не нужен', $plan['convert_id'], 0);
check('compact: удалены только старые коммиты', $plan['delete_ids'], [1, 2]);

$plan = Service_Nj667_Graph3d::compact_plan([], 2);
check('compact: пустая история', $plan['delete_ids'], []);

// --- текст графа и SVG --------------------------------------------------------------
$text = Service_Nj667_Graph3d::graph_text(nj667_graph());
check_true('текст: название на месте', strpos($text, 'Трёхколёсный велосипед') !== FALSE);
check_true('текст: узлы по id', strpos($text, 'n2') !== FALSE);
check_true('текст: связи по id', strpos($text, 'n1 — n6') !== FALSE);

$svg = Service_Nj667_Graph3d::svg_preview(nj667_graph());
check_true('svg: есть корень svg', strpos($svg, '<svg') !== FALSE);
check_true('svg: есть xml-заголовок', strpos($svg, '<?xml') === 0);
check('svg: по узлу — circle', substr_count($svg, '<circle'), 7);
check('svg: по связи — line', substr_count($svg, 'stroke-width="1.6"'), 6);
check_true('svg: изометрия', strpos($svg, 'data-view="isometric"') !== FALSE);
check_true('svg: белый фон', strpos($svg, 'fill="#ffffff"') !== FALSE);
check_true('svg: нет тёмного фона', strpos($svg, '#0f172a') === FALSE);
$svg_night = Service_Nj667_Graph3d::svg_preview(nj667_graph(), 480, 320, TRUE);
check_true('svg: ночной фон', strpos($svg_night, 'fill="#0f172a"') !== FALSE);
check_true('svg: пустой граф не роняет', strpos(Service_Nj667_Graph3d::svg_preview([]), '</svg>') !== FALSE);
$iso = Service_Nj667_Graph3d::svg_preview([
	'nodes' => [
		['id' => 'a', 'type' => 'hub', 'x' => 0, 'y' => 0,  'z' => 0],
		['id' => 'b', 'type' => 'hub', 'x' => 0, 'y' => 12, 'z' => 0],
	],
	'edges' => [['id' => 'e', 'source' => 'a', 'target' => 'b', 'type' => 'rigid']],
]);
preg_match_all('/<circle cx="([^"]+)" cy="([^"]+)"/', $iso, $iso_m);
check_true('svg: высота сдвигает изометрию', isset($iso_m[2][0], $iso_m[2][1]) AND $iso_m[2][0] !== $iso_m[2][1]);

$offers = Service_Nj667_Graph3d::models();
check_true('models: список не пуст', count($offers) > 0);
check_true('models: формат провайдер::модель', strpos((string) Arr::get($offers[0], 'spec', ''), '::') !== FALSE);

// --- коды --------------------------------------------------------------------------
$code = Service_Nj667_Graph3d::code_new();
check('code_new: длина 12', UTF8::strlen($code), 12);
check('code_new: безопасный алфавит',
	preg_match('~^[23456789abcdefghijkmnpqrstuvwxyz]{12}$~', (string) $code), 1);
check('code_new: коды разные',
	Service_Nj667_Graph3d::code_new() !== Service_Nj667_Graph3d::code_new(), TRUE);

// --- деградация без БД ---------------------------------------------------------------
check_true('без БД: job_state пустой', Service_Nj667_Graph3d::job_state(0) === []);
check_true('без БД: job_state не падает', is_array(Service_Nj667_Graph3d::job_state(5)));
check_true('без БД: model() не падает', is_string(Service_Nj667_Graph3d::model()));
$saved = Service_Nj667_Graph3d::set_generation_model('local::qwen-test');
check_true('без БД: set_generation_model ошибка, не исключение', Arr::get($saved, 'ok') === FALSE);

// ---------------------------------------------------------------- рендер вьюх

function render($file, array $data)
{
	global $ok, $bad;

	try
	{
		$view = new View($file);
		$view->set($data);
		$out  = $view->render();

		check('рендер '.$file.' ('.strlen($out).' байт)', strlen($out) > 500 ? 'true' : 'false', 'true');

		return $out;
	}
	catch (Throwable $e)
	{
		$bad++;
		echo "ФЕЙЛ рендер $file: ".$e->getMessage()." @ ".$e->getLine()."\n";

		return '';
	}
}

function contains($label, $haystack, $needle)
{
	check($label, strpos($haystack, $needle) !== FALSE ? 'true' : 'false', 'true');
}

$project = [
	'id'              => 7,
	'code'            => 'ab34cd56ef78',
	'user_id'         => 3,
	'visitor_uid'     => 'stand-visitor',
	'title'           => 'Трёхколёсный велосипед с корзиной',
	'summary'         => 'Три колеса, рама, руль, сиденье и корзина над задней осью.',
	'tags'            => 'велосипед, транспорт',
	'kind'            => 'public',
	'status'          => 'published',
	'featured'        => 1,
	'graph_json'      => json_encode(nj667_graph(), JSON_UNESCAPED_UNICODE),
	'nodes_count'     => 7,
	'edges_count'     => 6,
	'topo_hash'       => Service_Nj667_Graph3d::topology_hash(nj667_graph()),
	'model'           => 'qwen::qwen3-max',
	'remixes'         => 2,
	'views'           => 42,
	'moderation_note' => '',
	'updated_at'      => '2026-10-10 12:00:00',
];

$shared = [
	'node_types' => Service_Nj667_Graph3d::node_types(),
	'edge_types' => Service_Nj667_Graph3d::edge_types(),
	'csrf'       => Security::token(),
	'statuses'   => Service_Nj667_Graph3d::statuses(),
	'limits'     => Service_Nj667_Graph3d::limits(),
	'canvas'     => Service_Nj667_Graph3d::canvas(),
	'defaults'   => Service_Nj667_Graph3d::defaults(),
	'rate_text'  => '6 в минуту, 60 в час',
];

// --- витрина / создание -----------------------------------------------------------
$html = render('nj667/index', $shared + [
	'logged'    => TRUE,
	'can_start' => TRUE,
	'showcase'  => [$project],
]);

contains('витрина: заголовок сервиса', $html, 'пространственный граф');
contains('витрина: лучшие конструкции', $html, 'Лучшие конструкции');
contains('витрина: карточка published', $html, 'Трёхколёсный велосипед с корзиной');
contains('витрина: ссылка создать', $html, '/graph3d/create');
contains('витрина: ссылка в каталог', $html, '/graph3d/catalog');
contains('витрина: заголовок с иконкой', $html, 'fa-cube mr-2');
check_true('витрина: не по центру', strpos($html, 'mx-auto') === FALSE);
check_true('витрина: нет формы сборки', strpos($html, 'name="prompt"') === FALSE);
check_true('витрина: нет хлебных крошек', strpos($html, 'Хлебные крошки') === FALSE);
check_true('витрина: список проектов уехал', strpos($html, 'name="delete"') === FALSE);
contains('витрина: превью день', $html, 'theme=day');
contains('витрина: превью ночь', $html, 'theme=night');
contains('витрина: пара кадров', $html, 'nj667-preview-day');

$html = render('nj667/index', $shared + [
	'logged' => FALSE, 'can_start' => FALSE, 'showcase' => [],
]);
check_true('витрина гостя: без стены входа', strpos($html, 'nj-wall') === FALSE);
contains('витрина гостя: пустой топ', $html, 'Пока никто не опубликовал');

$html = render('nj667/create', $shared + [
	'logged'       => TRUE,
	'can_start'    => TRUE,
	'can_moderate' => TRUE,
	'error'        => '',
	'post'         => [],
	'model'        => '',
	'rag'          => TRUE,
	'ideas'        => ['Идея один', 'Идея два', 'Идея три'],
]);

// Атрибуты кнопки отправки на «Создать» (класс и подпись) — для проверок тона.
function submit_button($html) {
	$at = strpos($html, 'id="nj667-create-submit"');
	if ($at === FALSE) { return ''; }
	$end = strpos($html, '>', $at);
	return $end === FALSE ? '' : substr($html, $at, $end - $at);
}

contains('создать: заголовок', $html, 'Создать проект');
contains('создать: статус проектов', $html, 'Активные проекты:');
contains('создать: статус дневной квоты', $html, 'id="nj667-daily-quota"');
contains('создать: форма новой сборки', $html, 'name="prompt"');
contains('создать: POST на /graph3d/create', $html, 'action="/graph3d/create"');
contains('создать: форма multipart для фото', $html, 'enctype="multipart/form-data"');
contains('создать: поле фото', $html, 'name="photos[]"');
contains('создать: модератор может выбрать четыре фото', $html, 'data-max="4" multiple');
contains('создать: фото превьюшится', $html, 'id="nj667-photo-preview"');
contains('создать: превью использует локальный blob URL', $html, 'URL.createObjectURL(file)');
contains('создать: пресеты идей', $html, 'data-nj667-preset');
check('создать: три пресета', substr_count($html, 'type="button" data-nj667-preset='), 3);
contains('создать: обновить идеи', $html, 'id="nj667-ideas-refresh"');
check_true('создать: обновить — иконка, не текст', strpos($html, 'id="nj667-ideas-refresh"') !== FALSE
	AND strpos($html, 'fa-rotate') !== FALSE);
contains('создать: чекбокс RAG', $html, 'id="nj667-rag"');
contains('создать: расширенное отображение', $html, 'Расширенное отображение');
contains('создать админа: текущая модель', $html, 'воркера по умолчанию');
contains('создать: ссылка в настройки', $html, '/graph3d/settings');
check_true('создать: название внутри details', strpos($html, 'id="nj667-advanced"') !== FALSE
	AND strpos($html, 'id="nj667-title"') !== FALSE);
contains('создать: выбор режима без LLM', $html, 'value="template"');
contains('создать: режим шаблонов назван «Готовый каркас»', $html, 'Готовый каркас</span>');
contains('создать: простой режим стоит первым и выбран по умолчанию', $html, 'value="simple" data-mode-choice checked');
check_true('создать: порядок режимов simple → template → llm', strpos($html, 'value="simple"') < strpos($html, 'value="template"') AND strpos($html, 'value="template"') < strpos($html, 'value="llm"'));
contains('создать: режим полного пользовательского потока', $html, 'value="simple"');
contains('создать: пользовательский режим с точным названием', $html, 'Простой (для пользователя)');
contains('создать: шаблон домика', $html, 'Каркас домика');
// Просьба владельца (15.10.2026): убрать поясняющую плашку про STL, убрать
// заголовок «Что построить?», фото — компактной кнопкой рядом с описанием.
check_true('создать: нет плашки про STL и палочки', strpos($html, 'STL каждого уникального узла') === FALSE);
check_true('создать: у простого поля нет заголовка «Что построить?»', strpos($html, 'Что построить?') === FALSE
	AND strpos($html, 'aria-label="Что построить"') !== FALSE);
check_true('создать: фото — компактное поле, не отдельный блок', strpos($html, 'data-nj667-photo') !== FALSE
	AND strpos($html, 'id="nj667-photo-upload"') === FALSE);
check('создать: два компактных поля фото — простое и для LLM', substr_count($html, 'data-nj667-photo-input name="photos[]"'), 2);
contains('создать: у LLM своё поле фото', $html, 'id="nj667-photos-llm"');
$st_simple_at = strpos($html, 'data-creation-panel="simple"');
$st_prompt_at = $st_simple_at === FALSE ? FALSE : strpos($html, 'id="nj667-simple-prompt"', $st_simple_at);
$st_photo_at0 = $st_prompt_at === FALSE ? FALSE : strpos($html, 'data-nj667-photo>', $st_prompt_at);
check_true('создать: фото рядом с полем описания, внутри простой панели', $st_photo_at0 !== FALSE
	AND strpos(substr($html, $st_simple_at, $st_photo_at0 - $st_simple_at), '</fieldset>') === FALSE);
check_true('создать: правила фото в подсказке плашки, не строкой в панели', strpos($html, 'title="необязательно: до 4 кадров, до 5 МБ каждое"') !== FALSE
	AND strpos($html, '>необязательно') === FALSE);
contains('создать: статус фото описан для скринридера', $html, 'aria-describedby="nj667-photo-status"');
contains('создать: превью — миниатюры, не крупные карточки', $html, 'h-14 w-14 shrink-0');
$st_simple_btn = submit_button($html);
check_true('создать: кнопка простого режима зелёная (n2j-btn-cta)', strpos($st_simple_btn, 'n2j-btn-cta') !== FALSE
	AND strpos($st_simple_btn, 'n2j-btn-process') === FALSE);
check_true('создать: скрипт меняет тон кнопки по режиму', strpos($html, "mode === 'simple' ? 'emerald' : 'blue'") !== FALSE);
check_true('создать: нет хлебных крошек', strpos($html, 'Хлебные крошки') === FALSE);

$html = render('nj667/create', $shared + [
	'logged' => TRUE, 'can_start' => FALSE, 'can_moderate' => FALSE,
	'error' => '', 'post' => [], 'model' => '', 'rag' => TRUE,
]);
contains('создать обычному пользователю: выбран шаблон без LLM', $html, 'value="template" data-mode-choice checked');
contains('создать обычному пользователю: только одно фото', $html, 'data-max="1"');
$st_photo_at = strpos($html, 'id="nj667-photos"');
$st_photo_end = strpos($html, '>', $st_photo_at);
check_true('создать обычному пользователю: нет multiple у input фото', $st_photo_at !== FALSE AND $st_photo_end !== FALSE
	AND strpos(substr($html, $st_photo_at, $st_photo_end - $st_photo_at), 'multiple') === FALSE);
check_true('создать обычному пользователю: доступный режим не отключён',
	strpos($html, 'id="nj667-create-submit" type="submit" disabled') === FALSE);

$html = render('nj667/create', $shared + [
	'logged' => FALSE, 'can_start' => FALSE, 'can_moderate' => FALSE,
	'error' => '', 'post' => [], 'model' => '', 'rag' => TRUE,
]);
contains('создать гостя: плашка входа', $html, 'nj-wall');
contains('создать гостя: кнопка отключена', $html, 'disabled');

$html = render('nj667/projects', $shared + [
	'logged' => TRUE, 'mine' => [$project], 'error' => '',
]);
contains('проекты: заголовок', $html, 'Проекты');
contains('проекты: мой проект', $html, 'Трёхколёсный велосипед с корзиной');
contains('проекты: превью картинкой', $html, '/graph3d/api/preview?code=ab34cd56ef78');
contains('проекты: превью ночь', $html, 'theme=night');
contains('проекты: удаление проекта', $html, 'name="delete"');
contains('проекты: удаление иконкой', $html, 'fa-trash-alt');
check_true('проекты: нет большой кнопки «Удалить»', strpos($html, '>Удалить</button>') === FALSE);

$html = render('nj667/catalog', $shared + [
	'logged' => TRUE, 'can_start' => TRUE, 'catalog' => [$project], 'query' => '', 'error' => '',
]);
contains('каталог: заголовок', $html, 'Каталог');
contains('каталог: remix как шаблон', $html, 'name="remix"');
contains('каталог: поиск', $html, 'name="q"');
contains('каталог: превью ночь', $html, 'theme=night');

// --- рабочее место -----------------------------------------------------------------
$html = render('nj667/project', $shared + [
	'project'   => $project,
	'graph'     => Service_Nj667_Graph3d::graph_of($project),
	'revisions' => [
		['id' => 3, 'no' => 3, 'kind' => 'commit', 'label' => 'сдвиг «Руль»', 'author_kind' => 'user',
			'created_at' => '2026-10-10 12:05:00'],
		['id' => 2, 'no' => 2, 'kind' => 'milestone', 'label' => 'Контрольная точка 2', 'author_kind' => 'user',
			'created_at' => '2026-10-10 12:04:00'],
		['id' => 1, 'no' => 1, 'kind' => 'commit', 'label' => 'по описанию', 'author_kind' => 'ai',
			'created_at' => '2026-10-10 12:00:00'],
	],
	'events'    => [['kind' => 'submit', 'note' => 'отправлено на модерацию', 'created_at' => '2026-10-10 12:10:00']],
	'can_start' => TRUE,
	'can_moderate' => TRUE,
]);

contains('проект: холст', $html, 'id="nj667-canvas"');
contains('проект: холст день-ночь', $html, 'bg-white');
contains('проект: холст ночной фон', $html, 'dark:bg-gray-900');
contains('проект: карта импорта three', $html, '"three": "/assets/vendor/three/mod/three.module.js"');
contains('проект: скрипт редактора', $html, '/assets/js/nj667-graph3d.js');
contains('проект: описание → граф', $html, 'id="nj667-prompt"');
contains('проект: без автозаполнения', $html, 'autocomplete="off"');
contains('проект: пресет исправь', $html, 'Исправь ошибки');
contains('проект: пресет улучши', $html, 'Улучши и усовершенствуй');
contains('проект: инпейнтинг рядом', $html, 'id="nj667-inpaint-prompt"');
contains('проект: история правок', $html, 'id="nj667-history"');
contains('проект: контрольная точка в списке', $html, 'Контрольная точка 2');
contains('проект: правка модели помечена', $html, 'модель');
contains('проект: кнопка публикации', $html, 'id="nj667-publish-btn"');
contains('проект: опубликовать без очереди', $html, 'Опубликовать');
contains('проект: экспорт GLB', $html, 'id="nj667-export-glb-btn"');
contains('проект: экспорт JSON', $html, 'id="nj667-export-json-btn"');
contains('проект: удаление вторым нажатием', $html, 'id="nj667-delete-btn"');
contains('проект: удаление проекта иконкой', $html, 'aria-label="Удалить проект"');
contains('проект: мягкое удаление ревизии', $html, 'name="forget_revision"');
contains('проект: удалить узел', $html, 'id="nj667-del-node-btn"');
contains('проект: удалить связь', $html, 'id="nj667-del-edge-btn"');
contains('проект: оверлей воркера', $html, 'id="nj667-working"');
contains('проект: секундомер на оверлее', $html, 'id="nj667-elapsed"');
contains('проект: css-спиннер, не fa-spin', $html, 'nj667-spin');
contains('проект: поле фото к сборке', $html, 'id="nj667-photos"');
check_true('проект: RAG-чекбокса на правке нет', strpos($html, 'id="nj667-rag"') === FALSE);
check_true('проект: нет подсказки RAG', strpos($html, 'Только для пустого проекта') === FALSE);
contains('проект: страница просмотра', $html, 'Страница просмотра');
contains('проект: ссылка на чертёж', $html, '/graph3d/d/ab34cd56ef78');
contains('проект: панель на холсте', $html, 'id="nj667-toolbar"');
contains('проект: сцена под панелью', $html, 'id="nj667-stage"');
contains('проект: тулбар не переносит кнопки', $html, 'flex-wrap: nowrap');
contains('проект: тулбар днём читаемый', $html, 'text-gray-800');
contains('проект: иконка сохранить', $html, 'id="nj667-save-icon-btn"');
contains('проект: выбор на холсте', $html, 'id="nj667-hud"');
check_true('проект: сохранить после повторить', strpos($html, 'id="nj667-redo-btn"') < strpos($html, 'id="nj667-save-icon-btn"'));
contains('проект: на весь экран', $html, 'id="nj667-full-btn"');
contains('проект: конфиг графа в json', $html, 'id="nj667-config"');
check_true('проект: нет кнопки подписей', strpos($html, 'id="nj667-label-btn"') === FALSE);
check_true('проект: нет хлебных крошек', strpos($html, 'Хлебные крошки') === FALSE);

$project_regular_html = render('nj667/project', $shared + [
	'project' => $project,
	'graph' => Service_Nj667_Graph3d::graph_of($project),
	'revisions' => [],
	'events' => [],
	'can_start' => TRUE,
	'can_moderate' => FALSE,
]);
check_true('проект: «Описание → граф» скрыто без ACL nj667.moderate', strpos($project_regular_html, 'Описание → граф') === FALSE);
check_true('проект: inpaint скрыт без ACL nj667.moderate', strpos($project_regular_html, 'id="nj667-inpaint"') === FALSE);
check_true('проект: ручное сохранение доступно без LLM ACL', strpos($project_regular_html, 'id="nj667-save-btn"') !== FALSE);
check_true('проект: экспорт доступен без LLM ACL', strpos($project_regular_html, 'id="nj667-export-json-btn"') !== FALSE);

$auto_project_html = render('nj667/project', $shared + [
	'project' => $project,
	'graph' => Service_Nj667_Graph3d::graph_of($project),
	'revisions' => [],
	'events' => [],
	'can_start' => TRUE,
	'auto_simple_flow' => TRUE,
	'simple_flow' => ['enabled' => TRUE, 'state' => 'waiting', 'build_id' => 0, 'error' => ''],
]);
contains('автопоток: баннер ожидания на проекте', $auto_project_html, 'id="nj667-simple-flow"');
contains('автопоток: спрашивает статус проекта', $auto_project_html, '/graph3d/api/status?code=');
contains('автопоток: открывает созданную сборку', $auto_project_html, "'?build=' + buildId + '&auto=1'");
contains('автопоток: даёт ссылку остаться на графе', $auto_project_html, 'Остаться на графе');

$html = render('nj667/project', $shared + [
	'project'   => $project + ['job_id' => 11, 'graph_json' => json_encode(['nodes' => [], 'edges' => []])],
	'graph'     => ['meta' => [], 'nodes' => [], 'edges' => []],
	'revisions' => [],
	'events'    => [],
	'can_start' => TRUE,
]);
check_true('ожидание: мёртвый job_id не крутит оверлей', strpos($html, 'id="nj667-working" class="hidden') !== FALSE);
contains('ожидание: css-спиннер на месте', $html, 'nj667-spin');
contains('ожидание: секундомер 0:00', $html, 'id="nj667-elapsed"');
check_true('ожидание: нет дубля над холстом', strpos($html, 'Страницу можно не закрывать') === FALSE);

// --- публичная страница -------------------------------------------------------------
$html = render('nj667/view', $shared + [
	'project'      => $project,
	'graph'        => Service_Nj667_Graph3d::graph_of($project),
	'logged'       => FALSE,
	'can_start'    => FALSE,
	'can_moderate' => FALSE,
]);

contains('публичная: топология json', $html, 'Топология (источник правды)');
contains('публичная: узел в списке по id', $html, 'n2');
contains('публичная: холст чтения', $html, 'id="nj667-view-canvas"');
contains('публичная: холст день-ночь', $html, 'bg-white');
contains('публичная: readonly', $html, '"readonly":true');
contains('публичная: теги', $html, '#велосипед');
contains('публичная: шаблон', $html, 'Скопировать себе');
contains('публичная: ссылка на изделие', $html, '/graph3d/b/ab34cd56ef78');
contains('публичная: экспорт GLB', $html, 'id="nj667-export-glb-btn"');
contains('публичная: экспорт JSON', $html, 'id="nj667-export-json-btn"');
contains('публичная: ссылка на чертёж', $html, '/graph3d/d/ab34cd56ef78');

// --- модерация ------------------------------------------------------------------------
$html = render('nj667/moderate', $shared + [
	'queue'   => [$project, $project + ['id' => 8, 'code' => 'cd56ef78ab34', 'title' => 'Стул', 'status' => 'review']],
	'current' => $project,
	'graph'   => Service_Nj667_Graph3d::graph_of($project),
	'guard'   => Service_Nj667_Graph3d::graph_validate(Service_Nj667_Graph3d::graph_of($project)),
	'events'  => [['kind' => 'guard', 'note' => 'пре-модерация: ok', 'created_at' => '2026-10-10 12:09:00']],
	'status'  => '',
]);

contains('модерация: очередь', $html, 'Заявки');
contains('модерация: холст просмотра', $html, 'id="nj667-canvas"');
contains('модерация: холст день-ночь', $html, 'bg-white');
contains('модерация: превью ночь', $html, 'theme=night');
contains('модерация: AI-Guard', $html, 'AI-Guard: структура');
contains('модерация: одобрить', $html, 'value="approve"');
contains('модерация: образец', $html, 'value="feature"');
contains('модерация: отклонить', $html, 'value="reject"');
contains('модерация: причина', $html, 'id="nj667-note"');
contains('модерация: revision_id', $html, 'name="revision_id"');
contains('модерация: перегенерация названия', $html, 'id="nj667-caption-btn"');
contains('модерация: кнопка названия', $html, 'Перегенерировать название и описание');
contains('модерация: caption API', $html, '/graph3d/api/caption');
contains('модерация: журнал', $html, 'пре-модерация: ok');
check_true('модерация: нет хлебных крошек', strpos($html, 'Хлебные крошки') === FALSE);
check_true('модерация: причина не обязательна', strpos($html, 'обязательна') === FALSE);

$html = render('nj667/settings', $shared + [
	'models' => Service_Nj667_Graph3d::models(),
	'model'  => '',
	'error'  => '',
]);
contains('настройки: список моделей', $html, 'name="model"');
contains('настройки: своя модель', $html, 'name="model_custom"');
contains('настройки: размер кадра', $html, 'name="photo_max_px"');
contains('настройки: RAG', $html, 'name="rag"');
check_true('настройки: нет хлебных крошек', strpos($html, 'Хлебные крошки') === FALSE);

$html = render('nj667/ideas', $shared + [
	'presets'  => [['id' => 1, 'theme' => 'мебель', 'body' => 'Стул на четырёх ножках со спинкой', 'created_at' => '2026-10-11 12:00:00']],
	'job'      => [],
	'waiting'  => FALSE,
	'job_note' => '',
	'error'    => '',
	'theme'    => 'мебель',
]);
contains('идеи: заголовок', $html, 'Идеи для сборки');
contains('идеи: тема пачки', $html, 'name="theme"');
contains('идеи: генерация 30', $html, 'name="generate"');
contains('идеи: удаление иконкой', $html, 'name="delete"');
contains('идеи: пресет в списке', $html, 'Стул на четырёх ножках со спинкой');
check_true('идеи: нет confirm()', strpos($html, 'confirm(') === FALSE);

$html = render('nj667/moderate', $shared + [
	'queue' => [], 'current' => NULL, 'graph' => Service_Nj667_Graph3d::graph_new(''),
	'guard' => [], 'events' => [], 'status' => '',
]);
contains('модерация: пустая очередь', $html, 'Выберите заявку слева.');

$html = render('nj667/project', $shared + [
	'project'     => $project,
	'graph'       => Service_Nj667_Graph3d::graph_of($project),
	'revisions'   => [],
	'events'      => [],
	'pending'     => ['id' => 9, 'no' => 4, 'status' => 'review'],
	'premoderate' => TRUE,
	'can_start'   => TRUE,
	'can_moderate' => TRUE,
]);
contains('проект: заявка висит — кнопка немая', $html, 'На модерации');
contains('проект: CFG.review', $html, '"review":true');
contains('проект: ссылка на изделие', $html, '/graph3d/b/ab34cd56ef78');
contains('проект: ссылка названа по-человечески', $html, 'Изделие: STL, спецификация, узлы');

$html = render('nj667/usage', [
	'rows' => [[
		'id' => 11, 'channel' => 'nj667.generate', 'status' => 'done',
		'payload' => json_encode(['prompt' => 'трёхколёсный велосипед', 'project_id' => 7]),
		'created_at' => '2026-10-11 12:00:00', 'code' => 'ab34cd56ef78',
		'title' => 'Велосипед', 'prompt_tokens' => 120, 'completion_tokens' => 80,
		'llm_calls' => 1, 'elapsed_ms' => 4000,
	]],
	'spend' => [['who' => 'admin', 'jobs' => 1, 'prompt_tokens' => 120, 'completion_tokens' => 80]],
	'query' => '', 'page' => 1, 'pages' => 3, 'total' => 50,
]);
contains('usage: заголовок', $html, 'Генерации и токены');
contains('usage: промпт', $html, 'трёхколёсный велосипед');
contains('usage: токены', $html, 'токены');
contains('usage: превью', $html, '/graph3d/api/preview?code=ab34cd56ef78');
contains('usage: превью ночь', $html, 'theme=night');
contains('usage: поиск', $html, 'name="q"');
// карточки токенов на 2 колонки выезжают только на lg: ниже table с токенами
// ломается в две колонки по 340px (владелец правил вьюху, стенд — за ним).
contains('usage: сетка', $html, 'lg:grid-cols-2');
contains('usage: пагинация', $html, 'Страницы');

// --- карточка для главной ----------------------------------------------------------------
$html = render('nj667/card', []);
contains('карточка: ссылка на сервис', $html, 'href="/graph3d"');
contains('карточка: заголовок', $html, 'Графовый 3D-конструктор');

// --- ловушки, которые стенд вьюх не видит ----------------------------------------------
$ctrl = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php');
$acl_cfg = (string) file_get_contents(N2J_STAND_ROOT.'/application/config/acl.php');
$start_roles = preg_match("/'nj667\\.start'\\s*=>\\s*\\[([^\\]]*)\\]/", $acl_cfg, $acl_match)
	? (string) $acl_match[1] : '';
check_true('ACL: роль login имеет право начальной генерации nj667.start',
	$start_roles !== '' AND strpos($start_roles, "'login'") !== FALSE);
check_true('контроллер: редирект через HTTP::redirect (Kohana)', strpos($ctrl, 'HTTP::redirect') !== FALSE);
check_true('контроллер: нет Request::redirect — такого метода нет', strpos($ctrl, 'request->redirect') === FALSE);
check_true('контроллер: страница настроек', strpos($ctrl, 'function action_settings') !== FALSE);
check_true('контроллер: страница генераций', strpos($ctrl, 'function action_usage') !== FALSE);
check_true('контроллер: страница проектов', strpos($ctrl, 'function action_projects') !== FALSE);
check_true('контроллер: страница каталога', strpos($ctrl, 'function action_catalog') !== FALSE);
check_true('контроллер: страница идей', strpos($ctrl, 'function action_ideas') !== FALSE);
check_true('контроллер: страница чертежа', strpos($ctrl, 'function action_drawing') !== FALSE);
check_true('контроллер: вьюхи nj667', strpos($ctrl, "View::factory('nj667/") !== FALSE);
check_true('контроллер: нет папки views/667', strpos($ctrl, "View::factory('667/") === FALSE);
check_true('контроллер: POST поддерживает три режима', strpos($ctrl, "['llm', 'template', 'simple']") !== FALSE);
check_true('меню: порядок Создать → Каталог → Проекты', strpos($ctrl, "'title' => 'Создать'") < strpos($ctrl, "'title' => 'Каталог'") AND strpos($ctrl, "'title' => 'Каталог'") < strpos($ctrl, "'title' => 'Проекты'"));
check_true('меню: проекты только по Gate::logged_in', strpos($ctrl, 'if (Gate::logged_in())') !== FALSE);
$st_nav_titles = ['Создать', 'Каталог', 'Проекты', 'Модерация', 'Генерации', 'Идеи', 'Настройки'];
$st_nav_pos = array_map(function ($title) use ($ctrl) { return strpos($ctrl, "'title' => '".$title."'"); }, $st_nav_titles);
$st_nav_sorted = $st_nav_pos;
sort($st_nav_sorted);
check_true('меню: все семь пунктов в заданном порядке', ! in_array(FALSE, $st_nav_pos, TRUE) AND $st_nav_pos === $st_nav_sorted);
check_true('меню: последние четыре только при nj667.moderate', strpos($ctrl, 'if ($this->_can_moderate)') !== FALSE);

check_true('контроллер: сервер ограничивает фото по moderator ACL', strpos($ctrl, '$photo_limit = $this->_can_moderate ? Service_Nj667_Graph3d::PHOTO_MAX : 1;') !== FALSE);
check_true('контроллер: слишком много фото отклоняются на сервере', strpos($ctrl, 'if ($photo_count > $photo_limit)') !== FALSE);
check_true('контроллер: полный маршрут передаёт флаг воркеру', strpos($ctrl, "\$payload['simple_pipeline'] = TRUE") !== FALSE);
check_true('контроллер: простой маршрут включает simple_flow до очереди', strpos($ctrl, 'simple_flow_arm($project_id)') !== FALSE);

$api = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Api.php');
check_true('api: нет $this->can — у Controller такого метода нет', strpos($api, '$this->can(') === FALSE);
check_true('api: удаление через _can_moderate', strpos($api, '_can_moderate()') !== FALSE);
check_true('api: пре-модерация не ставится всегда', strpos($api, 'premoderate()') !== FALSE);
check_true('api: перегенерация карточки', strpos($api, 'function action_caption') !== FALSE);
check_true('api: случайные идеи', strpos($api, 'function action_ideas') !== FALSE);
check_true('api: статус включает simple_flow', strpos($api, "'simple_flow'  => Service_Nj667_Graph3d::simple_flow_state(\$project)") !== FALSE);
check_true('API: лимит фото из ACL', strpos($api, 'function _photo_limit()') !== FALSE AND strpos($api, 'return $this->_can_moderate() ? Service_Nj667_Graph3d::PHOTO_MAX : 1;') !== FALSE);
$generate_api = preg_match('/function action_generate\\(\\).+?function action_inpaint/s', $api, $m) ? $m[0] : '';
$inpaint_api = preg_match('/function action_inpaint\\(\\).+?function action_save/s', $api, $m) ? $m[0] : '';
$create_api = preg_match('/function action_create\\(\\).+?function action_generate/s', $api, $m) ? $m[0] : '';
$web_create = preg_match('/function _create_project\\(.+?function _delete_project/s', $ctrl, $m) ? $m[0] : '';
check_true('API: начальное создание сохраняет can_start',
	$create_api !== '' AND strpos($create_api, '$this->_gate(TRUE)') !== FALSE);
check_true('страница: начальный LLM-запуск сохраняет can_start',
	$web_create !== '' AND strpos($web_create, '$mode !== \'template\' AND ! $this->_can_start') !== FALSE);
check_true('API: повторная генерация требует nj667.moderate и rate gate, не can_start',
	$generate_api !== '' AND strpos($generate_api, '$this->_can_moderate()') !== FALSE
	AND strpos($generate_api, '$this->_rate_gate()') !== FALSE
	AND strpos($generate_api, '$this->_project(TRUE)') === FALSE);
check_true('API: inpaint требует nj667.moderate и rate gate, не can_start',
	$inpaint_api !== '' AND strpos($inpaint_api, '$this->_can_moderate()') !== FALSE
	AND strpos($inpaint_api, '$this->_rate_gate()') !== FALSE
	AND strpos($inpaint_api, '$this->_project(TRUE)') === FALSE);
check_true('API: создание проекта проверяет дневную квоту',
	strpos($api, 'llm_quota_status($user ? (int) $user->id : 0)') !== FALSE);
check_true('API: quota endpoint status видит дневной остаток',
	strpos($api, "'quota'        => Service_Nj667_Graph3d::llm_quota_status(") !== FALSE);

$worker_ctrl = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Worker.php');
check_true('воркер: простой поток стартует после нового графа',
	strpos($worker_ctrl, 'simple_flow_start($project_id)') !== FALSE
	AND strpos($worker_ctrl, "Arr::get(\$this->_body, 'merge', FALSE)") !== FALSE);

$svc = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php');
check_true('квота: очередь резервирует лимит атомарным upsert',
	strpos($svc, 'function _reserve_llm_quota') !== FALSE
	AND strpos($svc, 'ON CONFLICT (user_id, usage_day, operation)') !== FALSE
	AND strpos($svc, 'nj_667_daily_usage.used < ') !== FALSE);
check_true('квота: очередь считает только generate и inpaint',
	strpos($svc, 'counts_toward_llm_quota($channel)') !== FALSE);
check_true('проекты: лимит проверяется под advisory lock',
	strpos($svc, 'pg_advisory_xact_lock(667, ') !== FALSE
	AND strpos($svc, 'function active_project_count') !== FALSE);
check_true('история: обрезка получает предел из профиля и сериализует параллельные записи',
	strpos($svc, "'historical_revisions', 3") !== FALSE
	AND strpos($svc, 'function _trim_revisions') !== FALSE
	AND strpos($svc, 'pg_advisory_xact_lock(668, ') !== FALSE);
check_true('сервис: класс Graph вынесен', class_exists('Service_Nj667_Graph'));
check_true('сервис: класс Drawing вынесен', class_exists('Service_Nj667_Drawing'));
$flow_state = Service_Nj667_Graph3d::simple_flow_state(['settings_json' => json_encode([
	'simple_flow' => ['enabled' => TRUE, 'state' => 'queued', 'build_id' => 17,
		'build_job_id' => 18, 'parts_job_id' => 19, 'error' => ''],
])]);
check('сервис: simple_flow читает состояние из settings_json', Arr::get($flow_state, 'state'), 'queued');
check('сервис: simple_flow отдаёт build_id', Arr::get($flow_state, 'build_id'), 17);
check('сервис: обычный проект без simple_flow', Service_Nj667_Graph3d::simple_flow_state([]), []);
check_true('сервис: отклонение без причины разрешено', strpos($svc, 'Напишите причину') === FALSE);
check_true('сервис: нет отказа за пустые подписи', strpos($svc, 'Ни у одного узла нет подписи') === FALSE);
check_true('сервис: мягкое удаление ревизии', strpos($svc, 'function delete_revision') !== FALSE);
check_true('сервис: заявка — ревизия', strpos($svc, 'function pending_revision') !== FALSE);
check_true('сервис: заявка по проекту или снимку', strpos($svc, 'function pending_for_graph') !== FALSE);
check_true('сервис: каталог по проекту', strpos($svc, 'опубликованные проекты') !== FALSE);
check_true('сервис: пре-модерация из env', strpos($svc, 'function premoderate') !== FALSE);
check_true('сервис: пагинация генераций', strpos($svc, 'function generations_count') !== FALSE);
check_true('сервис: копилка идей', strpos($svc, 'function idea_presets_pick') !== FALSE);
$picked = Service_Nj667_Graph3d::idea_presets_pick(3);
check('идеи: три запасных без БД', count($picked), 3);
$submit_src = preg_match('/function submit\\(.+?function moderate/s', $svc, $m) ? $m[0] : '';
check_true('сервис: заявка не пишет ревизию', $submit_src !== ''
	AND strpos($submit_src, "_insert('nj_667_revisions'") === FALSE);
check_true('сервис: повтор заявки отказан', strpos($svc, 'Уже на модерации.') !== FALSE);
$restore_src = preg_match('/function restore\(.+?function delete_revision/s', $svc, $m) ? $m[0] : '';
check_true('сервис: откат без нового коммита', $restore_src !== ''
	AND strpos($restore_src, 'save_graph') === FALSE
	AND strpos($restore_src, '_store_graph') !== FALSE);

$js = (string) file_get_contents(N2J_STAND_ROOT.'/public_html/assets/js/nj667-graph3d.js');
check_true('холст: рёбра прямые, не Безье', strpos($js, 'QuadraticBezierCurve3') === FALSE);
check_true('холст: день-ночь по html.dark', strpos($js, 'function applyTheme') !== FALSE
	AND strpos($js, 'function isNight') !== FALSE
	AND strpos($js, "bg: '#ffffff'") !== FALSE);
check_true('холст: поллинг смотрит status очереди', strpos($js, 'job.status') !== FALSE);
check_true('холст: поллинг только пока задача жива', strpos($js, 'function jobIsLive') !== FALSE);
check_true('холст: ошибка воркера сразу стопит поллинг', strpos($js, 'function failJobText') !== FALSE
	AND strpos($js, 'job.error при retry') !== FALSE);
check_true('холст: секундомер воркера', strpos($js, 'startElapsed') !== FALSE);
check_true('холст: фото к generate', strpos($js, 'readPhotos') !== FALSE);
check_true('холст: RAG в generate', strpos($js, 'payload.rag') !== FALSE);
check_true('холст: полный экран', strpos($js, 'function toggleFull') !== FALSE);
check_true('холст: ресайз по сцене, не по ящику', strpos($js, 'function canvasHost') !== FALSE);
check_true('холст: сохранить иконкой', strpos($js, 'saveIconBtn') !== FALSE);
check_true('холст: орбита без вязкого damping', strpos($js, 'enableDamping = false') !== FALSE);
check_true('холст: кнопка публикации не молчит', strpos($js, 'function paintPublish') !== FALSE);
check_true('холст: publish ловит сеть', strpos($js, 'Опубликовать') !== FALSE AND strpos($js, 'На модерации') !== FALSE);
check_true('холст: пресеты промпта', strpos($js, 'data-nj667-preset') !== FALSE);

$st_upload_files = [
	'name' => ['one.jpg', 'two.jpg'], 'tmp_name' => ['', ''],
	'error' => [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE], 'size' => [0, 0],
];
check('сервис: считает все выбранные фото, даже слоты с ошибкой', Service_Nj667_Graph3d::uploads_count($st_upload_files), 2);
check_true('сервис: prepare_uploads принимает серверный лимит', strpos($svc, 'function prepare_uploads($files, $max') !== FALSE);
check_true('сервис: prepare_payload принимает серверный лимит', strpos($svc, 'function prepare_payload(array $images, $max') !== FALSE);
check_true('сервис: пустые байты не фото', Service_Nj667_Graph3d::photo_jpeg('') === '');
check_true('сервис: мусор как фото не принимается', Service_Nj667_Graph3d::photo_jpeg('not-an-image') === '');
check_true('сервис: кадр больше 5 МБ отсекается', Service_Nj667_Graph3d::photo_jpeg(str_repeat('x', 6 * 1024 * 1024)) === '');
check_true('сервис: фото на диск, не в очередь', strpos($svc, 'function photos_dir') !== FALSE);

$wh = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/handlers/nj667_graph3d.py');
check_true('воркер: тип узла всегда connector', strpos($wh, 'type всегда') !== FALSE AND strpos($wh, '«connector»') !== FALSE);
check_true('воркер: канал идей', strpos($wh, 'nj667.ideas') !== FALSE AND strpos($wh, 'def handle_ideas') !== FALSE);
check_true('воркер: канал карточки', strpos($wh, 'nj667.caption') !== FALSE AND strpos($wh, 'def handle_caption') !== FALSE);
check_true('воркер: лёгкая модель для саммари', strpos($wh, '_model_of(payload, light=True)') !== FALSE);
check_true('воркер: фото через image_url', strpos($wh, 'image_url') !== FALSE);
check_true('воркер: кадры из контекста, не из payload', strpos($wh, '_photos_of') !== FALSE);
check_true('воркер: кадр жмётся до photo_max_px', strpos($wh, '_photo_max_px') !== FALSE);
check_true('воркер: RAG можно выключить', strpos($wh, '_rag_on') !== FALSE);
check('сервис: кадр модели по умолчанию 512', Service_Nj667_Graph3d::photo_max_px(), 512);
check_true('сервис: RAG по умолчанию включён', Service_Nj667_Graph3d::rag_default() === TRUE);
check_true('сервис: RAG из 0 выключен', Service_Nj667_Graph3d::rag_from(['rag' => '0']) === FALSE);
check_true('сервис: RAG из 1 включён', Service_Nj667_Graph3d::rag_from(['rag' => '1']) === TRUE);
check_true('сервис: JSON-ошибка задачи — сразу fail', Service_Nj667_Graph3d::job_failed([
	'status' => 'queued', 'error' => 'ValueError: ответ не JSON',
]) === TRUE);
check_true('сервис: running без ошибки жива', Service_Nj667_Graph3d::job_failed([
	'status' => 'running', 'error' => '',
]) === FALSE);
check_true('сервис: пустая задача не жива', Service_Nj667_Graph3d::job_live([]) === FALSE);
check_true('сервис: none не жива', Service_Nj667_Graph3d::job_live([
	'found' => FALSE, 'status' => 'none', 'job_id' => 0,
]) === FALSE);
check_true('сервис: running жива', Service_Nj667_Graph3d::job_live([
	'found' => TRUE, 'status' => 'running',
]) === TRUE);
check_true('сервис: done не жива', Service_Nj667_Graph3d::job_live([
	'found' => TRUE, 'status' => 'done',
]) === FALSE);
check_true('сервис: queued с ошибкой не жива', Service_Nj667_Graph3d::job_live([
	'status' => 'queued', 'error' => 'ValueError: ответ не JSON',
]) === FALSE);
check('сервис: live_job_id без задачи — 0', Service_Nj667_Graph3d::live_job_id(['id' => 1, 'job_id' => 0]), 0);
check_true('сервис: job_id холста только generate/inpaint', strpos($svc, 'CH_GENERATE, self::CH_INPAINT') !== FALSE);
check_true('api: status сбрасывает мёртвый job_id', strpos($api, 'job_live') !== FALSE);
check_true('сервис: JSON-ошибка человеку, не дамп', strpos(Service_Nj667_Graph3d::job_error_text([
	'status' => 'failed', 'error' => 'ValueError: ответ не JSON (char 6518)',
]), 'сломанный граф') !== FALSE);
check_true('сервис: генерация без retry', strpos($svc, "'max_attempts' => 1") !== FALSE);
check_true('сервис: витрина лучших published', strpos($svc, 'function showcase') !== FALSE);
check_true('воркер: правка текущего графа, не каталога', strpos($wh, 'ТЕКУЩИЙ граф') !== FALSE);
$few_src = preg_match('/function few_shot\\(.+?function _vector_of/s', $svc, $m) ? $m[0] : '';
check_true('few-shot: нет отката на весь каталог', $few_src !== '' AND strpos($few_src, 'self::catalog') === FALSE);
check('сервис: set_model один (проект), set_generation_model — админка',
	substr_count($svc, 'function set_model('), 1);
check_true('сервис: админская модель отдельно', strpos($svc, 'function set_generation_model(') !== FALSE);
check('сервис: канал caption', Service_Nj667_Graph3d::CH_CAPTION, 'nj667.caption');
check('сервис: timeout caption', Service_Nj667_Graph3d::timeout('caption'), 180);
check_true('сервис: set_caption есть', strpos($svc, 'function set_caption') !== FALSE);
$cap = Service_Nj667_Graph3d::set_caption(0, 'Стул', 'Четыре ножки');
check_true('caption: нет проекта', empty($cap['ok']));
$cap = Service_Nj667_Graph3d::set_caption(1, '', 'описание');
check_true('caption: пустое название', empty($cap['ok']));
putenv('NJ667_LIGHT_MODEL=cheap::text');
check('сервис: light_model из env', Service_Nj667_Graph3d::light_model(), 'cheap::text');
putenv('NJ667_LIGHT_MODEL=');
check_true('сервис: light_model без env = model', Service_Nj667_Graph3d::light_model() === Service_Nj667_Graph3d::model());
$init = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/init.php');
check_true('маршрут api caption', strpos($init, 'graph3d/api/caption') !== FALSE);
check_true('приёмная w caption', strpos($init, 'graph3d/w/caption') !== FALSE);
check_true('маршрут чертежа', strpos($init, 'graph3d/d/<code>') !== FALSE);

$dopt = Service_Nj667_Graph3d::drawing_opt(['unit' => 'inch', 'unit_size' => 0, 'scale' => 'нет']);
check('чертёж: мусор единицы → мм', $dopt['unit'], 'mm');
check('чертёж: дефолт клетки 10 мм', (string) $dopt['unit_size'], '10');
check('чертёж: мусор масштаба → вписать', $dopt['scale'], 'fit');
$dopt_m = Service_Nj667_Graph3d::drawing_opt(['unit' => 'm']);
check('чертёж: в метрах клетка 0.5', (string) $dopt_m['unit_size'], '0.5');

$dplan = Service_Nj667_Graph3d::drawing_plan(nj667_graph(), [
	'unit' => 'mm', 'unit_size' => 10, 'scale' => '1:10', 'title' => 'Велосипед', 'code' => 'ab34cd56ef78',
]);
check_true('чертёж: план не пуст', ! empty($dplan['ok']));
check('чертёж: габарит X 120 мм', (string) round($dplan['span']['x'], 4), '120');
$want_len = 10 * sqrt(36 + 64 + 16);
check_true('чертёж: длина n1—n2', abs($dplan['longest'] - $want_len) < 0.05);
check('чертёж: масштаб 1:10', $dplan['scale'], '1:10');
check('чертёж: формат 107 мм', Service_Nj667_Graph3d::drawing_fmt(107.2, 'mm'), '107 мм');
check('чертёж: формат метров', Service_Nj667_Graph3d::drawing_fmt(1.5, 'm'), '1,5 м');

$dsvg = Service_Nj667_Graph3d::drawing_svg(nj667_graph(), [
	'unit' => 'mm', 'unit_size' => 10, 'scale' => '1:10', 'title' => 'Велосипед',
]);
check_true('чертёж: svg лист A4', strpos($dsvg, 'viewBox="0 0 297 210"') !== FALSE);
check_true('чертёж: svg Спереди', strpos($dsvg, 'data-view="Спереди"') !== FALSE);
check_true('чертёж: svg Сверху', strpos($dsvg, 'data-view="Сверху"') !== FALSE);
check_true('чертёж: нет латинской sверху', strpos($dsvg, 'sверху') === FALSE);
check_true('чертёж: svg штамп масштаб', strpos($dsvg, '1:10') !== FALSE);
check_true('чертёж: номера узлов по умолчанию выключены', strpos($dsvg, 'data-nid=') === FALSE);
check_true('чертёж: штамп ГОСТ 2.104 с графой «Листов»', strpos($dsvg, 'Листов') !== FALSE);
$dsvg_ids = Service_Nj667_Graph3d::drawing_svg(nj667_graph(), [
	'unit' => 'mm', 'unit_size' => 10, 'scale' => '1:10', 'title' => 'Велосипед', 'ids' => '1',
]);
check_true('чертёж: номера узлов по галочке ids', strpos($dsvg_ids, 'data-nid=') !== FALSE);

$stack = [
	'meta'  => ['title' => 'Стойка'],
	'nodes' => [
		['id' => 'bot', 'type' => 'connector', 'x' => 0, 'y' => 0, 'z' => 0],
		['id' => 'top', 'type' => 'connector', 'x' => 0, 'y' => 4, 'z' => 0],
		['id' => 'side', 'type' => 'connector', 'x' => 6, 'y' => 0, 'z' => 0],
	],
	'edges' => [
		['id' => 'e1', 'source' => 'bot', 'target' => 'top', 'type' => 'rigid'],
		['id' => 'e2', 'source' => 'bot', 'target' => 'side', 'type' => 'rigid'],
	],
];
$stack_svg = Service_Nj667_Graph3d::drawing_svg($stack, [
	'unit' => 'mm', 'unit_size' => 10, 'scale' => '1:10', 'title' => 'Стойка', 'ids' => 1,
]);
preg_match('/<g data-view="Сверху">.*?<\/g>/s', $stack_svg, $top_g);
$top_view = isset($top_g[0]) ? $top_g[0] : '';
check_true('чертёж: сверху есть группа', $top_view !== '');
check_true('чертёж: сверху подпись верхнего', strpos($top_view, 'data-nid="top"') !== FALSE);
check_true('чертёж: сверху нет подписи нижнего', strpos($top_view, 'data-nid="bot"') === FALSE);
check_true('чертёж: сверху кружок нижнего есть', substr_count($top_view, '<circle ') === 3);

$dcsv = Service_Nj667_Graph3d::drawing_csv(nj667_graph(), ['unit' => 'mm', 'unit_size' => 10]);
check_true('чертёж: csv габарит', strpos($dcsv, 'Габарит') !== FALSE);
check_true('чертёж: csv заготовка', strpos($dcsv, 'Заготовка') !== FALSE);

$empty_plan = Service_Nj667_Graph3d::drawing_plan(Service_Nj667_Graph3d::graph_new(''), ['unit' => 'mm']);
check_true('чертёж: пустой граф', empty($empty_plan['ok']));

$dviews = Service_Nj667_Graph3d::drawing_view_svgs(nj667_graph(), [
	'unit' => 'mm', 'unit_size' => 10, 'scale' => '1:10', 'title' => 'Велосипед',
]);
check('чертёж: четыре отдельных вида', count($dviews), 4);
check_true('чертёж: вид Сверху отдельно', isset($dviews['Сверху']) AND strpos($dviews['Сверху'], 'data-view="Сверху"') !== FALSE);
check_true('чертёж: вид Спереди отдельно', isset($dviews['Спереди']));
check_true('чертёж: экранный вид не лист 297mm', isset($dviews['Сверху']) AND strpos($dviews['Сверху'], '297mm') === FALSE);
check_true('чертёж: экранный вид на 100%', isset($dviews['Сверху']) AND strpos($dviews['Сверху'], 'width="100%"') !== FALSE);

$html = render('nj667/drawing', $shared + [
	'project' => $project,
	'graph'   => Service_Nj667_Graph3d::graph_of($project),
	'plan'    => $dplan,
	'svg'     => $dsvg,
	'views'   => $dviews,
	'opt'     => ['unit' => 'mm', 'unit_size' => 10, 'scale' => '1:10', 'paper' => 'a4'],
	'owner'   => TRUE,
	'units'   => Service_Nj667_Graph3d::drawing_units(),
	'scales'  => Service_Nj667_Graph3d::drawing_scales(),
	'error'   => '',
]);
contains('чертёж: столбик видов на узком', $html, 'nj667-drawing-views');
contains('чертёж: лист для широкого', $html, 'nj667-drawing-full');
contains('чертёж: до 640px — столбик видов', $html, 'nj667-drawing-views grid grid-cols-1 gap-3 sm:hidden print:hidden');
contains('чертёж: от 640px и в печати — весь лист', $html, 'nj667-drawing-full overflow-auto hidden sm:block print:block');
contains('чертёж: единица мм', $html, 'миллиметры');
contains('чертёж: масштаб', $html, 'id="nj667-scale"');
contains('чертёж: pdf', $html, 'id="nj667-pdf-btn"');
contains('чертёж: csv', $html, 'format=csv');
contains('чертёж: заготовки', $html, 'Заготовки');
contains('чертёж: координаты', $html, 'Координаты узлов');
check_true('чертёж: нет confirm', strpos($html, 'confirm(') === FALSE);

$html = render('nj667/drawing_print', [
	'plan' => $dplan,
	'svg'  => $dsvg,
	'opt'  => ['paper' => 'a4'],
]);
contains('чертёж печать: auto print', $html, 'window.print');
contains('чертёж печать: A4', $html, 'A4');
$root_env = (string) file_get_contents(N2J_STAND_ROOT.'/.env.example');
check_true('сайт .env.example: NJ667_LIGHT_MODEL', strpos($root_env, 'NJ667_LIGHT_MODEL') !== FALSE);

foreach (['NJ667_BUILD_MIN_ANGLE', 'NJ667_BUILD_MIN_SPAN', 'NJ667_BUILD_MAX_NODES',
	'NJ667_SCAD_MAX_KB', 'NJ667_MAX_STL_MB', 'NJ667_BUILD_TIMEOUT', 'NJ667_OPENSCAD_BIN',
	'NJ667_BUILD_PARTS_MAX', 'NJ667_BUILD_PARTS_BUDGET'] as $env_key)
{
	check_true('сайт .env.example: '.$env_key, strpos($root_env, $env_key) !== FALSE);
}

$wenv = (string) file_get_contents(N2J_STAND_ROOT.'/workers/.env.example');
check_true('воркер .env.example: есть NJ667_MODEL', strpos($wenv, 'NJ667_MODEL=') !== FALSE);
check_true('воркер .env.example: есть NJ667_LIGHT_MODEL', strpos($wenv, 'NJ667_LIGHT_MODEL') !== FALSE);
check_true('воркер .env.example: есть NJ667_FEW_SHOT', strpos($wenv, 'NJ667_FEW_SHOT') !== FALSE);
check_true('воркер .env.example: есть NJ667_PHOTO_MAX_PX', strpos($wenv, 'NJ667_PHOTO_MAX_PX') !== FALSE);
check_true('воркер .env.example: эмбеддинги общие', strpos($wenv, 'nj667.embed') !== FALSE);
check_true('воркер .env.example: таймаут рендера STL', strpos($wenv, 'NJ667_BUILD_TIMEOUT') !== FALSE);
check_true('воркер .env.example: свой openscad для сборок', strpos($wenv, 'NJ667_OPENSCAD_BIN') !== FALSE);

// --- сборка графа в изделие (Service_Nj667_Build) ---------------------------
// Наработки владельца (source_data/for_3d_graph.scad) переехали на PHP: здесь
// проверяется то, из-за чего «красивая на экране» конструкция не печатается.
// Координаты во всех графах стенда вписаны в радиус холста (24): graph_clean()
// режет всё, что дальше, и проверка проверяла бы кламп, а не посадку.

/** Треугольник: самая маленькая жёсткая фигура. */
function nj667_tri()
{
	return [
		'meta'  => ['title' => 'Уголок', 'units' => 'unit'],
		'nodes' => [
			['id' => 'a', 'type' => 'connector', 'x' => 0,  'y' => 0, 'z' => 0],
			['id' => 'b', 'type' => 'endpoint',  'x' => 10, 'y' => 0, 'z' => 0],
			['id' => 'c', 'type' => 'endpoint',  'x' => 0,  'y' => 10, 'z' => 0],
		],
		'edges' => [
			['id' => 'e1', 'source' => 'a', 'target' => 'b', 'type' => 'rigid'],
			['id' => 'e2', 'source' => 'a', 'target' => 'c', 'type' => 'rigid'],
		],
	];
}

/** Квадрат: четыре угла геометрически одинаковы — дедупликация обязана это увидеть. */
function nj667_square()
{
	return [
		'meta'  => ['title' => 'Квадрат', 'units' => 'unit'],
		'nodes' => [
			['id' => 'a', 'type' => 'connector', 'x' => 0,  'y' => 0,  'z' => 0],
			['id' => 'b', 'type' => 'connector', 'x' => 10, 'y' => 0,  'z' => 0],
			['id' => 'c', 'type' => 'connector', 'x' => 10, 'y' => 10, 'z' => 0],
			['id' => 'd', 'type' => 'connector', 'x' => 0,  'y' => 10, 'z' => 0],
		],
		'edges' => [
			['id' => 'e1', 'source' => 'a', 'target' => 'b', 'type' => 'rigid'],
			['id' => 'e2', 'source' => 'b', 'target' => 'c', 'type' => 'rigid'],
			['id' => 'e3', 'source' => 'c', 'target' => 'd', 'type' => 'rigid'],
			['id' => 'e4', 'source' => 'd', 'target' => 'a', 'type' => 'rigid'],
		],
	];
}

// --- выбор типа и параметров --------------------------------------------------
$bs_o0 = Service_Nj667_Build::opt();
check('сборка: тип по умолчанию — палочки для мороженого', $bs_o0['type'], 'flat');
check('сборка: ширина палочки из конфига', $bs_o0['params']['stick_width'], 10.0);
check('сборка: качество по умолчанию — черновик (запрос 7)', $bs_o0['quality'], 'low');
check('сборка: $fn черновика', $bs_o0['fn'], 12);
check('сборка: сращивание узлов включено', $bs_o0['merge'], 1);
check('сборка: перфорация по умолчанию выключена', $bs_o0['perforation'], 0);
check('сборка: масштаб общий с чертежом (1 клетка = 10 мм)', $bs_o0['unit_size'], 10.0);
check_true('сборка: типы узлов отдаются справочником', isset(Service_Nj667_Build::types()['round']));
check_true('сборка: у каждого типа свой scad-файл', (string) Arr::get(Service_Nj667_Build::type('cross'), 'scad') === 'node_types/cross.scad');

$bs_o1 = Service_Nj667_Build::opt(['type' => 'кривой-тип', 'params' => ['stick_width' => 9999, 'хак' => 1]]);
check('сборка: неизвестный тип не роняет сборку', $bs_o1['type'], 'flat');
check('сборка: кламп сверху (иначе рендер вечный)', $bs_o1['params']['stick_width'], 60.0);
check_true('сборка: чужое имя параметра не проходит', ! isset($bs_o1['params']['хак']));

$bs_o2 = Service_Nj667_Build::opt(['type' => 'round', 'quality' => 'high', 'perforation' => 1,
	'params' => ['tolerance' => '-5', 'stick_diameter' => '7,5']]);
check('сборка: запятая в числе — это число', $bs_o2['params']['stick_diameter'], 7.5);
check('сборка: допуск не бывает отрицательным', $bs_o2['params']['tolerance'], 0.0);
check('сборка: качество high → $fn 64', $bs_o2['fn'], 64);
check('сборка: галочка перфорации прошла', $bs_o2['perforation'], 1);
check('сборка: масштаб из сохранённых настроек не теряется',
	Service_Nj667_Build::opt([], ['unit_size' => 24])['unit_size'], 24.0);

// --- габариты посадок (те же формулы, что в scad/core) ----------------------
$bs_d = Service_Nj667_Build::dims($bs_o0);
check('сборка: паз = сечение + допуск', $bs_d['slot_w'], 10.25);
check('сборка: ядро = большее из наружных', $bs_d['core_size'], 12.25);
check('сборка: «юбка» узла r_in', $bs_d['r_in'], 8.125);

// --- геометрия: единицы, оси, раскрой ---------------------------------------
$bs_g = Service_Nj667_Build::geometry(nj667_tri(), Service_Nj667_Build::opt(['unit_size' => 10]));
check_true('сборка: геометрия готова', ! empty($bs_g['ok']));
check('сборка: узлы в мм (1 клетка = 10 мм)', $bs_g['mm'][1], [100.0, 0.0, 0.0]);
check('сборка: вверх графа (Y) — это вверх OpenSCAD (Z)', $bs_g['mm'][2], [0.0, 0.0, 100.0]);
check('сборка: пролёт = расстояние минус обе юбки', $bs_g['edges'][0]['free'], 83.8);
check('сборка: заготовка = пролёт + обе посадки', $bs_g['edges'][0]['cut'], 97.8);
check('сборка: лист раскроя — одна длина, две штуки', $bs_g['sticks'], [['len' => 97.8, 'count' => 2, 'pair' => '0—1']]);
check('сборка: деталей столько же, сколько узлов', $bs_g['counts']['clusters'], 3);
check('сборка: склейки нет — merged = 0', $bs_g['counts']['merged'], 0);
check('сборка: уникальных типа два (угол и конец)', $bs_g['counts']['types'], 2);
check('сборка: габарит по X', $bs_g['span']['x'], 100.0);
check('сборка: габарит по высоте', $bs_g['span']['z'], 100.0);
check('сборка: у угла два паза', $bs_g['types'][1]['slots'], 2);

$bs_empty = Service_Nj667_Build::geometry(['nodes' => [['id' => 'a', 'x' => 0, 'y' => 0, 'z' => 0]], 'edges' => []], $bs_o0);
check_true('сборка: граф из одного узла — слово, а не исключение',
	$bs_empty['ok'] === FALSE AND $bs_empty['error'] !== '');
check('сборка: .scad пустой, когда геометрия не готова', Service_Nj667_Build::scad($bs_empty, $bs_o0), '');

// --- дедупликация: квадрат = одна деталь в четырёх копиях ---------------------
$bs_sq = Service_Nj667_Build::geometry(nj667_square(), Service_Nj667_Build::opt(['unit_size' => 10]));
check('сборка: 4 угла → 1 уникальный тип', $bs_sq['counts']['types'], 1);
check('сборка: копий типа — 4', $bs_sq['types'][0]['count'], 4);
check('сборка: угол между лучами 90°', $bs_sq['types'][0]['arms'], [90.0]);
check('сборка: пазов у угла — 2', $bs_sq['types'][0]['slots'], 2);
check('сборка: индексы типов идут по сигнатуре (ksort)', $bs_sq['types'][0]['i'], 0);

// --- склейка пересекающихся узлов в одну деталь ------------------------------
$bs_near = nj667_tri();
$bs_near['nodes'][2]['x'] = 0.5;
$bs_near['nodes'][2]['y'] = 0;             // 5 мм по X и ноль по Y: юбки по 8.125 мм входят друг в друга
$bs_mg = Service_Nj667_Build::geometry($bs_near, Service_Nj667_Build::opt(['unit_size' => 10]));
check('сборка: пересекающиеся узлы срослись', $bs_mg['counts']['clusters'], 2);
check('сборка: склейка сократила детали на 1', $bs_mg['counts']['merged'], 1);
check_true('сборка: склейка объяснена человеку',
	(bool) preg_match('~пересекаются~u', json_encode($bs_mg['issues'], JSON_UNESCAPED_UNICODE)));
check_true('сборка: связь внутри детали паза не режет', empty($bs_mg['edges'][1]['external']));
check_true('сборка: связь внутри детали не едет в раскрой',
	! in_array('0—2', array_column($bs_mg['sticks'], 'pair'), TRUE));
check('сборка: сращивание можно выключить',
	count(Service_Nj667_Build::geometry($bs_near, Service_Nj667_Build::opt(['unit_size' => 10, 'merge' => 0]))['clusters']), 3);

// --- сращивание по короткой палочке (запрос 11): свободно < min_span → один узел ---
// Как на скриншоте: между узлами 3.8 мм свободной палочки при сращивании «вкл».
$bs_short = nj667_tri();
$bs_short['nodes'][2]['x'] = 0;
$bs_short['nodes'][2]['y'] = 0;
$bs_short['nodes'][2]['z'] = 2.005;   // граф z → мм по Y: 20.05 мм от a; 20.05 − 2·r_in ≈ 3.8 мм
$bs_sh_on  = Service_Nj667_Build::geometry($bs_short, Service_Nj667_Build::opt(['unit_size' => 10]));
$bs_sh_off = Service_Nj667_Build::geometry($bs_short, Service_Nj667_Build::opt(['unit_size' => 10, 'merge' => 0]));
$bs_sh_free = $bs_sh_off['edges'][1]['free'];
check_true('сращивание: сценарий со скриншота — свободно меньше ширины луча (10 мм)', $bs_sh_free > 0 AND $bs_sh_free < 10.0);
check('сращивание: короткая палочка склеивает концы (деталей 2)', $bs_sh_on['counts']['clusters'], 2);
check('сращивание: без галки — узлы отдельно (деталей 3)', $bs_sh_off['counts']['clusters'], 3);
check_true('сращивание: замечания про свободную палочку больше нет',
	strpos(json_encode($bs_sh_on['issues'], JSON_UNESCAPED_UNICODE), 'свободной палочки') === FALSE);
check_true('сращивание: без галки предупреждение остаётся',
	strpos(json_encode($bs_sh_off['issues'], JSON_UNESCAPED_UNICODE), 'свободной палочки') !== FALSE);
// Контроль: палочка длинная — узлы не трогаем, даже при включённой галке.
$bs_long = nj667_tri();
$bs_long['nodes'][2]['x'] = 0;
$bs_long['nodes'][2]['y'] = 0;
$bs_long['nodes'][2]['z'] = 2.8;     // 28 мм: свободно ≈ 11.8 мм ≥ 10 (ширина луча)
// Нижний ярус со скриншота: свободно ≈ 7.8 мм — короче луча, склеиваем.
$bs_mid = nj667_tri();
$bs_mid['nodes'][2]['x'] = 0;
$bs_mid['nodes'][2]['y'] = 0;
$bs_mid['nodes'][2]['z'] = 2.405;   // 24.05 мм: свободно ≈ 7.8 мм < 10
check('сращивание: палочка 7.8 мм (короче луча) склеивает (деталей 2)',
	Service_Nj667_Build::geometry($bs_mid, Service_Nj667_Build::opt(['unit_size' => 10]))['counts']['clusters'], 2);
check('сращивание: длинная палочка не склеивает (деталей 3)',
	Service_Nj667_Build::geometry($bs_long, Service_Nj667_Build::opt(['unit_size' => 10]))['counts']['clusters'], 3);

// --- проверка собираемости ---------------------------------------------------
$bs_narrow = [
	'meta'  => ['title' => 'Острый узел'],
	'nodes' => [
		['id' => 'h',  'type' => 'hub',      'x' => 0, 'y' => 0,  'z' => 0],
		['id' => 'p1', 'type' => 'endpoint', 'x' => 0, 'y' => 20, 'z' => 0],
		['id' => 'p2', 'type' => 'endpoint', 'x' => 0, 'y' => 20, 'z' => 7.6],
	],
	'edges' => [
		['id' => 'e1', 'source' => 'h', 'target' => 'p1', 'type' => 'rigid'],
		['id' => 'e2', 'source' => 'h', 'target' => 'p2', 'type' => 'rigid'],
	],
];
$bs_sharp = Service_Nj667_Build::geometry($bs_narrow, Service_Nj667_Build::opt(['unit_size' => 10]));
$bs_hub = NULL;

foreach ((array) $bs_sharp['types'] as $bs_t)
{
	if ((int) $bs_t['slots'] === 2)
	{
		$bs_hub = $bs_t;
	}
}

check('сборка: острый угол измерен (сетка 0.5°)', Arr::get($bs_hub, 'min_angle'), 21.0);
check_true('сборка: соосные лучи (0°) тоже пойманы',
	(bool) preg_match('~0..;|0°~u', json_encode($bs_mg['issues'], JSON_UNESCAPED_UNICODE)));
check_true('сборка: острый угол — замечание, а не молчание',
	(bool) preg_match('~меньше~u', json_encode($bs_sharp['issues'], JSON_UNESCAPED_UNICODE)));

$bs_broken = nj667_tri();
$bs_broken['edges'][] = ['id' => 'e9', 'source' => 'a', 'target' => 'нет-такого', 'type' => 'rigid'];
check('сборка: связь в никуда не участвует',
	Service_Nj667_Build::geometry($bs_broken, $bs_o0)['counts']['edges'], 2);

$bs_two = Service_Nj667_Build::geometry([
	'meta'  => ['title' => 'Две палки'],
	'nodes' => [
		['id' => 'a', 'x' => 0, 'y' => 0, 'z' => 0], ['id' => 'b', 'x' => 10, 'y' => 0, 'z' => 0],
		['id' => 'c', 'x' => 0, 'y' => 0, 'z' => 20], ['id' => 'd', 'x' => 10, 'y' => 0, 'z' => 20],
	],
	'edges' => [['source' => 'a', 'target' => 'b'], ['source' => 'c', 'target' => 'd']],
], $bs_o0);
check_true('сборка: две несвязанные части — предупреждение',
	count($bs_two['issues']) > 0);

// --- .scad: один файл, без подключений, без echo, данные цифра в цифру ------

$bs_lib = Service_Nj667_Build::scad($bs_g, Service_Nj667_Build::opt(['unit_size' => 10]), 'стенд');
check_true('scad: библиотека модуля на месте', Service_Nj667_Build::scad_missing('flat') === []);
check_true('scad: файл собран', strlen($bs_lib) > 1000);
check_true('scad: нет echo (спецификацию считает сайт)', strpos($bs_lib, 'echo(') === FALSE);
check_true('scad: нет include/use (воркер пустит только BOSL2)',
	stripos($bs_lib, 'include <') === FALSE AND stripos($bs_lib, 'use <') === FALSE);
check('scad: узлы в мм и в правильном порядке осей',
	(bool) preg_match('~NODES = \[\[0, 0, 0\], \[100, 0, 0\], \[0, 0, 100\]\];~', $bs_lib), TRUE);
check('scad: связи, кластеры, типы, уникальные детали',
	(bool) preg_match('~EDGES = \[\[0, 1\], \[0, 2\]\];~', $bs_lib)
	AND (bool) preg_match('~CL    = \[0, 1, 2\];~', $bs_lib)
	AND (bool) preg_match('~CTYPE = \[1, 0, 0\];~', $bs_lib)
	AND (bool) preg_match('~UNIQ  = \[1, 0\];~', $bs_lib), TRUE);
check_true('scad: числа без хвостовых нулей', strpos($bs_lib, '0.000000') === FALSE);
check_true('scad: параметры типа узла', strpos($bs_lib, 'stick_width = 10;') !== FALSE);
check_true('scad: режим, $fn и флаг перфорации',
	strpos($bs_lib, 'mode        = "assembly";') !== FALSE
	AND strpos($bs_lib, '$fn         = 12;') !== FALSE
	AND strpos($bs_lib, 'perforation = false;') !== FALSE);
check_true('scad: профиль из файла типа, коннектор и генератор склеены',
	strpos($bs_lib, 'module stick_profile()') !== FALSE
	AND strpos($bs_lib, 'module connector(arms)') !== FALSE
	AND strpos($bs_lib, 'module assembly()') !== FALSE);
check_true('scad: заголовок называет проект', strpos($bs_lib, 'стенд') !== FALSE);
$bs_one = Service_Nj667_Build::scad($bs_g, Service_Nj667_Build::opt(['unit_size' => 10, 'mode' => 'single', 'part' => 2]));
check_true('scad: режим single с индексом детали',
	strpos($bs_one, 'mode        = "single";') !== FALSE AND strpos($bs_one, 'part_index  = 2;') !== FALSE);
check('scad: чужой режим не проходит', Service_Nj667_Build::opt(['mode' => 'rm -rf'])['mode'], 'assembly');

// --- узлы к печати: план пачки и .scad на одну деталь -----------------------
$bs_opt10 = Service_Nj667_Build::opt(['unit_size' => 10]);
$bs_plan  = Service_Nj667_Build::parts_plan($bs_g, $bs_opt10, 0);
check('план: уникальных типов столько же, сколько в спецификации', $bs_plan['total'], 2);
check('план: первый узел — №0 (ноль это узел, а не «нет»)', $bs_plan['parts'][0]['part'], 0);
// В треугольнике type #0 — это «концы» (2 шт.), #1 — угол (1 шт.): copies
// обязано совпадать с числом узлов, иначе в цех уедет меньше деталей, чем в
// ведомости.
check('план: копий — столько, сколько узлов этого типа', $bs_plan['parts'][0]['copies'], 2);
check('план: copies не бывает нулём', Service_Nj667_Build::parts_plan($bs_sq, $bs_opt10, 0)['parts'][0]['copies'], 4);
check('план: тип узла подписан человеком', $bs_plan['parts'][0]['type'], 'flat');
check('план: лимит пачки режет список', count(Service_Nj667_Build::parts_plan($bs_g, $bs_opt10, 1)['parts']), 1);
check('план: отрезанное считается, а не теряется', Service_Nj667_Build::parts_plan($bs_g, $bs_opt10, 1)['skipped'], 1);
check('план: пустая геометрия — пустая пачка', Service_Nj667_Build::parts_plan($bs_empty, $bs_opt10, 0)['parts'], []);
// Окно пачки: готовые типы вычитаются ДО лимита, иначе следующие за
// parts_max никогда не дожидаются очереди (жалоба владельца, 13.10.2026).
$bs_win = Service_Nj667_Build::parts_plan($bs_g, $bs_opt10, 1, [0 => TRUE]);
check('план: готовые типы не мешают окну сдвинуться', (int) $bs_win['parts'][0]['part'], 1);
check('план: «на диске» посчитано', (int) $bs_win['done'], 1);
check('план: после вычитания лимит режет остаток', (int) Service_Nj667_Build::parts_plan(
	$bs_g, $bs_opt10, 1, [0 => TRUE])['skipped'], 0);
check('план: всё на диске — пачки нет',
	Service_Nj667_Build::parts_plan($bs_g, $bs_opt10, 12, [0 => TRUE, 1 => TRUE])['parts'], []);
check('план: без готовых типов skipped — это отрезанное лимитом',
	(int) Service_Nj667_Build::parts_plan($bs_g, $bs_opt10, 1)['skipped'], 1);
$bs_full_run_plan = ['parts' => [
	['part' => 2], ['part' => 3], ['part' => 4], ['part' => 5],
], 'total' => 8, 'done' => 2, 'skipped' => 0];
$bs_run_window = Service_Nj667_Build::parts_run_window($bs_full_run_plan, [3, 4]);
check('план: продолжение держится в пределах выбранных типов',
	array_column($bs_run_window['parts'], 'part'), [3, 4]);
check('план: новые типы не подмешиваются в повторную пачку', $bs_run_window['skipped'], 4);
check('лимит: размер пачки из конфига', Service_Nj667_Build::limit('parts_max', 0), 30);
check('лимит: профильный размер одного запуска',
	Service_Nj667_Graph3d::build_parts_run_limit(42), 30);
$bs_many_types = ['types' => []];
for ($bs_i = 0; $bs_i < 40; $bs_i++)
{
	$bs_many_types['types'][] = ['i' => $bs_i, 'count' => 1, 'slots' => 1,
		'arms' => [1], 'size' => 10];
}
$bs_capped_run = Service_Nj667_Build::parts_plan($bs_many_types, $bs_opt10,
	Service_Nj667_Graph3d::build_parts_run_limit(42));
check('лимит: 40 типов при max_nodes=400 режутся до 30 за запуск',
	count($bs_capped_run['parts']), 30);
check('лимит: десять типов можно запустить отдельно', $bs_capped_run['skipped'], 10);
check_true('лимит: бюджет пачки не меньше одного рендера',
	Service_Nj667_Build::limit('parts_budget_s', 0) >= 900);

$bs_part_scad = Service_Nj667_Build::scad_for_part($bs_g, $bs_opt10, 1, 'узел 1');
check_true('scad узла: режим single и нужный индекс',
	strpos($bs_part_scad, 'mode        = "single";') !== FALSE
	AND strpos($bs_part_scad, 'part_index  = 1;') !== FALSE);
check_true('scad узла: это НЕ сборка', strpos($bs_part_scad, 'mode        = "assembly";') === FALSE);
check_true('scad узла: в подписи назван узлом', strpos($bs_part_scad, 'узел 1') !== FALSE);
check('scad узла: данные те же, что у сборки',
	(bool) preg_match('~UNIQ  = \[1, 0\];~', $bs_part_scad), TRUE);
$bs_part0 = Service_Nj667_Build::scad_for_part($bs_g, $bs_opt10, 0);
check_true('scad узла №0 отличается от узла №1', $bs_part0 !== $bs_part_scad
	AND strpos($bs_part0, 'part_index  = 0;') !== FALSE);

// --- спецификация, чертёж узла, STL ------------------------------------------
$bs_csv = Service_Nj667_Build::spec_csv($bs_sq, Service_Nj667_Build::opt(['unit_size' => 10]));
check_true('csv: BOM для Excel', substr($bs_csv, 0, 3) === "\xEF\xBB\xBF");
// stripos в PHP кириллицу не складывает — только точное вхождение.
check_true('csv: детали и раскрой', strpos($bs_csv, 'ДЕТАЛИ НА ПЕЧАТЬ') !== FALSE
	AND strpos($bs_csv, 'лист раскроя') !== FALSE);
check_true('csv: копий четыре и длина палочки', (bool) preg_match('~#0;4;~u', $bs_csv)
	AND strpos($bs_csv, '97,8;4') !== FALSE);

$bs_svg = Service_Nj667_Build::part_svg($bs_sq, Service_Nj667_Build::opt(['unit_size' => 10]), 0);
check_true('чертёж узла: svg', strpos($bs_svg, '<svg xmlns') === 0);
check_true('чертёж узла: копии и паз', strpos($bs_svg, '4 шт') !== FALSE AND strpos($bs_svg, 'паз') !== FALSE);
check_true('чертёж узла: размер паза в мм', strpos($bs_svg, '10,25') !== FALSE);
check('чертёж узла: несуществующего типа нет', Service_Nj667_Build::part_svg($bs_sq, $bs_o0, 9), '');

// Чертёж узла — лист А4 по ГОСТ: три вида, размерные линии со стрелками,
// штриховка сечения и штамп с честным масштабом (не «1:1» на глазок).
check_true('чертёж узла: лист A4 и рамка', strpos($bs_svg, 'viewBox="0 0 900 636"') !== FALSE
	AND strpos($bs_svg, 'width="297mm"') !== FALSE);
check_true('чертёж узла: изометрия и разрез подписаны', strpos($bs_svg, 'Изометрия') !== FALSE
	AND (bool) preg_match('~Сечение А–А \(увеличено [\d,]+×~u', $bs_svg));
check_true('чертёж узла: размерные линии со стрелками', substr_count($bs_svg, '<polygon') >= 8
	AND substr_count($bs_svg, 'stroke-width="0.7"') >= 8);
check_true('чертёж узла: размер луча — реальная длина, не проекция',
	strpos($bs_svg, '>100<') !== FALSE);
check_true('чертёж узла: штамп и масштаб плана', strpos($bs_svg, 'Наименование:') !== FALSE
	AND strpos($bs_svg, 'Масштаб:</tspan> 1:2,6<') !== FALSE);
check_true('чертёж узла: штриховка сечения, клетка — только если нарисована',
	strpos($bs_svg, 'nj667-hatch') !== FALSE
	AND (strpos($bs_svg, '1 клетка') === FALSE OR strpos($bs_svg, 'url(#nj667-grid)') !== FALSE));

$bs_stl = str_repeat(' ', 80).pack('V', 2)
	.pack('f12', 0, 0, 1,  0, 0, 0,  10, 0, 0,  0, 10, 0).pack('v', 0)
	.pack('f12', 0, 0, 1,  10, 0, 0,  10, 10, 0, 0, 10, 0).pack('v', 0);
$bs_stat = Service_Nj667_Build::stl_stats($bs_stl);
check_true('stl: разобран', ! empty($bs_stat['ok']));
check('stl: граней 2', $bs_stat['triangles'], 2);
check('stl: габарит 10×10×0', $bs_stat['dims'], [10.0, 10.0, 0.0]);
check('stl: нижняя грань на нуле', $bs_stat['min'], [0.0, 0.0, 0.0]);
check('stl: на 2 гранях выборка не нужна', $bs_stat['sampled'], FALSE);
check('stl: размер файла', $bs_stat['size'], 184);
check_true('stl: обрезанный — ошибка, а не пустая модель',
	strpos(Service_Nj667_Build::stl_stats(substr($bs_stl, 0, 120))['error'], 'Обрезан') !== FALSE);
check_true('stl: пустой CSG — внятное слово',
	strpos(Service_Nj667_Build::stl_stats(str_repeat(' ', 80).pack('V', 0))['error'], 'ни одной грани') !== FALSE);
check_true('stl: мусор не проходит', Service_Nj667_Build::stl_stats('абвгдеж')['ok'] === FALSE);

$bs_pack = Service_Nj667_Build::spec_pack($bs_g);
check_true('spec: координаты в базу не едут', ! isset($bs_pack['mm']) AND ! isset($bs_pack['arms']));
check('spec: количество деталей есть', $bs_pack['counts']['clusters'], 3);
check('spec: размеры посадок в packed', isset($bs_pack['dims']['r_in']), TRUE);
check('spec: параметры для повтора сборки', Service_Nj667_Build::params_pack($bs_o1)['type'], 'flat');

check('хеш графа: перестановка узлов не меняет', Service_Nj667_Build::graph_hash(nj667_tri()),
	Service_Nj667_Build::graph_hash(['meta' => nj667_tri()['meta'],
		'nodes' => array_reverse(nj667_tri()['nodes']), 'edges' => nj667_tri()['edges']]));
check_true('хеш графа: другой граф — другой хеш',
	Service_Nj667_Build::graph_hash(nj667_tri()) !== Service_Nj667_Build::graph_hash(nj667_square()));

// --- дверь модуля и обвязка --------------------------------------------------
foreach (['build_opt', 'build_geometry', 'build_scad', 'build_create', 'build_current', 'build_get',
	'build_history', 'build_queue', 'build_attach_stl', 'build_mark', 'build_scad_text', 'build_file_path',
	'build_stale', 'build_prefs', 'set_build_prefs', 'build_types', 'build_spec_csv', 'build_part_svg',
	'storage_dir'] as $bs_m)
{
	check_true('дверь: '.$bs_m.' есть', method_exists('Service_Nj667_Graph3d', $bs_m));
}

check_true('очередь: канал сборки объявлен', Service_Nj667_Graph3d::CH_BUILD === 'nj667.build');
check_true('лимиты сборки читаются из конфига', (int) Service_Nj667_Build::limit('max_nodes', 0) === 400);
// 280 КБ, а не 600: воркер отбраковывает код длиннее 300 000 символов
// (scad.py), и человек получил бы «рендер не удался» вместо сборки.
check('scad-файл влезает в лимит воркера', (int) Service_Nj667_Build::limit('scad_max_kb', 0) <= 290, TRUE);
// Рендер CSG — минуты. Общий лимит генерации убьёт купол на середине, и
// человек получит «модель не собралась» вместо модели.
check('таймаут сборки отдан очереди', Service_Nj667_Graph3d::timeout('build'), 900);

foreach (['flat', 'round', 'cross', 'tube'] as $bs_k)
{
	check_true('конфиг: тип '.$bs_k.' описан', isset(Service_Nj667_Build::types()[$bs_k]['scad']));
}

$bs_ctrl = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php');
check_true('контроллер: страница сборки', strpos($bs_ctrl, 'function action_build') !== FALSE);
check_true('контроллер: файл отдаётся по пути из строки', strpos($bs_ctrl, 'build_file_path') !== FALSE);

$bs_api = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Api.php');
$bs_view = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/build.php');
check_true('api: сборка', strpos($bs_api, 'function action_build') !== FALSE);

$bs_w = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Worker.php');
check_true('воркер: контекст сборки', strpos($bs_w, 'function action_build') !== FALSE);
check_true('воркер: приёмка STL', strpos($bs_w, 'function action_build_file') !== FALSE);
check_true('воркер: итог сборки', strpos($bs_w, 'function action_build_result') !== FALSE);

$bs_py = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/handlers/nj667_graph3d.py');
check_true('воркер: канал сборки в обработчике', strpos($bs_py, 'nj667.build') !== FALSE);
check_true('воркер: рендер собран без LLM', strpos($bs_py, 'async def handle_build') !== FALSE
	AND strpos($bs_py, 'NJ667_BUILD_TIMEOUT') !== FALSE);
check_true('воркер: openscad берётся из scad.py (не дублируем)', strpos($bs_py, 'stl_of(') !== FALSE);
check_true('воркер: нет движка — честное слово', strpos($bs_py, 'no_engine') !== FALSE);
$bs_reg = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/handlers/__init__.py');
check_true('воркер: канал зарегистрирован', strpos($bs_reg, '"nj667.build"') !== FALSE);

// --- страница сборки (вьюха обязана рендериться и говорить правду) -----------
$bs_vars = [
	'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
	'graph'   => nj667_tri(),
	'geom'    => $bs_g,
	'opt'     => $bs_o0,
	'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'done', 'type_key' => 'flat',
		'stl_size' => 12345, 'render_ms' => 4200, 'job_id' => 0],
	'history' => [['id' => 6, 'status' => 'error', 'type_key' => 'flat',
		'created_at' => '2026-10-12 09:00', 'stl_size' => 0, 'error' => 'openscad не найден']],
	'stale'   => TRUE,
	'owner'   => TRUE,
	'can_moderate' => TRUE,
	'csrf'    => 'stand-csrf-token',
	'error'   => '',
	'part_rows' => [
		['part_index' => 0, 'status' => 'done', 'stl_size' => 2097152, 'copies' => 1, 'error' => ''],
		['part_index' => 1, 'status' => 'error', 'stl_size' => 0, 'copies' => 2,
			'error' => 'openscad: ERROR: CGAL failed'],
	],
	'plan'   => ['parts' => [['part' => 0], ['part' => 1]], 'total' => 4, 'skipped' => 2,
		'done' => 0],
	'schema' => ['glb' => TRUE, 'parts' => TRUE],
];

$bs_page = render('nj667/build', $bs_vars);
$bs_auto_page = render('nj667/build', array_merge($bs_vars, [
	'simple_flow' => ['enabled' => TRUE, 'state' => 'partial', 'build_id' => 7,
		'error' => 'детали: очередь не доступна'],
]));
contains('сборка автопотока: уведомление', $bs_auto_page, 'Автоматическая сборка');
contains('сборка автопотока: ошибка части', $bs_auto_page, 'детали: очередь не доступна');
contains('сборка: заголовок', $bs_page, 'Сборка');
contains('сборка: выбор типа узла', $bs_page, 'name="type" value="round"');
contains('сборка: качество дуг', $bs_page, 'name="quality"');
contains('сборка: параметры сечения', $bs_page, 'name="params[stick_width]"');
contains('сборка: галка сращивания', $bs_page, 'name="merge" value="1"');
contains('сборка: hidden-ноль у галки', $bs_page, 'name="merge" value="0"');
contains('сборка: кнопка в STL', $bs_page, 'name="render" value="1"');
contains('сборка: csrf', $bs_page, 'name="csrf"');
contains('сборка: просмотрщик подключён', $bs_page, '/assets/js/nj667-stl.js');
contains('сборка: у скрипта есть cache-buster (пережить правку без Ctrl+F5)', $bs_page,
	'/assets/js/nj667-stl.js?v=');
contains('сборка: importmap three', $bs_page, '"three": "/assets/vendor/three/mod/three.module.js"');
contains('сборка: блок просмотрщика', $bs_page, 'id="nj667-stl"');
contains('сборка: STL и .scad рядом', $bs_page, '?file=stl&amp;build=7');
contains('сборка: скачивание scad', $bs_page, '?format=scad&amp;build=7');
contains('сборка: спецификация csv', $bs_page, '?format=csv');
contains('сборка: чертёж узла', $bs_page, '?part=1&amp;format=svg');
contains('сборка: габарит в мм', $bs_page, 'Габарит, мм');
contains('сборка: лист раскроя', $bs_page, 'лист раскроя');
contains('сборка: число копий типа', $bs_page, 'Копий');
contains('сборка: про устаревшую сборку', $bs_page, 'пересоберите');
contains('сборка: история сборок', $bs_page, '#6');
contains('сборка: ошибка из истории', $bs_page, 'openscad не найден');
contains('сборка: ссылка на чертёж', $bs_page, '/graph3d/d/ab34cd56ef78');
contains('сборка: колонка «К печати»', $bs_page, 'К печати');
contains('сборка: узел №0 помечен нулём', $bs_page, 'data-part="0"');
contains('сборка: готовый узел — STL', $bs_page, '?part=0&amp;file=stl&amp;build=7');
contains('сборка: размер STL узла', $bs_page, 'МБ');
contains('сборка: сорванный узел — своя ошибка', $bs_page, 'CGAL failed');
contains('сборка: кнопка пачки узлов', $bs_page, 'name="parts" value="1"');
contains('сборка: кнопка знает, сколько осталось', $bs_page, 'осталось 2');
$bs_svc = (string) file_get_contents(N2J_STAND_ROOT
	.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php');
// Схема БД важнее «кнопка не работает»: без миграций 009/010 цвет и детали не
// появятся НИКОГДА, и страница обязана сказать это прямо (владелец, 13.10.2026).
$bs_state = Service_Nj667_Graph3d::build_schema_state();
check('схема: состояние — два признака', implode(',', array_keys($bs_state)), 'glb,parts');
check_true('схема: оба признака — bool', is_bool($bs_state['glb']) AND is_bool($bs_state['parts']));
// array_merge, не «+»: у «+» левый набор важнее, и schema не переопределился бы.
$bs_noschema = render('nj667/build', array_merge($bs_vars,
	['schema' => ['glb' => FALSE, 'parts' => FALSE]]));
check_true('схема: без миграций страница называет причину, а не молчит',
	strpos($bs_noschema, 'make migrate') !== FALSE
	AND strpos($bs_noschema, 'glb_path') !== FALSE
	AND strpos($bs_noschema, 'nj_667_build_parts') !== FALSE);
check_true('схема: на здоровой базе не пугаем почём зря',
	strpos($bs_page, 'make migrate') === FALSE);
check_true('пачка: без таблицы узлов кнопка объясняет, а не молчит',
	strpos($bs_svc, 'В базе нет таблицы nj_667_build_parts') !== FALSE);
contains('сборка: у пачки свой csrf', $bs_page, 'name="csrf" value="stand-csrf-token"');
check_true('сборка: пачка не обещает перерендер готового',
	strpos($bs_page, 'не перерендериваются') !== FALSE);

$bs_parts_page = render('nj667/build', [
	'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
	'geom'    => $bs_g,
	'opt'     => $bs_o0,
	'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'spec', 'stl_size' => 0],
	'owner'   => TRUE,
	'csrf'    => 'stand-csrf-token',
]);
contains('сборка: без заказа узлов сказано «не заказывали»', $bs_parts_page, 'не заказывали');
contains('сборка: пачка считается по типам', $bs_parts_page, 'Собрать узлы к печати (2)');
check_true('сборка: узлы показаны и без готового STL сборки',
	strpos($bs_parts_page, 'К печати') !== FALSE);

$bs_guest = render('nj667/build', [
	'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
	'geom'    => $bs_g,
	'opt'     => $bs_o0,
	'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'done', 'stl_size' => 12345, 'render_ms' => 0],
	'owner'   => FALSE,
	'csrf'    => 'stand-csrf-token',
	'part_rows' => [
		['part_index' => 0, 'status' => 'done', 'stl_size' => 1000, 'copies' => 1, 'error' => ''],
		['part_index' => 1, 'status' => 'queued', 'stl_size' => 0, 'copies' => 2, 'error' => ''],
	],
]);
check_true('сборка: гостю не дают форму пересборки', strpos($bs_guest, 'name="csrf"') === FALSE);
check_true('сборка: гость видит модель и спецификацию',
	strpos($bs_guest, '?file=stl&amp;build=7') !== FALSE AND strpos($bs_guest, 'Габарит, мм') !== FALSE);
check_true('сборка: гость забирает готовый STL узла',
	strpos($bs_guest, '?part=0&amp;file=stl') !== FALSE);
contains('сборка: гость видит, что узел в очереди', $bs_guest, 'в очереди');
contains('сборка: недоделанному узлу дают .scad', $bs_guest, '?part=1&amp;format=scad&amp;build=7');
check_true('сборка: гость не ставит пачку в очередь', strpos($bs_guest, 'name="parts"') === FALSE);

$bs_broken_page = render('nj667/build', [
	'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Пусто', 'status' => 'draft'],
	'geom'    => Service_Nj667_Build::geometry(['nodes' => [], 'edges' => []], $bs_o0),
	'opt'     => $bs_o0,
	'build'   => NULL,
	'owner'   => TRUE,
	'csrf'    => 'stand-csrf-token',
	'error'   => '',
]);
check_true('сборка: пустой граф — внятная строка на странице',
	strpos($bs_broken_page, 'не') !== FALSE AND strlen($bs_broken_page) > 1500);

$bs_mig = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/migrations_pg/pgMigration_Nj_667_008_Graph3d_Builds.php');
check_true('миграция 008: таблица сборок', strpos($bs_mig, 'CREATE TABLE IF NOT EXISTS nj_667_builds') !== FALSE);
check_true('миграция 008: есть down', strpos($bs_mig, 'DROP TABLE IF EXISTS nj_667_builds') !== FALSE);

$bs_mig9 = (string) file_get_contents(N2J_STAND_ROOT
	.'/application/modules/nj667_graph3d/migrations_pg/pgMigration_Nj_667_009_Graph3d_BuildParts.php');
check_true('миграция 009: таблица узлов к печати',
	strpos($bs_mig9, 'CREATE TABLE IF NOT EXISTS nj_667_build_parts') !== FALSE);
check_true('миграция 009: один тип — одна строка',
	strpos($bs_mig9, 'CREATE UNIQUE INDEX IF NOT EXISTS nj_667_build_parts_uniq') !== FALSE);
check_true('миграция 009: есть down', strpos($bs_mig9, 'DROP TABLE IF EXISTS nj_667_build_parts') !== FALSE);
$bs_mig12 = (string) file_get_contents(N2J_STAND_ROOT
	.'/application/modules/nj667_graph3d/migrations_pg/pgMigration_Nj_667_012_Graph3d_DailyQuota.php');
check_true('миграция 012: дневной счётчик уникален по пользователю, дню и операции',
	strpos($bs_mig12, 'PRIMARY KEY (user_id, usage_day, operation)') !== FALSE);
check_true('миграция 012: отрицательный расход запрещён', strpos($bs_mig12, 'CHECK (used >= 0)') !== FALSE);
$bs_mig13 = (string) file_get_contents(N2J_STAND_ROOT
	.'/application/modules/nj667_graph3d/migrations_pg/pgMigration_Nj_667_013_Graph3d_PartsRun.php');
check_true('миграция 013: номер запуска хранится на сборке и детали',
	strpos($bs_mig13, 'nj_667_builds') !== FALSE
	AND strpos($bs_mig13, 'nj_667_build_parts') !== FALSE
	AND substr_count($bs_mig13, 'ADD COLUMN IF NOT EXISTS parts_run_id') === 2);
$bs_door = (string) file_get_contents(N2J_STAND_ROOT
	.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php');
check_true('дверь: methods сборок на месте', strpos($bs_door, 'build_parts_render') !== FALSE
	AND strpos($bs_door, 'build_parts_context') !== FALSE
	AND strpos($bs_door, 'build_part_attach_stl') !== FALSE
	AND strpos($bs_door, 'build_parts_result') !== FALSE);
check_true('детали: автопродолжение привязано к parts_run_id',
	strpos($bs_door, 'parts_run_window($full, $run_parts)') !== FALSE
	AND strpos($bs_door, "'parts_run_id' => (int) \$run_id") !== FALSE
	AND strpos($bs_door, 'public static function build_parts_continue($build_id, $run_id = NULL)') !== FALSE
	AND strpos($bs_w, 'build_parts_continue($build_id, $run_id)') !== FALSE);
check_true('детали: очередь не расширяет окно сверх профиля',
	strpos($bs_door, 'build_parts_run_limit') !== FALSE
	AND strpos($bs_door, 'min($quota, $config)') !== FALSE);
check_true('детали: готовой считается только строка с STL на диске',
	strpos($bs_door, "AND trim((string) Arr::get(\$row, 'stl_path', '')) !== ''") !== FALSE);
check_true('воркер: пачка узлов — один рендер на тип', strpos($bs_py, 'handle_build_parts') !== FALSE);
check_true('воркер: бюджет пачки принадлежит сайту', strpos($bs_py, 'budget_s') !== FALSE);
check_true('воркер: part уезжает в выгрузку', strpos($bs_py, 'form["part"]') !== FALSE);

// ---------------------------------------------------------------- GLB сборки
//
// Что человек видит в браузере: воркер лепит из STL glTF, страница читает его
// сама. STL остаётся печатным артефактом, поэтому здесь проверяются и права
// на скачивание, и галка «хранить STL», и то, что сайт не принимает битый
// glTF молча. Фикстура ниже — байты, которые выдал workers/n2j_workers/glb.py
// (три грани, склеены в 5 вершин): если формат разъедется, стенд красный.

$bs_glb = (string) base64_decode('Z2xURgIAAAAUBAAAbAMAAEpTT057ImFzc2V0Ijp7InZlcnNpb24iOiIyLjAiLCJnZW5lcmF0b3Ii'
	.'OiJuMmoucnUgbmo2NjcgKFNUTFx1MjE5MkdMQikifSwic2NlbmUiOjAsInNjZW5lcyI6W3sibm9k'
	.'ZXMiOlswXSwibmFtZSI6InN0YW5kIn1dLCJub2RlcyI6W3sibWVzaCI6MCwibmFtZSI6InN0YW5k'
	.'In1dLCJtZXNoZXMiOlt7Im5hbWUiOiJzdGFuZCIsInByaW1pdGl2ZXMiOlt7ImF0dHJpYnV0ZXMi'
	.'OnsiUE9TSVRJT04iOjAsIk5PUk1BTCI6MX0sImluZGljZXMiOjIsIm1hdGVyaWFsIjowfV19XSwi'
	.'bWF0ZXJpYWxzIjpbeyJuYW1lIjoicHJpbnQiLCJwYnJNZXRhbGxpY1JvdWdobmVzcyI6eyJiYXNl'
	.'Q29sb3JGYWN0b3IiOlswLjgyLDAuNzIsMC41NSwxLjBdLCJtZXRhbGxpY0ZhY3RvciI6MC4wLCJy'
	.'b3VnaG5lc3NGYWN0b3IiOjAuODV9fV0sImFjY2Vzc29ycyI6W3siYnVmZmVyVmlldyI6MCwiY29t'
	.'cG9uZW50VHlwZSI6NTEyNiwiY291bnQiOjUsInR5cGUiOiJWRUMzIiwibWluIjpbMC4wLDAuMCww'
	.'LjBdLCJtYXgiOlsxMC4wLDEwLjAsNS4wXX0seyJidWZmZXJWaWV3IjoxLCJjb21wb25lbnRUeXBl'
	.'Ijo1MTI2LCJjb3VudCI6NSwidHlwZSI6IlZFQzMifSx7ImJ1ZmZlclZpZXciOjIsImNvbXBvbmVu'
	.'dFR5cGUiOjUxMjMsImNvdW50Ijo5LCJ0eXBlIjoiU0NBTEFSIn1dLCJidWZmZXJWaWV3cyI6W3si'
	.'YnVmZmVyIjowLCJieXRlT2Zmc2V0IjowLCJieXRlTGVuZ3RoIjo2MCwidGFyZ2V0IjozNDk2Mn0s'
	.'eyJidWZmZXIiOjAsImJ5dGVPZmZzZXQiOjYwLCJieXRlTGVuZ3RoIjo2MCwidGFyZ2V0IjozNDk2'
	.'Mn0seyJidWZmZXIiOjAsImJ5dGVPZmZzZXQiOjEyMCwiYnl0ZUxlbmd0aCI6MjAsInRhcmdldCI6'
	.'MzQ5NjN9XSwiYnVmZmVycyI6W3siYnl0ZUxlbmd0aCI6MTQwfV19ICCMAAAAQklOAAAAAAAAAAAA'
	.'AAAAAAAAIEEAAAAAAAAAAAAAAAAAACBBAAAAAAAAIEEAACBBAAAAAAAAAAAAAAAAAACgQC755D4A'
	.'AAAALvlkPwAAAAAAAAAAAACAP0JbeD4AAAAAQlt4PwAAAAAAAAAAAACAPwAAgD8AAAAAAAAAAAAA'
	.'AQACAAEAAwACAAAAAgAEAAAA');

$bs_gstats = Service_Nj667_Build::glb_stats($bs_glb);

check('glb: файл конвертера принят', $bs_gstats['ok'], TRUE);
check('glb: граней столько, сколько индексов / 3', $bs_gstats['triangles'], 3);
check('glb: вершины склеены (5, а не 9)', $bs_gstats['vertices'], 5);
check('glb: габарит в мм как есть', $bs_gstats['dims'], [10.0, 10.0, 5.0]);
check('glb: размер = размер файла', $bs_gstats['size'], strlen($bs_glb));
check_true('glb: генератор подписан (видно, чем лепили)',
	strpos($bs_gstats['generator'], 'nj667') !== FALSE);

// Меняем первый байт: магия glTF уезжает, а длина файла остаётся — проверяем
// именно «это не GLB», а не обрезку.
$bs_bad = 'X'.substr($bs_glb, 1);
check('glb: не GLB назван не GLB', (bool) Service_Nj667_Build::glb_stats($bs_bad)['ok'], FALSE);
contains('glb: чужая сигнатура — внятное слово',
	(string) Arr::get(Service_Nj667_Build::glb_stats($bs_bad), 'error'), 'не GLB');
contains('glb: обрезанный файл назван обрезанным',
	(string) Arr::get(Service_Nj667_Build::glb_stats(substr($bs_glb, 0, 400)), 'error'), 'обрезан');
contains('glb: пустой файл — не «ноль граней, успех»',
	(string) Arr::get(Service_Nj667_Build::glb_stats(''), 'error'), 'короче заголовка');
contains('glb: версия не наша',
	(string) Arr::get(Service_Nj667_Build::glb_stats(
		substr($bs_glb, 0, 4).'3'.substr($bs_glb, 5)), 'error'), 'glTF 2.0');

// Два меша, внешний буфер, пустые атрибуты — то, что страница нарисовала бы
// наполовину. Правим JSON, а не байты фикстуры: длина описания зашита в
// заголовок, и подмена словом съела бы проверку на «обрезан» вместо смысла.
$glb_of = function (array $json, $bin_len = 140)
{
	$text = json_encode($json, JSON_UNESCAPED_SLASHES);
	$text .= str_repeat(' ', (4 - (strlen($text) % 4)) % 4);
	$bin  = str_repeat(chr(0), $bin_len);

	return pack('V3', 0x46546C67, 2, 12 + 8 + strlen($text) + 8 + strlen($bin))
		.pack('V2', strlen($text), 0x4E4F534A).$text
		.pack('V2', strlen($bin), 0x004E4942).$bin;
};

$bs_glb_two = $glb_of(['asset' => ['version' => '2.0'],
	'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]],
		['primitives' => [['attributes' => ['POSITION' => 0]]]]],
	'accessors' => [], 'bufferViews' => [], 'buffers' => [['byteLength' => 140]]]);

contains('glb: два меша — отказ, а не «первый и ладно»',
	(string) Arr::get(Service_Nj667_Build::glb_stats($bs_glb_two), 'error'), 'меш');

$bs_glb_uri = $glb_of(['asset' => ['version' => '2.0'],
	'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]],
	'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3'],
		['bufferView' => 1, 'componentType' => 5123, 'count' => 3, 'type' => 'SCALAR']],
	'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 36],
		['buffer' => 0, 'byteOffset' => 36, 'byteLength' => 6]],
	'buffers' => [['byteLength' => 140, 'uri' => 'data.bin']]]);

contains('glb: внешний буфер отвергнут (страница его не догрузит)',
	(string) Arr::get(Service_Nj667_Build::glb_stats($bs_glb_uri), 'error'), 'внешни');

$bs_glb_nopos = $glb_of(['asset' => ['version' => '2.0'],
	'meshes' => [['primitives' => [['attributes' => []]]]],
	'accessors' => [], 'bufferViews' => [], 'buffers' => [['byteLength' => 140]]]);

contains('glb: без POSITION нечего рисовать — так и сказано',
	(string) Arr::get(Service_Nj667_Build::glb_stats($bs_glb_nopos), 'error'), 'POSITION');

// 32-битные индексы по 4 байта: считай их по 2 — и обрезанный файл пройдёт
// проверку «данных хватает», а человек получит мусор вместо купола.
$bs_glb_i32 = ['asset' => ['version' => '2.0'],
	'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]],
	'accessors' => [['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3',
		'min' => [0, 0, 0], 'max' => [1, 1, 1]],
		['bufferView' => 1, 'componentType' => 5125, 'count' => 60, 'type' => 'SCALAR']],
	'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 36],
		['buffer' => 0, 'byteOffset' => 36, 'byteLength' => 240]],
	'buffers' => [['byteLength' => 276]]];

contains('glb: uint32 считаются по 4 байта',
	(string) Arr::get(Service_Nj667_Build::glb_stats($glb_of($bs_glb_i32, 140)), 'error'),
	'за конец бинарника');

$bs_glb_i32_ok = Service_Nj667_Build::glb_stats($glb_of($bs_glb_i32, 276));

check('glb: тот же файл с полным бинарником принят', $bs_glb_i32_ok['ok'], TRUE);
check('glb: 60 индексов — 20 граней', $bs_glb_i32_ok['triangles'], 20);

// ---- опция хранения STL
check('сборка: STL по умолчанию храним', $bs_o0['keep_stl'], 1);
check('сборка: галка «только смотреть» доезжает до опций',
	(int) Arr::get(Service_Nj667_Build::opt(['keep_stl' => 0], []), 'keep_stl'), 0);
check('сборка: keep_stl записан в params_json',
	(int) Arr::get(Service_Nj667_Build::params_pack($bs_o0), 'keep_stl'), 1);

$bs_cfg = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/config/nj667_graph3d.php');
contains('конфиг: NJ667_BUILD_KEEP_STL на месте', $bs_cfg, 'NJ667_BUILD_KEEP_STL');

$bs_env = (string) file_get_contents(N2J_STAND_ROOT.'/.env.example');
contains('.env.example: ключ keep_stl описан', $bs_env, 'NJ667_BUILD_KEEP_STL');

// ---- страница: GLB главный, STL — про печать
$bs_glb_page = render('nj667/build', [
	'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
	'graph'   => nj667_tri(),
	'geom'    => $bs_g,
	'opt'     => $bs_o0,
	'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'done', 'type_key' => 'flat',
		'stl_size' => 12345, 'glb_size' => 5432, 'render_ms' => 4200, 'job_id' => 0],
	'owner'   => TRUE,
	'csrf'    => 'stand-csrf-token',
	'error'   => '',
]);
contains('сборка: просмотр идёт на GLB', $bs_glb_page, 'data-kind="glb"');
contains('сборка: ссылка на GLB есть', $bs_glb_page, '?file=glb&amp;build=7');
contains('сборка: STL не прячем — кнопка скачивания на месте', $bs_glb_page, 'Скачать STL');
contains('сборка: скачивание — кнопка с иконкой, а не ссылка', $bs_glb_page, 'fa-download');
contains('сборка: группа кнопок — div (внутри живёт form)', $bs_glb_page,
	'<div class="ml-auto flex flex-wrap items-center gap-2">');
contains('сборка: GLB подписан именем формата', $bs_glb_page, 'Скачать GLB');
contains('сборка: иконка GLB из Font Awesome 6', $bs_glb_page, 'fa-file-zipper');
contains('сборка: формат подписан в метре', $bs_glb_page, 'glTF');
contains('сборка: галка хранения STL в форме', $bs_glb_page, 'name="keep_stl"');
check('сборка: у галки есть hidden-0 (иначе «снято» не отправить)',
	substr_count($bs_glb_page, 'name="keep_stl"'), 2);

$bs_noglb = render('nj667/build', [
	'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
	'graph'   => nj667_tri(),
	'geom'    => $bs_g,
	'opt'     => $bs_o0,
	'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'done', 'type_key' => 'flat',
		'stl_size' => 12345, 'glb_size' => 0, 'render_ms' => 0, 'job_id' => 0],
	'owner'   => TRUE,
	'csrf'    => 'stand-csrf-token',
	'error'   => '',
]);
contains('сборка: без GLB страница не пуста — читаем STL', $bs_noglb, 'data-kind="stl"');
check_true('сборка: битый конвертер не враньё в кнопках',
	strpos($bs_noglb, '?file=glb') === FALSE AND strpos($bs_noglb, 'Скачать GLB') === FALSE);

// Галку «Хранить STL» сняли — STL на диске нет, а печатать когда-то надо.
// Кнопка обязана предложить собрать его, а не исчезнуть вместе со ссылкой.
$bs_stl_absent = render('nj667/build', [
	'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
	'graph'   => nj667_tri(),
	'geom'    => $bs_g,
	'opt'     => $bs_o0,
	'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'done', 'type_key' => 'flat',
		'stl_size' => 0, 'glb_size' => 5432, 'render_ms' => 900, 'job_id' => 0,
		'error' => 'stl: STL не храним по слову сайта'],
	'owner'   => TRUE,
	'csrf'    => 'stand-csrf-token',
	'error'   => '',
	'part_rows' => [['part_index' => 0, 'status' => 'queued', 'stl_size' => 0, 'copies' => 2, 'error' => '']],
]);
contains('сборка: STL не хранили — кнопка предлагает собрать его', $bs_stl_absent, 'Собрать STL');
contains('сборка: кнопка «Собрать STL» ставит keep_stl', $bs_stl_absent, 'name="keep_stl" value="1"');
contains('сборка: кнопка «Собрать STL» идёт в рендер', $bs_stl_absent, 'name="render" value="1"');
check_true('сборка: без STL на диске ссылки на него нет',
	strpos($bs_stl_absent, 'data-src="/graph3d/b/ab34cd56ef78?file=stl') === FALSE);
contains('сборка: очередь пачки названа человеком', $bs_stl_absent, 'ещё в очереди');
contains('сборка: слово воркера видно и при успехе', $bs_stl_absent,
	'Модель готова (glTF), но воркер добавил: stl: STL не храним по слову сайта');

// ---- руки сервера: mime, вид поля, контракт API
$bs_js = (string) file_get_contents(N2J_STAND_ROOT.'/public_html/assets/js/nj667-stl.js');
contains('код: отдают glTF правильным mime', $bs_ctrl, 'model/gltf-binary');
contains('код: путь файла проверяется по виду', $bs_door, "in_array(\$kind, ['stl', 'glb'], TRUE)");
contains('код: приёмка GLB в двери', $bs_door, 'build_attach_glb');
contains('код: отсутствие миграции 010 не роняет сборку', $bs_door, 'build_glb_ready');
contains('код: сайт ждёт glb_size', $bs_api, "'glb_size'");
contains('код: воркер знает kind', $bs_w, "Arr::get(\$this->_body, 'kind', 'stl')");
contains('код: glb отдаётся по ?file=glb', $bs_ctrl, "\$want === 'glb'");
contains('код: контекст сборки просит glb', $bs_door, "'glb' => self::build_glb_ready()");

$bs_py_glb = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/glb.py');
contains('воркер: конвертер STL→GLB живёт в модуле', $bs_py_glb, 'def stl_to_glb');
contains('воркер: GLB проверяют до отправки', $bs_py_glb, 'def glb_info');
contains('воркер: glb едет отдельным kind', $bs_py, 'kind="glb"');
contains('воркер: STL хранят по слову сайта', $bs_py, 'keep_stl');
contains('воркер: отказ конвертера не убивает сборку', $bs_py, 'glb.GlbError');
contains('браузер: glTF читаем сами', $bs_js, 'function parseGlb');
contains('браузер: парсер доступен тестам', $bs_js, 'parseGlb,');

$bs_mig10 = (string) file_get_contents(N2J_STAND_ROOT
	.'/application/modules/nj667_graph3d/migrations_pg/pgMigration_Nj_667_010_BuildGlb.php');
contains('миграция 010: колонка glb_path', $bs_mig10, 'ADD COLUMN IF NOT EXISTS glb_path');
contains('миграция 010: колонка glb_size', $bs_mig10, 'ADD COLUMN IF NOT EXISTS glb_size');
check_true('миграция 010: down ничего не крошит',
	strpos($bs_mig10, 'DROP COLUMN') === FALSE);

	// ---- правки по живому стенду (11.10.2026, вечер).
	// 1) Снятая галка «Хранить STL» возвращалась: prefs — белый список флагов,
	//    keep_stl в него не попал, а opt() читает флаг именно из prefs.
	contains('дверь: keep_stl пишется в prefs', $bs_door,
		"'keep_stl'    => (int) Arr::get(\$opt, 'keep_stl', 1)");
	check_true('spec: снятый флаг читается из prefs, а не из дефолта конфига',
		(int) Arr::get(Service_Nj667_Build::opt([], ['keep_stl' => 0]), 'keep_stl', 1) === 0);
	// 2) Успех = хоть какой-то файл на диске: пустой stl_path при готовом glb
	//    писался как error, просмотрщик останавливался — цветной модели человек
	//    не видел.
	contains('воркер-эндпоинт: успех считаем по STL ИЛИ GLB', $bs_w,
		"\$have = (trim((string) Arr::get(\$build, 'stl_path', '')) !== ''");
	check_true('воркер-эндпоинт: «= … OR …» в скобках — иначе OR слабее присваивания',
		strpos($bs_w, "OR trim((string) Arr::get(\$build, 'glb_path', '')) !== '');") !== FALSE);
	check_true('воркер-эндпоинт: старой жалобы «STL не прислал» больше нет',
		strpos($bs_w, "'Воркер сообщил об успехе, но STL не прислал.'") === FALSE);
	// 3) Кнопка «Собрать узлы» уходит на /graph3d/b/xx без ?build= — id берём из
	//    строки, иначе ветка молчала и просмотрщик просил файл несобранной
	//    сборки (HTTP 404).
	contains('страница: id сборки для пачки — из строки', $bs_ctrl,
		"\$here       = (int) Arr::get(\$build, 'id', 0);");
	contains('страница: ветка пачки на том же условии, что и пересчёт', $bs_ctrl,
		'if ($parts_ask AND $here > 0)');
	contains('страница: пачку ставим и на свежесозданную сборку', $bs_ctrl,
		'Service_Nj667_Graph3d::build_parts_render($build_id, $project, $graph, $opt)');
	contains('страница: редирект несёт и число узлов пачки', $bs_ctrl,
		"'parts' => \$parts_n > 0 ? \$parts_n : NULL,");
	contains('воркер: без принятого GLB STL идёт в любом случае', $bs_py, 'or not glb_ok');
	// Просмотрщик обязан рисовать то, что положено рисовать: цвет glTF-файла
	// из файла, а не из-под нашей кисти — иначе GLB и STL неразличимы.
	contains('js: цвет читается из baseColorFactor glTF', $bs_js, 'const factor = Array.isArray(pbr.baseColorFactor) ? pbr.baseColorFactor : [];');
	contains('js: линейный цвет переводится в sRGB', $bs_js, 'color: glbColor(factor)');
	contains('js: материал glTF уходит в сцену', $bs_js, 'Number.isFinite(Number(want.color)) ? Number(want.color)');
	// Пачка узлов — не рендер модели: помечать сборку «done» после неё нельзя.
	$bs_fn_parts = '';

	if (preg_match('~function build_parts_result.*?\n\t\}~s', $bs_door, $bs_m))
	{
		$bs_fn_parts = $bs_m[0];
	}

	check_true('дверь: пачка узлов не трогает статус сборки',
		strpos($bs_fn_parts, 'build_mark(') === FALSE AND strlen($bs_fn_parts) > 200);
	contains('дверь: недобор пачки уходит вызывающему словом', $bs_fn_parts, "'note' => \$first");
	// Форма несёт id сборки в теле — на просмотре истории кнопка обязана
	// работать по той сборке, которую человек видит, а не по последней.
	contains('страница: id сборки берётся из тела формы', $bs_ctrl,
		"\$build_id = (int) Arr::get(\$src, 'build', 0);");
	contains('страница: пачка на свежей сборке тянет и рендер модели', $bs_ctrl,
		"if ( ! empty(\$src['render']) OR \$parts_ask)");
	// Скобки вокруг AND: «$x = a AND b» присваивает только a, «a AND b ? x : y»
	// теряет ветку. Оба были живыми багами в этом модуле — их ловит
	// php_ternary_lint, и он же не даёт их вернуть.
	$bs_proj = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/project.php');
	$bs_set  = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/settings.php');
	check_true('проект: оверлей ожидания считается целиком, а не первым операндом',
		strpos($bs_proj, '$waiting  = ($job_id > 0 AND') !== FALSE);
	check_true('настройки: своя модель доходит до поля, а не схлопывается в bool',
		strpos($bs_set, "HTML::chars((\$model !== '' AND ! \$known) ? \$model : '')") !== FALSE);
	// 4) Блок просмотра не обещает файл, которого нет.
	$bs_nofile = render('nj667/build', [
		'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
		'graph'   => nj667_tri(),
		'geom'    => $bs_g,
		'opt'     => $bs_o0,
		'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'done', 'type_key' => 'flat',
			'stl_size' => 0, 'glb_size' => 0, 'stl_path' => '', 'glb_path' => '', 'render_ms' => 0, 'job_id' => 0],
		'history' => [], 'stale' => FALSE, 'owner' => TRUE, 'csrf' => 'stand-csrf-token', 'error' => '',
		'part_rows' => [], 'plan' => ['parts' => [], 'total' => 0, 'skipped' => 0],
	]);
	contains('вьюха: нет файлов — пустой data-src', $bs_nofile, 'data-src=""');
	contains('вьюха: нет файлов — поллинг всё равно подключён', $bs_nofile,
		'data-state="done"');

	$bs_glbsrc = render('nj667/build', [
		'project' => ['id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Уголок', 'status' => 'published'],
		'graph'   => nj667_tri(),
		'geom'    => $bs_g,
		'opt'     => $bs_o0,
		'build'   => ['id' => 7, 'project_id' => 1, 'status' => 'error', 'type_key' => 'flat',
			'stl_size' => 0, 'glb_size' => 4096, 'stl_path' => '', 'glb_path' => 'var/glb/7.glb',
			'error' => 'Отказ приёмки: STL не прислан', 'render_ms' => 900, 'job_id' => 0],
		'history' => [], 'stale' => FALSE, 'owner' => TRUE, 'csrf' => 'stand-csrf-token', 'error' => '',
		'part_rows' => [], 'plan' => ['parts' => [], 'total' => 0, 'skipped' => 0],
	]);
	contains('вьюха: glb есть — смотрим glb', $bs_glbsrc,
		'data-src="/graph3d/b/ab34cd56ef78?file=glb&amp;build=7"');
	contains('вьюха: error с файлом на диске — не «рендер не удался»', $bs_glbsrc,
		'Модель на диске (glTF), но воркер добавил');
	contains('вьюха: текст ошибки воркера показан', $bs_glbsrc, 'Отказ приёмки: STL не прислан');


// ------------------------------------------------- цвет, эргономика, компактность
//
// Пять вопросов владельца от 13.10.2026: раскраска по типам узлов в браузере,
// «нажал «собрать узлы» — окно зависло», форма отвергала собственное значение
// по умолчанию, цифры липли к рамке, чертёж без изометрии и размеров. Тут то,
// что ломается тихо: приёмка glTF, лимит групп, сетка числовых полей и якоря,
// о которых договариваются вьюха и просмотрщик.

$bs_glb2 = $glb_of(['asset' => ['version' => '2.0', 'generator' => 'nj667 (STL/VRML->GLB)'],
	'meshes' => [['primitives' => [
		['attributes' => ['POSITION' => 0, 'NORMAL' => 1], 'indices' => 2, 'material' => 0],
		['attributes' => ['POSITION' => 3, 'NORMAL' => 4], 'indices' => 5, 'material' => 1],
	]]],
	'accessors' => [
		['bufferView' => 0, 'componentType' => 5126, 'count' => 4, 'type' => 'VEC3',
			'min' => [0, 0, 0], 'max' => [10, 10, 0]],
		['bufferView' => 0, 'componentType' => 5126, 'count' => 4, 'type' => 'VEC3'],
		['bufferView' => 0, 'componentType' => 5123, 'count' => 6, 'type' => 'SCALAR'],
		['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3',
			'min' => [0, 0, 5], 'max' => [10, 10, 5]],
		['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3'],
		['bufferView' => 0, 'componentType' => 5123, 'count' => 3, 'type' => 'SCALAR'],
	],
	'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 120]],
	'buffers' => [['byteLength' => 140]],
	'materials' => [
		['name' => 'print', 'pbrMetallicRoughness' => ['baseColorFactor' => [0.06, 0.22, 0.45, 1]]],
		['name' => 'print-1', 'pbrMetallicRoughness' => ['baseColorFactor' => [0.7, 0.39, 0.02]]],
	]]);

$bs_color = Service_Nj667_Build::glb_stats($bs_glb2);

check('glb: две группы цвета — не повод отвергать файл', $bs_color['ok'], TRUE);
check('glb: групп видно столько, сколько в файле', (int) Arr::get($bs_color, 'groups'), 2);
check('glb: грани считаются по всем группам', $bs_color['triangles'], 3);
check('glb: вершины считаются по всем группам', $bs_color['vertices'], 7);
check('glb: габарит — рамка вокруг всех цветов, а не вокруг первой группы',
	$bs_color['dims'], [10.0, 10.0, 5.0]);

// Один материал битый — это обрезанный файл, и «порисуем как получится» тут
// означало бы показать человеку серую модель вместо внятного слова.
$bs_broken_mat = $glb_of(['asset' => ['version' => '2.0'],
	'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1, 'material' => 0]]]],
	'accessors' => [
		['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3',
			'min' => [0, 0, 0], 'max' => [1, 1, 1]],
		['bufferView' => 0, 'componentType' => 5123, 'count' => 3, 'type' => 'SCALAR'],
	],
	'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 36],
		['buffer' => 0, 'byteOffset' => 36, 'byteLength' => 6]],
	'buffers' => [['byteLength' => 140]],
	'materials' => [['name' => 'print', 'pbrMetallicRoughness' => ['baseColorFactor' => [0.5]]]]]);

contains('glb: материал без цвета назван materials-проблемой',
	(string) Arr::get(Service_Nj667_Build::glb_stats($bs_broken_mat), 'error'), 'материал');

// Лимит групп: приёмка и генератор обязаны называть одно число, иначе воркер
// молча пришлёт файл, который страница отвергнет, — и человек увидит пустое окно.
$bs_prims = [];

for ($i = 0; $i < 25; $i++)
{
	$bs_prims[] = ['attributes' => ['POSITION' => 0], 'indices' => 1];
}

$bs_many = $glb_of(['asset' => ['version' => '2.0'],
	'meshes' => [['primitives' => $bs_prims]],
	'accessors' => [
		['bufferView' => 0, 'componentType' => 5126, 'count' => 3, 'type' => 'VEC3',
			'min' => [0, 0, 0], 'max' => [1, 1, 1]],
		['bufferView' => 0, 'componentType' => 5123, 'count' => 3, 'type' => 'SCALAR'],
	],
	'bufferViews' => [['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 36],
		['buffer' => 0, 'byteOffset' => 36, 'byteLength' => 6]],
	'buffers' => [['byteLength' => 140]]]);

check('конфиг: групп цвета в GLB умеем 24', (int) Service_Nj667_Build::limit('glb_prims_max', 0), 24);
contains('glb: 25 групп — отказ с числом лимита',
	(string) Arr::get(Service_Nj667_Build::glb_stats($bs_many), 'error'), 'до 24');

$bs_js = (string) file_get_contents(N2J_STAND_ROOT.'/public_html/assets/js/nj667-stl.js');
$bs_glb_py = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/glb.py');
$bs_js = (string) file_get_contents(N2J_STAND_ROOT.'/public_html/assets/js/nj667-stl.js');

check_true('воркер: VRML читается и красится (MAX_GROUPS = 24)',
	strpos($bs_glb_py, 'MAX_GROUPS = 24') !== FALSE AND strpos($bs_glb_py, 'def parse_wrl') !== FALSE
	AND strpos($bs_glb_py, 'def wrl_to_glb') !== FALSE);
check_true('воркер: цвет из glTF линейный (gamma 2.2), а не sRGB как есть',
	strpos($bs_glb_py, '** 2.2') !== FALSE OR strpos($bs_glb_py, '2.2') !== FALSE);
check_true('воркер: сборка просит STL и VRML одним запуском',
	strpos($bs_py, '("stl", "wrl")') !== FALSE AND strpos($bs_py, '_files_of') !== FALSE);
check_true('js: лимит групп и цвет читаются из файла, а не из головы',
	strpos($bs_js, 'MAX_GROUPS = 24') !== FALSE AND strpos($bs_js, 'model.groups') !== FALSE);
check_true('js: узел показывают в этом же окне (кнопка и url)',
	strpos($bs_js, 'data-part-view') !== FALSE AND strpos($bs_js, 'function partUrl') !== FALSE);
check_true('js: ожидание называет секунды и число готовых узлов',
	strpos($bs_js, 'waitNote') !== FALSE AND strpos($bs_js, 'узлы: ') !== FALSE);
check_true('воркер: солиды склеиваются по цвету, а не падают на лимите групп',
	strpos($bs_glb_py, 'def merge_by_color') !== FALSE
	AND strpos($bs_glb_py, 'groups = merge_by_color(') !== FALSE);
check_true('js: счётчик готовых узлов не ложится плашкой на холст',
	strpos($bs_js, 'Узлов на диске: ') === FALSE);

$bs_view_src = (string) file_get_contents(N2J_STAND_ROOT
	.'/application/modules/nj667_graph3d/views/nj667/build.php');
check_true('пачка: страница говорит, сколько типов уже на диске',
	strpos($bs_view_src, "'done', 0)") !== FALSE);
check_true('пачка: план сборки считают по её id, а не по пустому списку',
	strpos((string) file_get_contents(N2J_STAND_ROOT
		.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php'),
		'build_parts_plan($geom, $opt,') !== FALSE);
// ($bs_svc читается один раз: он нужен и выше, и в иглах про js)

check_true('пачка: статус сборки не трогает, трогает только job_id',
	strpos($bs_svc, 'UPDATE nj_667_builds SET job_id = ') !== FALSE
	// Единственный «status = queued» на сервис — это рендер модели; пачка
	// узлов права менять статус сборки не имеет.
	AND substr_count($bs_svc, "nj_667_builds SET status = '.self::_q('queued')") === 1);
check_true('пачка: готовый тип вычитается до лимита запуска',
	strpos($bs_svc, 'self::build_parts_plan($geom, $opt, (int) $build_id,') !== FALSE);
check_true('пачка: worker-result не может расширить исходное окно',
	strpos($bs_svc, 'parts_run_window($full, $run_parts)') !== FALSE);
check_true('сборка: залипший queued снимают по живой задаче',
	strpos((string) file_get_contents(N2J_STAND_ROOT
		.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php'),
		'function build_reconcile') !== FALSE);

// ---- эргономика числовых полей: форма не имеет права отвергать свой дефолт --
$bs_grid = 0;

foreach (array_keys(Service_Nj667_Build::types()) as $bs_tk)
{
	foreach (Service_Nj667_Build::type_params($bs_tk) as $bs_pname => $bs_spec)
	{
		$bs_spec  = (array) $bs_spec;
		$bs_step  = (float) Arr::get($bs_spec, 'step', 0.1);
		$bs_off   = ((float) Arr::get($bs_spec, 'def', 0) - (float) Arr::get($bs_spec, 'min', 0));
		$bs_ratio = $bs_step > 0 ? $bs_off / $bs_step : 0;

		// Браузер считает валидными только min + k*step: значение «вне сетки»
		// человек видит как «введите 10.001» и не может отправить форму.
		if (abs($bs_ratio - round($bs_ratio)) > 1e-6)
		{
			$bs_grid++;
		}
	}
}

check('конфиг: каждый параметр сидит на сетке своего шага', $bs_grid, 0);

preg_match_all('/<input[^>]*type="number"[^>]*>/i', $bs_glb_page, $bs_num);
$bs_glued = 0;

// Отступы по бокам даёт либо общий n2j-input (px-3.5 внутри компонента), либо px-*.
foreach ($bs_num[0] as $bs_tag)
{
	if (strpos($bs_tag, 'n2j-input') === FALSE AND strpos($bs_tag, 'px-') === FALSE)
	{
		$bs_glued++;
	}
}

check('вёрстка: ни одно числовое поле не липнет к рамке', $bs_glued, 0);

preg_match_all('/<(input[^>]*type="number"|select)[^>]*>/i', $bs_glb_page, $bs_all);

// Радиус rounded-lg — внутри общего n2j-input (эталон v6) или явным классом.
foreach ($bs_all[0] as $bs_tag)
{
	if (strpos($bs_tag, 'n2j-input') === FALSE AND strpos($bs_tag, 'rounded-lg') === FALSE)
	{
		$bs_glued++;
	}
}

check('вёрстка: у контролов единый радиус rounded-lg (эталон)', $bs_glued, 0);
contains('форма: масштаб принимает любое число (step="any")', $bs_glb_page, 'name="unit_size" step="any"');
contains('форма: у масштаба есть подсказка в title', $bs_glb_page, 'Сколько миллиметров в одной клетке');
contains('вьюха: окно просмотра не вылезает за экран', $bs_view, 'max-height: clamp(260px, 58vh, 680px)');
contains('вьюха: холст тянется на весь остаток карточки (без мёртвой полосы)', $bs_view,
	'flex: 1 1 auto');
contains('вьюха: карточка просмотрщика — колонка, низом служит строка статуса', $bs_page,
	'flex min-h-0 flex-col');
$bs_mono = render('nj667/build', array_merge($bs_vars, ['build' => array_merge($bs_vars['build'],
	['glb_size' => 409000, 'stats_json' => '{"glb":{"groups":1}}'])]));
$bs_color = render('nj667/build', array_merge($bs_vars, ['build' => array_merge($bs_vars['build'],
	['glb_size' => 409000, 'stats_json' => '{"glb":{"groups":9}}'])]));
contains('вьюха: одна краска в файле — сказано словами, а не спрятана', $bs_mono,
	'В glTF одна краска');
contains('вьюха: виноватый назван прямо на странице (бинарь и версия)', $bs_mono,
	'NJ667_OPENSCAD_BIN');
contains('вьюха: сказано, что цвета несёт только .wrl', $bs_mono, 'Краску несёт');
check_true('вьюха: счётчик «Красок в файле» убран по запросу владельца', strpos($bs_color, 'Красок в файле') === FALSE);
check_true('вьюха: число красок берётся из приёмки, а не наугад',
	strpos($bs_view, 'stats_json') !== FALSE AND strpos($bs_view, 'groups') !== FALSE);
contains('вьюха: «spec» говорится словами, а не «спецификация»', $bs_view,
	'3D-модели ещё нет — нажмите «Пересчитать и в 3D»');
check('вьюха: кнопки пачки — и в шапке, и под списком', substr_count($bs_page, 'name="parts" value="1"'), 2);
// Строка «К печати: не заказывали» без кнопки = ловушка: проверить надо и
// тот путь, где id сборки не доехал до вьюхи.
$bs_nobuild = render('nj667/build', array_merge($bs_vars, ['build' => []]));
contains('вьюха: без файла на диске кнопка остаётся и объясняет шаг', $bs_nobuild,
	'пачка встанет на свежую сборку');
check_true('вьюха: кнопка пачки не прячется из-за отсутствующего build_id',
	substr_count($bs_nobuild, 'name="parts" value="1"') === 2);

$bs_glb_py = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/glb.py');
check_true('воркер: цвет граней читается из color/colorIndex, а не из материала',
	strpos($bs_glb_py, 'colorIndex') !== FALSE AND strpos($bs_glb_py, '_wrl_palette') !== FALSE);
check_true('воркер: жёлтая метка OpenSCAD не принимается за краску',
	strpos($bs_glb_py, 'WRL_DEFAULT_SRGB') !== FALSE AND strpos($bs_glb_py, '_is_wrl_default') !== FALSE);
// Владелец убрал счётчик красок из статуса 3D (запрос 2): «цветов: N», граней, «Красок в файле».
check_true('js: статус 3D без счётчика красок и граней',
	strpos($bs_js, 'цветов: ') === FALSE AND strpos($bs_js, 'граней, ') === FALSE);
check_true('js: камера подгоняется под кадр, а не сидит на 2.1·r',
	strpos($bs_js, 'const fit = () =>') !== FALSE AND strpos($bs_js, 'Math.sin(fov / 2)') !== FALSE);
contains('вьюха: параметры и модель — одна строка', $bs_view, 'xl:grid-cols-[minmax(300px,360px)_minmax(0,1fr)]');
contains('вьюха: прежние сборки свёрнуты', $bs_page, '<details');
contains('вьюха: готовому узлу дают кнопку просмотра', $bs_page, 'data-part-view="0"');
contains('вьюха: кнопка знает id сборки и размер файла', $bs_page, 'data-build="7"');
contains('вьюха: статус и таймер — одна группа', $bs_view, 'class="nj667-status inline-flex min-w-0 flex-wrap items-baseline');
// Поле без рамки и подложки в день — «белое на белом» (замечание владельца,
// 13.10.2026). С 20.10.2026 поля — общий n2j-input (рамка и подложка в обеих темах).
contains('вьюха: поле качества — n2j-input с рамкой и подложкой', $bs_page, 'name="quality" class="n2j-input n2j-select');
contains('вьюха: поле клетки графа — n2j-input', $bs_page, 'class="n2j-input w-24 shrink-0"');
check_true('вьюха: своего правила на поля в <style> нет', strpos($bs_page, '.nj667-card input') === FALSE);


// ------------------------------------------------- файлы на диске (отчёт, очистка)
// Чистая классификация без диска и базы: фиксированное «сейчас», синтетика.
$st_now = 1800000000;
$st_cfg = ['stale_days' => 30, 'batch' => 500, 'orphan_min_age' => 3600];
$st_day = 86400;
$st_projects = [
	'AAAAAAAA' => ['deleted' => 0, 'title' => 'Живой', 'status' => 'draft'],
	'BBBBBBBB' => ['deleted' => 1, 'title' => 'Удалённый', 'status' => 'draft'],
];
// ts — время сборки (done_at или created_at), code — владелец
$st_builds = [
	10 => ['code' => 'AAAAAAAA', 'status' => 'done',  'ts' => $st_now - 60 * $st_day], // не последняя: устарела
	11 => ['code' => 'AAAAAAAA', 'status' => 'error', 'ts' => $st_now - 50 * $st_day], // не последняя и не готовая: устарела
	12 => ['code' => 'AAAAAAAA', 'status' => 'done',  'ts' => $st_now - 40 * $st_day], // последняя готовая: не трогаем
	13 => ['code' => 'AAAAAAAA', 'status' => 'spec',  'ts' => $st_now - 1 * $st_day],  // последняя: не трогаем
	14 => ['code' => 'AAAAAAAA', 'status' => 'error', 'ts' => $st_now - 5 * $st_day],  // недавняя: не трогаем
	30 => ['code' => 'BBBBBBBB', 'status' => 'done',  'ts' => $st_now - 60 * $st_day],
];
$st_f = function ($code, $name, $bytes, $age_s) use ($st_now) {
	return ['rel' => 'nj667/'.$code.'/'.$name, 'code' => $code, 'name' => $name,
		'bytes' => $bytes, 'mtime' => $st_now - $age_s, 'abs' => '/nope/'.$name];
};
$st_old = 100 * $st_day;
$st_files = [
	$st_f('AAAAAAAA', '01.jpg', 100, $st_old),              // кадр живого: keep
	$st_f('AAAAAAAA', 'build-10.stl', 1000, $st_old),        // stale
	$st_f('AAAAAAAA', 'build-10.glb', 1100, $st_old),        // stale
	$st_f('AAAAAAAA', 'build-10-part-0.stl', 200, $st_old),  // stale (узел старой сборки)
	$st_f('AAAAAAAA', 'build-10-part-0.png', 50, $st_old),   // stale (превью)
	$st_f('AAAAAAAA', 'build-11.scad', 30, $st_old),         // stale
	$st_f('AAAAAAAA', 'build-12.glb', 1200, $st_old),        // keep: последняя готовая
	$st_f('AAAAAAAA', 'build-13.scad', 31, $st_old),         // keep: последняя
	$st_f('AAAAAAAA', 'build-14.stl', 1400, $st_old),        // keep: недавняя
	$st_f('AAAAAAAA', 'build-99.stl', 990, 2 * $st_day),     // orphan: строки нет, давно
	$st_f('AAAAAAAA', 'build-98.stl', 980, 10),              // keep: строки нет, но свежий
	$st_f('AAAAAAAA', 'build-30.stl', 300, $st_old),         // orphan: чужая сборка (30 принадлежит BBBBBBBB)
	$st_f('AAAAAAAA', 'notes.txt', 7, $st_old),              // other
	$st_f('BBBBBBBB', '01.jpg', 400, $st_old),               // deleted
	$st_f('BBBBBBBB', 'build-30.glb', 4000, $st_old),        // deleted
	$st_f('CCCCCCCC', 'build-40.stl', 5000, 2 * $st_day),    // orphan: каталога нет в проектах
	$st_f('CCCCCCCC', '02.png', 600, 10),                    // keep: свежий, каталог без проекта
	$st_f('CCCCCCCC', 'readme.md', 9, $st_old),              // other
];
$st_plan = Service_Nj667_Storage::plan($st_files, $st_projects, $st_builds, $st_now, $st_cfg);
$st_cat = function ($rel) use ($st_plan) {
	foreach ($st_plan['items'] as $it) { if ($it['rel'] === $rel) { return $it['cat']; } }
	return 'нет';
};
$st_item = function ($rel) use ($st_plan) {
	foreach ($st_plan['items'] as $it) { if ($it['rel'] === $rel) { return $it; } }
	return [];
};

check('storage: кадр живого проекта — keep', $st_cat('nj667/AAAAAAAA/01.jpg'), 'keep');
check('storage: устаревшая сборка stl — stale', $st_cat('nj667/AAAAAAAA/build-10.stl'), 'stale');
check('storage: устаревшая сборка glb — stale', $st_cat('nj667/AAAAAAAA/build-10.glb'), 'stale');
check('storage: узел устаревшей сборки — stale', $st_cat('nj667/AAAAAAAA/build-10-part-0.stl'), 'stale');
check('storage: превью узла устаревшей сборки — stale', $st_cat('nj667/AAAAAAAA/build-10-part-0.png'), 'stale');
check('storage: не готовая и не последняя сборка — stale', $st_cat('nj667/AAAAAAAA/build-11.scad'), 'stale');
check('storage: последняя готовая сборка — keep', $st_cat('nj667/AAAAAAAA/build-12.glb'), 'keep');
check('storage: последняя сборка (spec) — keep', $st_cat('nj667/AAAAAAAA/build-13.scad'), 'keep');
check('storage: недавняя сборка — keep', $st_cat('nj667/AAAAAAAA/build-14.stl'), 'keep');
check('storage: сборка без строки, давно — orphan', $st_cat('nj667/AAAAAAAA/build-99.stl'), 'orphan');
check('storage: сборка без строки, свежая — keep', $st_cat('nj667/AAAAAAAA/build-98.stl'), 'keep');
check('storage: сборка другого проекта — orphan', $st_cat('nj667/AAAAAAAA/build-30.stl'), 'orphan');
check('storage: неизвестное имя — other', $st_cat('nj667/AAAAAAAA/notes.txt'), 'other');
check('storage: photo extension recognized', $st_item('nj667/AAAAAAAA/01.jpg')['ext'], 'jpg');
check('storage: unknown extension recognized for type filter', $st_item('nj667/AAAAAAAA/notes.txt')['ext'], 'txt');
check('storage: кадр удалённого проекта — deleted', $st_cat('nj667/BBBBBBBB/01.jpg'), 'deleted');
check('storage: сборка удалённого проекта — deleted', $st_cat('nj667/BBBBBBBB/build-30.glb'), 'deleted');
check('storage: каталог без проекта, давно — orphan', $st_cat('nj667/CCCCCCCC/build-40.stl'), 'orphan');
check('storage: каталог без проекта, свежий — keep', $st_cat('nj667/CCCCCCCC/02.png'), 'keep');
check('storage: неизвестное имя в каталоге без проекта — other', $st_cat('nj667/CCCCCCCC/readme.md'), 'other');

check('storage: сумма stale', $st_plan['cats']['stale'], ['files' => 5, 'bytes' => 2380]);
check('storage: сумма deleted', $st_plan['cats']['deleted'], ['files' => 2, 'bytes' => 4400]);
check('storage: сумма orphan', $st_plan['cats']['orphan'], ['files' => 3, 'bytes' => 6290]);
check('storage: по проектам — порядок по размеру', array_keys($st_plan['projects']), ['AAAAAAAA', 'CCCCCCCC', 'BBBBBBBB']);
check('storage: по проектам — байты', array_values($st_plan['projects']), [7388, 5609, 4400]);

check('storage: fmt 0 байт', Service_Nj667_Storage::fmt_bytes(0), '0 Б');
check('storage: fmt 1500 байт', Service_Nj667_Storage::fmt_bytes(1500), '1,5 КБ');
check('storage: fmt 12 МБ', Service_Nj667_Storage::fmt_bytes(12345678), '12 МБ');
check('storage: fmt 1.5 ГБ', Service_Nj667_Storage::fmt_bytes(1610612736), '1,5 ГБ');

check('storage: нет APPPATH — каталога нет, scan пуст', Service_Nj667_Storage::scan(), []);
check('storage: очистка «В работе» запрещена', Service_Nj667_Storage::clean('keep')['ok'], FALSE);
check('storage: очистка неизвестной категории запрещена', Service_Nj667_Storage::clean('../etc')['ok'], FALSE);
check('storage: порог по умолчанию — конфиг стенда подхватывается', is_array(Service_Nj667_Storage::cats()), TRUE);

// Рендер страницы настроек с данными отчёта
$st_storage = [
	'root_ok' => TRUE, 'files' => 18, 'bytes' => 20480,
	'rows' => [
		['key' => 'keep', 'title' => 'В работе', 'rule' => 'R1', 'files' => 5, 'bytes' => 100, 'cleanable' => FALSE],
		['key' => 'stale', 'title' => 'Устаревшие сборки', 'rule' => 'R2', 'files' => 5, 'bytes' => 2560, 'cleanable' => TRUE],
		['key' => 'orphan', 'title' => 'Сироты', 'rule' => 'R3', 'files' => 3, 'bytes' => 6290, 'cleanable' => TRUE],
		['key' => 'deleted', 'title' => 'Удалённые проекты', 'rule' => 'R4', 'files' => 2, 'bytes' => 4400, 'cleanable' => TRUE],
		['key' => 'other', 'title' => 'Прочее', 'rule' => 'R5', 'files' => 0, 'bytes' => 0, 'cleanable' => FALSE],
	],
	'top' => [['code' => 'CCCCCCCC', 'title' => '', 'status' => '', 'deleted' => FALSE, 'known' => FALSE, 'bytes' => 5600]],
	'disk' => ['free' => 3.0 * 1024 * 1024 * 1024, 'total' => 20.0 * 1024 * 1024 * 1024],
	'cfg' => $st_cfg,
	'sort' => 'size_desc',
	'sorts' => Service_Nj667_Storage::sorts(),
	'files_shown' => [
		['rel' => 'nj667/AAAAAAAA/build-10.stl', 'name' => 'build-10.stl', 'code' => 'AAAAAAAA', 'title' => 'Живой', 'known' => TRUE, 'kind' => 'build', 'bytes' => 1000, 'mtime' => 1700000000, 'cat' => 'stale', 'cat_title' => 'Устаревшие сборки'],
		['rel' => 'nj667/AAAAAAAA/01.jpg', 'name' => '01.jpg', 'code' => 'AAAAAAAA', 'title' => 'Живой', 'known' => TRUE, 'kind' => 'photo', 'bytes' => 100, 'mtime' => 1600000000, 'cat' => 'keep', 'cat_title' => 'В работе'],
		['rel' => 'nj667/CCCCCCCC/build-40.stl', 'name' => 'build-40.stl', 'code' => 'CCCCCCCC', 'title' => '', 'known' => FALSE, 'kind' => 'build', 'bytes' => 5000, 'mtime' => 1750000000, 'cat' => 'orphan', 'cat_title' => 'Сироты'],
	],
	'files_total' => 3,
];
$st_page = render('nj667/settings', [
	'models' => [], 'model' => '', 'photo_max_px' => 512, 'rag' => TRUE, 'error' => '',
	'saved' => TRUE, 'csrf' => 'stand-csrf-token', 'storage' => $st_storage,
	'cleaned' => ['category' => 'stale', 'removed' => 3, 'bytes' => 5000, 'left' => 2, 'failed' => 0],
	'deleted' => ['removed' => 2, 'bytes' => 1200, 'failed' => 0, 'missing' => 1],
]);
contains('страница: отчёт виден', $st_page, 'id="nj667-storage"');
contains('страница: итог занятого места', $st_page, 'в 18 файлах');
contains('страница: свободно на диске', $st_page, 'Свободно на диске');
check('страница: кнопки очистки только у трёх категорий', substr_count($st_page, 'Очистить порцией'), 3);
contains('страница: кнопка очистки шлёт категорию', $st_page, 'name="storage_clean" value="stale"');
contains('страница: очистка порцией — двойное нажатие', $st_page, 'data-n2j-arm="Очистить?"');
contains('страница: итог порции', $st_page, 'Очищено: 3 файлов, 4,9 КБ.');
contains('страница: остаток порции', $st_page, 'Осталось ещё 2');
contains('страница: пояснения справа', $st_page, 'Что удаляется');
contains('страница: форма модели осталась', $st_page, 'name="model"');
check('страница: форма модели не получила csrf-поле для очистки', substr_count($st_page, 'name="storage_clean"'), 3);

contains('страница: сортировка выпадает списком', $st_page, '<select id="nj667-sort" name="sort"');
$st_sort_start = strpos($st_page, '<select id="nj667-sort"');
$st_sort_end = strpos($st_page, '</select>', $st_sort_start);
check('страница: шесть сортировок', substr_count(substr($st_page, $st_sort_start, $st_sort_end - $st_sort_start), '<option value="'), 6);
contains('страница: фильтр расширения', $st_page, 'name="type"');
contains('страница: группировка по сборкам', $st_page, 'name="group_by"');
contains('страница: массовая отметка группы', $st_page, 'data-nj667-group-all');
contains('страница: полная очистка', $st_page, 'name="storage_purge" value="1"');
check('страница: строки файлов', substr_count($st_page, 'name="files[]"'), 3);
contains('страница: кнопка удаления выбранных', $st_page, 'Удалить выбранные');
contains('страница: форма выбранных с режимом удаления', $st_page, 'name="storage_delete" value="1"');
contains('страница: POST удаления сохраняет фильтр типа', $st_page, 'name="type" value="all"');
contains('страница: POST удаления сохраняет группировку', $st_page, 'name="group_by" value="build"');
contains('страница: итог ручного удаления', $st_page, 'Удалено: 2, 1,2 КБ.');
contains('страница: файл уже не было', $st_page, 'Уже не было на диске: 1.');
contains('страница: статус файла', $st_page, 'Сироты');
contains('страница: каталог без проекта подписан', $st_page, 'каталог без проекта');
check('страница: кнопка порции не отмечает файлы (name="files[]" только в списке)', substr_count($st_page, 'storage_clean" value'), 3);

// Компоновка: страница во всю ширину (как /graph3d/projects), без «дыр».
contains('страница: контейнер во всю ширину', $st_page, 'class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8"');
check_true('страница: ширина ничем не ограничена (ни вьюха, ни её карточки)',
	strpos($st_page, 'max-w-7xl') === FALSE AND strpos($st_page, 'mx-auto') === FALSE);
contains('страница: сетка «содержимое + справка»', $st_page, 'xl:grid-cols-3');
contains('страница: форма настроек занимает две трети', $st_page, 'xl:col-span-2');
contains('страница: поля настроек в две колонки', $st_page, 'lg:grid-cols-2');
contains('страница: шапка со ссылками на соседние разделы', $st_page, 'href="/graph3d/limits"');
contains('страница: подтверждение сохранения видно', $st_page, 'Настройки сохранены');

// Страница лимитов: плотная таблица под сотни людей (владелец, 16.10.2026).
$lm_users = [
	[
		'id' => 12, 'username' => 'мастер', 'email' => 'master@example.com',
		'active_projects' => 12, 'llm_operations_per_day' => NULL,
		'updated_at' => '2026-10-16 07:00:00', 'updated_by' => 1,
		'effective_limits' => ['active_projects' => 12, 'llm_operations_per_day' => 5],
		'active_count' => 3,
		'daily_usage' => ['used' => 4, 'limit' => 5],
	],
	[
		'id' => 34, 'username' => '', 'email' => 'very-long-address-for-truncation@example.com',
		'active_projects' => NULL, 'llm_operations_per_day' => 0,
		'updated_at' => NULL, 'updated_by' => NULL,
		'effective_limits' => ['active_projects' => 5, 'llm_operations_per_day' => 0],
		'active_count' => 5,
		'daily_usage' => ['used' => 0, 'limit' => 0],
	],
];

$lm_page = render('nj667/limits', [
	'users' => $lm_users, 'query' => 'мастер', 'page' => 2, 'limit' => 50, 'total' => 1234,
	'error' => '', 'saved' => TRUE, 'csrf' => 'stand-csrf-token',
]);
contains('лимиты: контейнер во всю ширину', $lm_page, 'class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8"');
check_true('лимиты: ширина ничем не ограничена',
	strpos($lm_page, 'max-w-5xl') === FALSE AND strpos($lm_page, 'mx-auto') === FALSE);
contains('лимиты: таблица вместо карточек', $lm_page, '<table');
contains('лимиты: колонки подписаны', $lm_page, '<th scope="col"');
check('лимиты: строк по числу людей', substr_count($lm_page, 'name="user_id"'), 2);
contains('лимиты: имя человека и его id в одной строке', $lm_page, '#12');
contains('лимиты: длинная почта не рвёт таблицу', $lm_page, 'max-w-[18rem] truncate');
contains('лимиты: память форм не заменяет серверные квоты', $lm_page, 'data-n2j-no-remember');
contains('лимиты: своё значение подставлено в поле', $lm_page, 'value="12"');
contains('лимиты: пустое поле подсказывает наследование', $lm_page, 'placeholder="По плану"');
foreach ([12 => [12, 5], 34 => [5, 0]] as $uid => $values)
{
    foreach (['p', 'l'] as $i => $field)
    {
        preg_match('~<input id="nj667-'.$field.'-'.$uid.'"[^>]*value="([^"]*)"~', $lm_page, $match);
        check('лимиты: текущее значение '.$field.' пользователя '.$uid, (string) ($match[1] ?? ''), (string) $values[$i]);
    }
}
contains('лимиты: расход показан долей от лимита', $lm_page, '>3<');
contains('лимиты: сохранил — остался в том же срезе', $lm_page, 'name="sort" value="new"');
contains('лимиты: размер страницы уезжает с формой', $lm_page, 'name="per" value="50"');
contains('лимиты: найдено подписано', $lm_page, '1 234');
contains('лимиты: показанный срез подписан', $lm_page, 'Показаны 51–100');
contains('лимиты: порядок выбирается списком', $lm_page, '<select id="nj667-limit-sort"');
contains('лимиты: размер страницы выбирается списком', $lm_page, '<select id="nj667-limit-per"');
contains('лимиты: пагинация уносит срез с собой', $lm_page, 'page=3');
contains('лимиты: подсказка про наследование плана', $lm_page, 'Пустое поле наследует план');
contains('лимиты: сохранение подтверждено', $lm_page, 'Лимиты сохранены');
contains('лимиты: пустое состояние называет запрос',
	render('nj667/limits', ['users' => [], 'query' => 'zzz', 'page' => 1, 'limit' => 30,
		'total' => 0, 'error' => '', 'saved' => FALSE, 'csrf' => 'stand-csrf-token']),
	'Пользователи не найдены по запросу «zzz»');
// Взведённая кнопка обязана побеждать базовые классы, иначе в день-теме
// белое на белом (владелец, 16.10.2026): важные классы из сборки CSS.
// Теперь это общий data-n2j-arm (app.js) и правило [data-n2j-arm].is-armed в сборке.
check_true('страница: своего взвода нет — общий data-n2j-arm',
	strpos($st_page, "classList.add('!bg-rose-600'") === FALSE AND strpos($st_page, 'data-n2j-arm="Удалить выбранные?"') !== FALSE);

// Сортировка и ручное удаление: проверки без диска
$st_items = [
	['rel' => 'nj667/B/x', 'bytes' => 10, 'mtime' => 300],
	['rel' => 'nj667/A/y', 'bytes' => 30, 'mtime' => 100],
	['rel' => 'nj667/A/z', 'bytes' => 30, 'mtime' => 200],
];
check('сорт: крупные сверху, при равенстве — по пути', array_column(Service_Nj667_Storage::sort_items($st_items, 'size_desc'), 'rel'), ['nj667/A/y', 'nj667/A/z', 'nj667/B/x']);
check('сорт: мелкие сверху', array_column(Service_Nj667_Storage::sort_items($st_items, 'size_asc'), 'rel'), ['nj667/B/x', 'nj667/A/y', 'nj667/A/z']);
check('сорт: давно не менялись — первыми', array_column(Service_Nj667_Storage::sort_items($st_items, 'old'), 'rel'), ['nj667/A/y', 'nj667/A/z', 'nj667/B/x']);
check('сорт: недавно менялись — первыми', array_column(Service_Nj667_Storage::sort_items($st_items, 'new'), 'rel'), ['nj667/B/x', 'nj667/A/z', 'nj667/A/y']);
$st_type_items = [
	['rel' => 'a.stl', 'bytes' => 1, 'mtime' => 1, 'ext' => 'stl'],
	['rel' => 'b.png', 'bytes' => 1, 'mtime' => 1, 'ext' => 'png'],
	['rel' => 'c.glb', 'bytes' => 1, 'mtime' => 1, 'ext' => 'glb'],
];
check('сорт: по типу файла', array_column(Service_Nj667_Storage::sort_items($st_type_items, 'type'), 'rel'), ['c.glb', 'b.png', 'a.stl']);
check('удаление: пустой выбор отказ', Service_Nj667_Storage::delete_files([])['ok'], FALSE);
check('удаление: пустые строки не считаются выбором', Service_Nj667_Storage::delete_files(['', ''])['ok'], FALSE);
check('удаление: больше 500 — отказ', Service_Nj667_Storage::delete_files(array_map(function ($i) { return 'nj667/A/'.$i.'.jpg'; }, range(1, 501)))['ok'], FALSE);
check('удаление: без каталога — отказ до базы', Service_Nj667_Storage::delete_files(['nj667/A/x.jpg'])['ok'], FALSE);
check('отчёт: неизвестная сортировка подменяется', is_array(Service_Nj667_Storage::sorts()), TRUE);

$st_empty = render('nj667/settings', [
	'models' => [], 'model' => '', 'photo_max_px' => 512, 'rag' => TRUE, 'error' => '',
	'csrf' => 'stand-csrf-token', 'storage' => ['root_ok' => FALSE, 'files' => 0, 'bytes' => 0, 'rows' => [], 'top' => [], 'disk' => NULL, 'cfg' => $st_cfg],
	'cleaned' => NULL,
]);
contains('страница без каталога: сообщение', $st_empty, 'Каталога с файлами модуля пока нет');
check('страница без каталога: кнопок нет', substr_count($st_empty, 'storage_clean'), 0);

// ------------------------------------------------------------------ запрос 7: публикация, ZIP, фильтр, бейдж
$rq7_svc = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php');
$rq7_st  = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Storage.php');
$rq7_ctl = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php');
$rq7_bv  = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/build.php');
$rq7_cv  = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/catalog.php');
$rq7_mig = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/migrations_pg/pgMigration_Nj_667_011_BuildPublish.php');

// миграция 011: поля публикации
check_true('миграция 011: published_at', strpos($rq7_mig, 'published_at TIMESTAMPTZ NULL') !== FALSE);
check_true('миграция 011: published_by', strpos($rq7_mig, 'published_by INTEGER NOT NULL DEFAULT 0') !== FALSE);
check_true('миграция 011: есть down', strpos($rq7_mig, 'DROP COLUMN IF EXISTS published_at') !== FALSE);
check_true('миграция 011: класс назван по шаблону', strpos($rq7_mig, 'class pgMigration_Nj_667_011_BuildPublish extends pgMigration') !== FALSE);

// публикация: проверки до базы
$rq7_pub0 = Service_Nj667_Graph3d::build_publish(0);
check('публикация: без сборки — отказ до базы', $rq7_pub0['ok'], FALSE);
check('публикация: без сборки — текст', $rq7_pub0['error'], 'Сборка не найдена.');
check('снятие: без сборки — отказ до базы', Service_Nj667_Graph3d::build_unpublish(0)['ok'], FALSE);

// публикация: админ, владелец, порядок в контроллере
check_true('контроллер: публикация только модератору', strpos($rq7_ctl, 'Публиковать сборку может только модератор (право nj667.moderate).') !== FALSE);
check_true('контроллер: публикация перехватывается до ветки владельца',
	strpos($rq7_ctl, '_build_publish(') < strpos($rq7_ctl, 'Собирать изделие может только владелец.'));
$rq7_pub_at = strpos($rq7_ctl, 'function _build_publish');
$rq7_pub_end = strpos($rq7_ctl, 'function _build_text');
$rq7_pub_ctl = substr($rq7_ctl, $rq7_pub_at, $rq7_pub_end - $rq7_pub_at);
check_true('контроллер: публикация с CSRF — внутри функции', strpos($rq7_pub_ctl, 'Security::check') !== FALSE
	AND strpos($rq7_pub_ctl, 'Публиковать сборку может только модератор (право nj667.moderate).') !== FALSE);
$rq7_svc_at = strpos($rq7_svc, 'function build_publish');
$rq7_svc_end = strpos($rq7_svc, 'function build_unpublish');
$rq7_pub_src = substr($rq7_svc, $rq7_svc_at, $rq7_svc_end - $rq7_svc_at);
check_true('сервис: одна опубликованная сборка на граф — прежняя снимается',
	strpos($rq7_pub_src, 'AND id <> \'.$id') !== FALSE AND strpos($rq7_pub_src, 'published_at = NULL') !== FALSE);
check_true('сервис: граф публикуется (kind public)', strpos($rq7_pub_src, "kind = \\'public\\'") !== FALSE);
check_true('сервис: публикация без пре-модерации', strpos($rq7_pub_src, 'premoderate') === FALSE);
check_true('сервис: публикация — только собранная модель', strpos($rq7_pub_src, 'В сборке пока нет модели') !== FALSE);
check_true('вьюха: кнопка публикации только для модератора', strpos($rq7_bv, '$can_moderate AND $build_id') !== FALSE
	AND strpos($rq7_bv, 'name="publish_build"') !== FALSE);
check_true('вьюха: снятие публикации', strpos($rq7_bv, 'name="unpublish_build"') !== FALSE);

// ZIP
check_true('zip: ссылка на странице сборки', strpos($rq7_bv, "?format=zip") !== FALSE);
check_true('zip: контроллер до ветки svg с деталью', strpos($rq7_ctl, "if (\$format === 'zip')") < strpos($rq7_ctl, "if (\$format === 'svg' AND"));
check_true('zip: архив — временный файл, удаляется после отдачи', strpos($rq7_ctl, "'application/zip', TRUE)") !== FALSE);
check_true('zip: ZipArchive проверяется', strpos($rq7_svc, "class_exists('ZipArchive')") !== FALSE);
check_true('zip: STL берём по строкам сборки (stl_path)', strpos($rq7_svc, "build_file_path(\$row, 'stl')") !== FALSE);
if (class_exists('ZipArchive'))
{
	$rq7_zb = ['id' => 153, 'project_id' => 1, 'status' => 'done', 'glb_size' => 0, 'stl_size' => 0];
	$rq7_zr = Service_Nj667_Graph3d::build_zip($rq7_zb, "a;b\n1;2\n");
	check('zip: только CSV — ок', $rq7_zr['ok'], TRUE);
	check('zip: только CSV — один файл', $rq7_zr['files'], 1);
	$rq7_zc = new ZipArchive;
	check_true('zip: архив открывается', $rq7_zc->open($rq7_zr['path']) === TRUE);
	check('zip: внутри spec.csv', $rq7_zc->getNameIndex(0), 'spec.csv');
	check('zip: содержимое CSV', trim((string) $rq7_zc->getFromName('spec.csv')), "a;b\n1;2");
	$rq7_zc->close();
	@unlink($rq7_zr['path']);
	check('zip: пустая сборка — отказ, без файла', Service_Nj667_Graph3d::build_zip($rq7_zb, '')['ok'], FALSE);
}

// очистка: опубликованные — отдельная категория, массово не чистятся
check('очистка: опубликованные не в CLEANABLE', in_array('published', Service_Nj667_Storage::CLEANABLE, TRUE), FALSE);
check('очистка: категория опубликованных описана', isset(Service_Nj667_Storage::cats()['published']), TRUE);
check('очистка: опубликованные нельзя очистить порцией', Service_Nj667_Storage::clean('published')['ok'], FALSE);
check_true('полная очистка: сохраняет опубликованный GLB', Service_Nj667_Storage::keep_published_asset(['published' => TRUE, 'kind' => 'build', 'ext' => 'glb']));
check_true('полная очистка: сохраняет превью сборки', Service_Nj667_Storage::keep_published_asset(['published' => TRUE, 'kind' => 'build', 'ext' => 'png']));
check_true('полная очистка: сохраняет STL детали', Service_Nj667_Storage::keep_published_asset(['published' => TRUE, 'kind' => 'part', 'ext' => 'stl']));
check_true('полная очистка: сохраняет превью детали', Service_Nj667_Storage::keep_published_asset(['published' => TRUE, 'kind' => 'part', 'ext' => 'png']));
check('полная очистка: опубликованный STL сборки удаляется', Service_Nj667_Storage::keep_published_asset(['published' => TRUE, 'kind' => 'build', 'ext' => 'stl']), FALSE);
check('полная очистка: фото проекта удаляется', Service_Nj667_Storage::keep_published_asset(['published' => TRUE, 'kind' => 'photo', 'ext' => 'jpg']), FALSE);
check('полная очистка: не опубликованное сохраняться не должно', Service_Nj667_Storage::keep_published_asset(['published' => FALSE, 'kind' => 'build', 'ext' => 'glb']), FALSE);
check('полная очистка без каталога — безопасный отказ', Service_Nj667_Storage::purge_unpublished()['ok'], FALSE);
check_true('отчёт: опубликованные скрываются без галочки', strpos($rq7_st, 'if ($it[\'published\'] AND ! $show_published)') !== FALSE);
check_true('удаление: опубликованные без галочки отклоняются', strpos($rq7_st, 'if ($items[$rel][\'published\'] AND ! $show_published)') !== FALSE);
check_true('удаление: счётчик отклонённых в ответе', strpos($rq7_st, "'protected' => \$protected") !== FALSE);
check_true('настройки: галочка берётся из GET', strpos($rq7_ctl, "\$this->request->query('published') === '1'") !== FALSE);
check_true('настройки: POST удаления несёт фильтр', strpos($rq7_ctl, "\$show_published = (string) Arr::get(\$post, 'published', '') === '1';") !== FALSE);

// публикация по сборке: план хранилища
$rq7_day = 86400;
$rq7_builds = [
	50 => ['code' => 'DDDDDDDD', 'status' => 'done', 'ts' => $st_now - 60 * $rq7_day, 'published' => TRUE],
	51 => ['code' => 'DDDDDDDD', 'status' => 'done', 'ts' => $st_now - 50 * $rq7_day, 'published' => FALSE],
	52 => ['code' => 'DDDDDDDD', 'status' => 'done', 'ts' => $st_now - 1 * $rq7_day,  'published' => FALSE],
	60 => ['code' => 'EEEEEEEE', 'status' => 'done', 'ts' => $st_now - 60 * $rq7_day, 'published' => TRUE],
];
$rq7_projects = ['DDDDDDDD' => ['deleted' => 0], 'EEEEEEEE' => ['deleted' => 1]];
$rq7_files = [
	$st_f('DDDDDDDD', 'build-50.stl', 700, $st_old),
	$st_f('DDDDDDDD', 'build-50-part-0.stl', 200, $st_old),
	$st_f('DDDDDDDD', 'build-50.glb', 300, $st_old),
	$st_f('DDDDDDDD', 'build-51.stl', 1000, $st_old),
	$st_f('DDDDDDDD', 'build-52.glb', 1200, $st_old),
	$st_f('DDDDDDDD', '01.jpg', 100, $st_old),
	$st_f('EEEEEEEE', 'build-60.stl', 500, $st_old),
];
$rq7_plan = Service_Nj667_Storage::plan($rq7_files, $rq7_projects, $rq7_builds, $st_now, $st_cfg);
$rq7_item = function ($rel) use ($rq7_plan) {
	foreach ($rq7_plan['items'] as $it) { if ($it['rel'] === $rel) { return $it; } }
	return NULL;
};
check('план: сборка опубликованного — published', $rq7_item('nj667/DDDDDDDD/build-50.stl')['cat'], 'published');
check('план: узел опубликованной — published', $rq7_item('nj667/DDDDDDDD/build-50-part-0.stl')['cat'], 'published');
check('план: glb опубликованной — published', $rq7_item('nj667/DDDDDDDD/build-50.glb')['cat'], 'published');
check('план: флаг у файла опубликованной', $rq7_item('nj667/DDDDDDDD/build-50.stl')['published'], TRUE);
check('план: непубликованная устаревшая — stale', $rq7_item('nj667/DDDDDDDD/build-51.stl')['cat'], 'stale');
check('план: у непубликованной флаг FALSE', $rq7_item('nj667/DDDDDDDD/build-51.stl')['published'], FALSE);
check('план: последняя сборка — keep', $rq7_item('nj667/DDDDDDDD/build-52.glb')['cat'], 'keep');
check('план: опубликованная в удалённом проекте — deleted', $rq7_item('nj667/EEEEEEEE/build-60.stl')['cat'], 'deleted');
check('план: удалённая не помечена опубликованной', $rq7_item('nj667/EEEEEEEE/build-60.stl')['published'], FALSE);
check('план: сумма опубликованных', $rq7_plan['cats']['published'], ['files' => 3, 'bytes' => 1200]);
$rq7_no_project_plan = Service_Nj667_Storage::plan($rq7_files,
	['EEEEEEEE' => ['deleted' => 1]], $rq7_builds, $st_now, $st_cfg);
$rq7_no_project_item = function ($rel) use ($rq7_no_project_plan) {
	foreach ($rq7_no_project_plan['items'] as $it) { if ($it['rel'] === $rel) { return $it; } }
	return [];
};
check_true('план: опубликованные файлы сохраняются при отсутствующей строке проекта',
	! empty($rq7_no_project_item('nj667/DDDDDDDD/build-50.glb')['published']));
$rq7_unmapped_builds = $rq7_builds;
$rq7_unmapped_builds[50]['code'] = '';
$rq7_unmapped_plan = Service_Nj667_Storage::plan($rq7_files,
	['EEEEEEEE' => ['deleted' => 1]], $rq7_unmapped_builds, $st_now, $st_cfg);
$rq7_unmapped_glb = [];
foreach ($rq7_unmapped_plan['items'] as $it) {
	if ($it['rel'] === 'nj667/DDDDDDDD/build-50.glb') { $rq7_unmapped_glb = $it; break; }
}
check_true('план: опубликованный GLB сохраняется при пустом project mapping', ! empty($rq7_unmapped_glb['published']));

check('план: сумма устаревших не меняется', $rq7_plan['cats']['stale'], ['files' => 1, 'bytes' => 1000]);

// бейдж «Изделие» в каталоге
check_true('каталог: подзапрос опубликованной сборки в каталоге и поиске',
	substr_count($rq7_svc, 'AS published_build_id FROM nj_667_projects') === 2);
$rq7_cat_html = render('nj667/catalog', $shared + [
	'logged' => TRUE, 'can_start' => TRUE, 'query' => '', 'error' => '',
	'catalog' => [$project + ['published_build_id' => 153]],
]);
contains('каталог: бейдж «Изделие» ведёт к сборке', $rq7_cat_html, '/graph3d/b/ab34cd56ef78?build=153');
contains('каталог: подпись бейджа', $rq7_cat_html, 'Изделие');
$rq7_cat_plain = render('nj667/catalog', $shared + [
	'logged' => TRUE, 'can_start' => TRUE, 'query' => '', 'error' => '',
	'catalog' => [$project],
]);
check_true('каталог: без опубликованной сборки бейджа нет', strpos($rq7_cat_plain, '?build=') === FALSE);
check_true('каталог: вторая карточка не добавлена', substr_count($rq7_cat_html, '<article') === 1);

// страница настроек: фильтр и уведомления
$rq7_set = $st_storage + ['show_published' => FALSE, 'hidden' => ['files' => 4, 'bytes' => 5000]];
$rq7_set_html = render('nj667/settings', [
	'models' => [], 'model' => '', 'photo_max_px' => 512, 'rag' => TRUE, 'error' => '',
	'csrf' => 'stand-csrf-token', 'storage' => $rq7_set,
	'cleaned' => NULL,
	'deleted' => ['removed' => 1, 'bytes' => 10, 'failed' => 0, 'missing' => 0, 'protected' => 2],
]);
contains('настройки: скрыто опубликованных — подсказка', $rq7_set_html, 'Скрыто опубликованных файлов: 4');
contains('настройки: пропущено опубликованных — итог', $rq7_set_html, 'Пропущено опубликованных: 2');
check_true('настройки: галочка не отмечена по умолчанию', (function () use ($rq7_set_html) {
	preg_match('~<input type="checkbox" id="nj667-published"[^>]*>~', $rq7_set_html, $m);
	return isset($m[0]) AND strpos($m[0], 'checked') === FALSE;
})());
$rq7_set_on = render('nj667/settings', [
	'models' => [], 'model' => '', 'photo_max_px' => 512, 'rag' => TRUE, 'error' => '',
	'csrf' => 'stand-csrf-token', 'storage' => array_merge($st_storage, ['show_published' => TRUE, 'hidden' => ['files' => 0, 'bytes' => 0],
		'rows' => array_merge($st_storage['rows'], [['key' => 'published', 'title' => 'Опубликованные сборки', 'rule' => 'R6', 'files' => 3, 'bytes' => 1200, 'cleanable' => FALSE]])]),
	'cleaned' => NULL, 'deleted' => NULL,
]);
check_true('настройки: с галочкой — скрытых нет', strpos($rq7_set_on, 'Скрыто опубликованных') === FALSE);
contains('настройки: с галочкой — форма удаления несёт фильтр', $rq7_set_on, '<input type="hidden" name="published" value="1">');
check_true('настройки: с галочкой — галочка отмечена', (function () use ($rq7_set_on) {
	preg_match('~<input type="checkbox" id="nj667-published"[^>]*>~', $rq7_set_on, $m);
	return isset($m[0]) AND strpos($m[0], 'checked') !== FALSE;
})());
contains('настройки: категория опубликованных в списке', $rq7_set_on, 'Опубликованные сборки');

// страница сборки: публикация и ZIP (запрос 7)
$rq7_b_admin = $bs_vars + ['can_moderate' => TRUE];
$rq7_b_admin['build'] = array_merge($bs_vars['build'], ['glb_size' => 4096]);
$rq7_b_pub = $rq7_b_admin;
$rq7_b_pub['build'] = array_merge($rq7_b_admin['build'], ['published_at' => '2026-10-14 10:00:00']);
$rq7_pa = render('nj667/build', $rq7_b_admin);
contains('сборка: админ видит «Опубликовать сборку»', $rq7_pa, 'name="publish_build"');
check_true('сборка: снятия у неопубликованной нет', strpos($rq7_pa, 'name="unpublish_build"') === FALSE);
contains('сборка: ZIP-ссылка с номером сборки', $rq7_pa, 'format=zip&amp;build=7');
contains('сборка: подпись ZIP', $rq7_pa, 'Всё ZIP');
contains('сборка: форма публикации несёт csrf', $rq7_pa, 'name="csrf" value="stand-csrf-token"');
$rq7_pp = render('nj667/build', $rq7_b_pub);
contains('сборка: опубликованная — «Снять с публикации»', $rq7_pp, 'name="unpublish_build"');
contains('сборка: опубликованная — статус', $rq7_pp, 'Опубликована в каталоге');
check_true('сборка: опубликованную не публикуем повторно', strpos($rq7_pp, 'name="publish_build"') === FALSE);
$rq7_pg = render('nj667/build', array_merge($bs_vars, ['can_moderate' => FALSE, 'owner' => FALSE]));
check_true('сборка: гостю кнопок публикации нет', strpos($rq7_pg, 'publish_build') === FALSE
	AND strpos($rq7_pg, 'unpublish_build') === FALSE);
contains('сборка: гостю ZIP есть', $rq7_pg, 'format=zip&amp;build=7');
$rq7_ok = render('nj667/build', array_merge($rq7_b_admin, ['pub' => 'on']));
contains('сборка: уведомление после публикации', $rq7_ok, 'Сборка опубликована: граф в каталоге');
$rq7_off = render('nj667/build', array_merge($rq7_b_pub, ['pub' => 'off']));
contains('сборка: уведомление после снятия', $rq7_off, 'Сборка снята с публикации');
$rq7_err = render('nj667/build', array_merge($rq7_b_admin, ['pub_error' => 'В сборке пока нет модели: сначала соберите изделие.']));
contains('сборка: текст ошибки публикации', $rq7_err, 'В сборке пока нет модели');
$rq7_nomodel = render('nj667/build', array_merge($rq7_b_admin, ['build' => ['id' => 7, 'project_id' => 1, 'status' => 'spec', 'stl_size' => 0, 'glb_size' => 0, 'job_id' => 0]]));
check_true('сборка: без модели кнопки публикации нет', strpos($rq7_nomodel, 'name="publish_build"') === FALSE);
contains('сборка: без модели — подсказка', $rq7_nomodel, 'Публиковать можно собранную сборку с моделью.');

// права (запрос 8): один признак — право nj667.moderate, без _is_admin
$rq8_ctl = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php');
$rq8_api = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Api.php');
$rq8_view = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/build.php');
check_true('права: контроллер не зовёт _is_admin (метод был пустым для админа)', strpos($rq8_ctl, '_is_admin') === FALSE);
check_true('права: признак модератора из nj667.moderate', strpos($rq8_ctl, "\$this->can('nj667.moderate')") !== FALSE);
check_true('права: scad и публикация — через _can_moderate', substr_count($rq8_ctl, '$this->_can_moderate') >= 3);
check_true('права: вьюха сборки — can_moderate', strpos($rq8_view, 'is_admin') === FALSE AND strpos($rq8_view, '$can_moderate') !== FALSE);
check_true('права API: админ определяется по списку ролей', strpos($rq8_api, "in_array('admin', \$roles, TRUE)") !== FALSE);
check_true('права API: метода has_role у модели больше нет', strpos($rq8_api, "method_exists(\$user, 'has_role')") === FALSE);

// статус «published» (запрос 8)
$rq8_svc = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php');
check_true('статус: перерендер пишет published у опубликованной', substr_count($rq8_svc, '_done_status_sql()') >= 3);
check_true('статус: снятие возвращает done', strpos($rq8_svc, "THEN \\'done\\' ELSE status END") !== FALSE);
$rq8_pub_b = $rq7_b_admin;
$rq8_pub_b['build'] = array_merge($rq7_b_admin['build'], ['status' => 'published', 'published_at' => '2026-10-14 10:00:00']);
$rq8_pub_html = render('nj667/build', $rq8_pub_b);
contains('статус: опубликованная сборка показывает модель', $rq8_pub_html, 'Изделие целиком:');
contains('статус: опубликованная — снятие с публикации', $rq8_pub_html, 'name="unpublish_build"');
check_true('статус: опубликованную повторно не публикуем', strpos($rq8_pub_html, 'name="publish_build"') === FALSE);
$rq8_hist = render('nj667/build', array_merge($rq7_b_admin, ['history' => [
	['id' => 7, 'status' => 'published', 'type_key' => 'flat', 'created_at' => '2026-10-12 09:00', 'stl_size' => 5, 'error' => ''],
]]));
check_true('статус: в истории слово «published» не голое', strpos($rq8_hist, '>published<') === FALSE);

// витрина на главной (запрос 8): клон с палочками + кнопка в модуль
$rq8_sample = Service_Nj667_Graph3d::home_sample();
check_true('витрина: образец — два уголка и три палочки', count($rq8_sample['nodes']) === 6 AND count($rq8_sample['edges']) === 7);
$rq8_card = render('nj667/card', []);
contains('витрина: интерактивный блок на карточке', $rq8_card, 'data-nj667-home3d');
contains('витрина: образец в data-graph', $rq8_card, 'data-graph="');
contains('витрина: кнопка в модуль', $rq8_card, 'href="/graph3d"');
contains('витрина: скрипт подключён отложенно', $rq8_card, '/assets/js/nj667-home3d.js?v=');
check_true('витрина: JS-файл есть', is_file(N2J_STAND_ROOT.'/public_html/assets/js/nj667-home3d.js'));
check_true('витрина: three грузится по относительному пути (без importmap)', strpos((string) file_get_contents(N2J_STAND_ROOT.'/public_html/assets/js/nj667-home3d.js'), "import(THREE_URL)") !== FALSE);

// приоритет на главной: манифест помечает карточку, каталог её учитывает
check_true('главная: манифест nj667 ставит home => TRUE', strpos((string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/config/nj_module.php'), "'home'      => TRUE") !== FALSE);
$rq9_cat = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj110_catalog/classes/Service/Nj110/Catalog.php');
check_true('главная: home_cards учитывает флаг home раньше sort', strpos($rq9_cat, '$ha = empty($a[\'home\']) ? 1 : 0;') !== FALSE AND strpos($rq9_cat, '[$sa, $ha, $a[\'sort\'], $a[\'title\']]') !== FALSE);

// карточка сборки 128 px + витрина корня + превью на главной (запрос 10)
$rq10_grid = ['nodes' => [], 'edges' => []];
for ($i = 0; $i < 4; $i++) { $rq10_grid['nodes'][] = ['id' => 'n'.$i, 'type' => $i % 2 ? 'endpoint' : 'connector', 'x' => ($i % 2) * 20, 'y' => intdiv($i, 2) * 20, 'z' => 0]; }
$rq10_grid['edges'] = [['id' => 'e1', 'source' => 'n0', 'target' => 'n1', 'type' => 'rigid'], ['id' => 'e2', 'source' => 'n1', 'target' => 'n3', 'type' => 'rigid'], ['id' => 'e3', 'source' => 'n0', 'target' => 'n2', 'type' => 'rigid']];
$rq10_png = Service_Nj667_Graph3d::card_png($rq10_grid);
check_true('карточка: PNG рисуется', strlen($rq10_png) > 100 AND substr($rq10_png, 1, 3) === 'PNG');
$rq10_im = $rq10_png !== '' ? imagecreatefromstring($rq10_png) : FALSE;
check_true('карточка: размер 128×128', $rq10_im !== FALSE AND imagesx($rq10_im) === 128 AND imagesy($rq10_im) === 128);
check_true('карточка: фон прозрачный (угол альфа 127)', $rq10_im !== FALSE AND ((imagecolorat($rq10_im, 0, 0) >> 24) & 127) === 127);
check_true('карточка: пустой граф — PNG не рисуем', Service_Nj667_Graph3d::card_png(['nodes' => [], 'edges' => []]) === '');
$rq10_ctl = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Controller/Nj667/Graph3d.php');
$rq10_svc = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php');
$rq10_idx = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/index.php');
$rq10_st  = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Storage.php');
$rq10_js  = (string) file_get_contents(N2J_STAND_ROOT.'/public_html/assets/js/nj667-home3d.js');
$rq10_card= (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/views/nj667/card.php');
check_true('карточка: публикация рисует файл (build_card_write)', strpos($rq10_svc, 'self::build_card_write($id);') !== FALSE);
check_true('карточка: файл карточки отдаётся только опубликованной сборке', strpos($rq10_ctl, "=== 'card'") !== FALSE AND strpos($rq10_svc, "'published_at', '') === ''") !== FALSE);
check_true('карточка: хранилище относит card к сборке', strpos($rq10_st, 'build-([0-9]+)-card') !== FALSE);
check_true('витрина корня: три последние сборки', strpos($rq10_ctl, 'latest_published_builds(3)') !== FALSE AND strpos($rq10_svc, 'ORDER BY b.published_at DESC') !== FALSE);
check_true('витрина корня: блок вида есть', strpos($rq10_idx, 'Свежие опубликованные сборки') !== FALSE AND strpos($rq10_idx, '&file=card') !== FALSE);
$rq10_idx_html = render('nj667/index', ['logged' => FALSE, 'can_start' => FALSE, 'csrf' => 'x', 'showcase' => [], 'latest_builds' => [
	['build_id' => 9, 'code' => 'abcdefgh1234', 'title' => 'Уголок', 'summary' => 'Два уголка', 'published_at' => '2026-10-13 10:00:00+03', 'project_id' => 1],
]]);
contains('витрина корня: карточка сборки выведена', $rq10_idx_html, 'build=9&amp;file=card');
contains('витрина корня: ссылка в сборку', $rq10_idx_html, '/graph3d/b/abcdefgh1234?build=9');
check_true('главная: плашка «Загружаем» не остаётся (remove из DOM)', strpos($rq10_js, 'note.parentNode.removeChild(note)') !== FALSE AND strpos($rq10_js, 'note.hidden = true') === FALSE);
check_true('главная: плашка не перехватывает мышь', strpos($rq10_card, 'pointer-events-none') !== FALSE);

// ------------------------------------------------------------------ итог
echo "\n";
echo $bad === 0
	? "Все проверки пройдены: $ok ок, 0 фейл\n"
	: "Есть фейлы: $ok ок, $bad фейл\n";

exit($bad === 0 ? 0 : 1);
