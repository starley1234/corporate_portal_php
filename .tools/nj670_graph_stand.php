<?php
/**
 * ProcessHub: статическая SVG-миниатюра рисует связи так же, как холст.
 *
 * Зачем отдельный стенд: миниатюры на страницах аудита, факта и регламента
 * собирает Service_Nj670_Graph::svg() на сервере. Раньше они всегда вели
 * стрелку из правой грани в левую — при цели ниже линия шла сквозь блоки,
 * самопетля вырождалась в точку, а подпись условия закрывала соседний шаг.
 *
 * Запуск (PHP в песочнице нет, используем WASM):
 *   cd /tmp/phpwasm && node phpwasm_stand.mjs .tools/nj670_graph_stand.php
 * (см. .tools/phpwasm_stand.mjs — там установка @php-wasm)
 */

class Arr
{
	public static function get($a, $k, $d = NULL)
	{
		return (is_array($a) AND array_key_exists($k, $a)) ? $a[$k] : $d;
	}

	public static function path($a, $p, $d = NULL)
	{
		$cur = $a;

		foreach (explode('.', $p) as $k)
		{
			if ( ! is_array($cur) OR ! array_key_exists($k, $cur))
			{
				return $d;
			}

			$cur = $cur[$k];
		}

		return $cur;
	}
}

class HTML
{
	public static function chars($s)
	{
		return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
	}
}

class UTF8
{
	public static function substr($s, $from, $len = NULL)
	{
		return mb_substr((string) $s, (int) $from, $len === NULL ? NULL : (int) $len, 'UTF-8');
	}

	public static function strlen($s)
	{
		return mb_strlen((string) $s, 'UTF-8');
	}
}

class Service_Nj670_Repo
{
	public static function minutes_text($m)
	{
		$m = (int) $m;

		if ($m <= 0) { return '0 мин'; }
		if ($m < 60) { return $m.' мин'; }

		return $m % 60 ? floor($m / 60).' ч '.($m % 60).' мин' : floor($m / 60).' ч';
	}

	public static function money($v)
	{
		return number_format((float) $v, 0, '.', ' ').' ₽';
	}

	public static function node_types()
	{
		return [
			'start' => 'Старт', 'end' => 'Конец', 'user_task' => 'Задача',
			'service_task' => 'Авто', 'gateway_xor' => 'XOR', 'gateway_and' => 'AND',
			'timer' => 'Таймер', 'subprocess' => 'Подпроцесс',
		];
	}

	public static function branch($name, $default = [])
	{
		$cfg = ['economy' => ['wait_cost_share' => 0.5, 'default_rate_per_hour' => 1500]];

		return isset($cfg[$name]) ? $cfg[$name] : $default;
	}
}

require_once '/app/application/modules/nj670_processhub/classes/Service/Nj670/Graph.php';

$fails = 0;

function check($label, $ok)
{
	global $fails;

	echo ($ok ? 'ok   ' : 'ФЕЙЛ ').$label."\n";

	if ( ! $ok) { $fails++; }
}

function node($id, $x, $y, $w = 196, $h = 86)
{
	return [
		'id' => $id, 'step_id' => $id, 'type' => 'user_task', 'title' => $id, 'lane' => 'l',
		'role_code' => '', 'system_code' => '', 'duration_minutes' => 10, 'wait_time_minutes' => 0,
		'properties' => [], 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
	];
}

function edge($id, $from, $to, $condition = '')
{
	return ['id' => $id, 'from' => $from, 'to' => $to, 'type' => 'sequence',
		'condition' => $condition, 'weight' => 1, 'avg_minutes' => 0, 'properties' => []];
}

function route(array $nodes, array $edges)
{
	$graph = ['lanes' => [['id' => 'l', 'title' => 'Юрист']], 'nodes' => $nodes, 'edges' => $edges,
		'meta' => ['mode' => 'plan', 'width' => 1000, 'height' => 700]];

	$svg = Service_Nj670_Graph::svg($graph, ['compact' => FALSE]);
	$paths = [];

	if (preg_match_all('~<path d="([^"]+)"~', $svg, $m))
	{
		foreach ($m[1] as $d)
		{
			// Первый path — сама стрелка в <defs>, её в маршруты не берём.
			if (strpos($d, 'M 0 0 L 10') === 0)
			{
				continue;
			}

			$paths[] = $d;
		}
	}

	if (getenv('N2J_DEBUG')) { echo "--- paths: ".implode(' | ', $paths)."\n"; }

	return ['svg' => $svg, 'paths' => $paths];
}

// 1. Горизонталь: выход справа (x=236), вход слева (x=300).
$r = route([node('a', 40, 40), node('b', 300, 40)], [edge('e', 'a', 'b')]);
check('стрелка вперёд выходит из правой грани', strpos($r['paths'][0], 'M 236 83') === 0);
check('стрелка вперёд входит в левую грань', preg_match('~, 300 83$~', $r['paths'][0]) === 1);

// 2. Цель ниже: вход в верхнюю грань, а не в левую.
$r = route([node('a', 300, 40), node('b', 300, 260)], [edge('e', 'a', 'b')]);
check('цель ниже — вход в верхнюю грань', preg_match('~ 398 260$~', $r['paths'][0]) === 1);

// 3. Между шагами стоит третий блок: обход сбоку, а не сквозь него.
$r = route(
	[node('a', 300, 40), node('mid', 300, 180), node('b', 300, 340)],
	[edge('e', 'a', 'b')]
);
check('блок в коридоре — линия уходит в боковой канал', strpos($r['paths'][0], ' L ') !== FALSE);
check('обход не проходит сквозь третий блок', preg_match('~^M (496|300) 83 L ~', $r['paths'][0]) === 1);

// 4. Петля назад: обе точки на нижней грани, обход ниже всех рамок.
$r = route([node('a', 40, 40), node('b', 400, 40)], [edge('e', 'b', 'a')]);
check('петля назад идёт по нижней грани', preg_match('~^M [\\d.]+ 126 L ~', $r['paths'][0]) === 1);
check('петля назад не режет блоки', strpos($r['paths'][0], ' L ') !== FALSE);

// 5. Самопетля: дуга над шагом, оба конца на верхней грани.
$r = route([node('a', 40, 200)], [edge('e', 'a', 'a')]);
check('самопетля выходит и входит в верхнюю грань', strpos($r['paths'][0], 'M ') === 0
	AND preg_match('~ 200$~', $r['paths'][0]) === 1);

// 6. Подпись условия — текст с обводкой, без плашки поверх соседних шагов.
$r = route([node('a', 40, 40), node('b', 400, 40)], [edge('e', 'a', 'b', 'если сумма > 100')]);
check('подпись условия читается с обводкой', strpos($r['svg'], 'paint-order="stroke"') !== FALSE);
check('на миниатюре нет плашки 144 px под подпись', strpos($r['svg'], 'width="144"') === FALSE);

echo $fails ? "\nПРОВАЛ: замечаний $fails\n" : "\nвсе проверки пройдены\n";
exit($fails ? 1 : 0);
