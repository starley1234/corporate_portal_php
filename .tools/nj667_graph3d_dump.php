<?php
/**
 * Дамп страниц «Графового 3D-конструктора» (nj667_graph3d) для UI-стенда.
 *
 * Отдаёт ровно тот HTML, что уезжает в браузер: настоящие вьюхи
 * `views/nj667/*.php`, настоящий сервис, а Kohana заменена короткими
 * заглушками (View, HTML, Arr, Security, Gate, Config).
 *
 *   N2J_ROOT=/path/to/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj667_graph3d_dump.php
 *   N2J_PAGE=view|moderate|index|create|settings    какая страница (по умолчанию project)
 *   N2J_AUTO_SIMPLE_FLOW=1                          показать состояние полного маршрута
 *   N2J_CAN_MODERATE=0                              скрыть блок модератора (по умолчанию включён для UI-тестов)
 *
 * Стенд сам ничего не проверяет — его вывод забирает
 * `.tools/nj667_graph3d_ui.mjs` и прогоняет в настоящем браузере (chromium):
 * холст на three.js виден только там.
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
require N2J_STAND_ROOT.'/application/modules/nj667_graph3d/classes/Service/Nj667/Graph3d.php';

// ---------------------------------------------------------------- страница

$PAGE = getenv('N2J_PAGE') ?: 'project';

$graph = [
	'meta'  => ['title' => 'Трёхколёсный велосипед', 'units' => 'unit'],
	'nodes' => [
		['id' => 'n1', 'type' => 'hub', 'x' => 0, 'y' => 0, 'z' => 0, 'label' => 'Рама'],
		['id' => 'n2', 'type' => 'endpoint', 'x' => -6, 'y' => -8, 'z' => 4, 'label' => 'Заднее колесо левое'],
		['id' => 'n3', 'type' => 'endpoint', 'x' => 6, 'y' => -8, 'z' => 4, 'label' => 'Заднее колесо правое'],
		['id' => 'n4', 'type' => 'endpoint', 'x' => 0, 'y' => -8, 'z' => -6, 'label' => 'Переднее колесо'],
		['id' => 'n5', 'type' => 'panel', 'x' => 0, 'y' => 4, 'z' => 2, 'label' => 'Сиденье'],
		['id' => 'n6', 'type' => 'endpoint', 'x' => 0, 'y' => 8, 'z' => -5, 'label' => 'Руль'],
		['id' => 'n7', 'type' => 'connector', 'x' => 0, 'y' => 6, 'z' => 7, 'label' => 'Корзина'],
	],
	'edges' => [
		['id' => 'e1', 'source' => 'n1', 'target' => 'n2', 'type' => 'structural', 'label' => 'ось'],
		['id' => 'e2', 'source' => 'n1', 'target' => 'n3', 'type' => 'structural', 'label' => 'ось'],
		['id' => 'e3', 'source' => 'n1', 'target' => 'n4', 'type' => 'rigid', 'label' => 'вилка'],
		['id' => 'e4', 'source' => 'n1', 'target' => 'n5', 'type' => 'rigid', 'label' => 'подседел'],
		['id' => 'e5', 'source' => 'n1', 'target' => 'n6', 'type' => 'hinge', 'label' => 'колонка'],
		['id' => 'e6', 'source' => 'n1', 'target' => 'n7', 'type' => 'rigid', 'label' => 'крепление'],
	],
];

$project = [
	'id'          => 7,
	'code'        => 'ab34cd56ef78',
	'user_id'     => 3,
	'visitor_uid' => 'stand-visitor',
	'title'       => 'Трёхколёсный велосипед с корзиной',
	'summary'     => 'Три колеса, рама, руль, сиденье и корзина над задней осью.',
	'tags'        => 'велосипед, транспорт',
	'kind'        => 'public',
	'status'      => 'published',
	'featured'    => 1,
	'graph_json'  => json_encode($graph, JSON_UNESCAPED_UNICODE),
	'nodes_count' => count($graph['nodes']),
	'edges_count' => count($graph['edges']),
	'topo_hash'   => Service_Nj667_Graph3d::topology_hash($graph),
	'model'       => 'stand::model',
	'remixes'     => 2,
	'views'       => 42,
	'updated_at'  => '2026-10-10 12:00:00',
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

if ($PAGE === 'project')
{
	$view = new View('nj667/project');
	$auto_simple_flow = getenv('N2J_AUTO_SIMPLE_FLOW') === '1';
	$view->set($shared + [
		'project'   => $project,
		'graph'     => Service_Nj667_Graph3d::graph_of($project),
		'simple_flow' => ['enabled' => $auto_simple_flow, 'state' => 'waiting', 'build_id' => 0, 'error' => ''],
		'auto_simple_flow' => $auto_simple_flow,
		'revisions' => [
			['id' => 2, 'no' => 2, 'kind' => 'commit', 'label' => 'сдвиг \u00abРуль\u00bb',
				'author_kind' => 'user', 'created_at' => '2026-10-10 12:05:00'],
			['id' => 1, 'no' => 1, 'kind' => 'milestone', 'label' => 'Контрольная точка 1',
				'author_kind' => 'user', 'created_at' => '2026-10-10 12:00:00'],
		],
		'events'    => [['kind' => 'submit', 'note' => 'отправлено на модерацию',
			'created_at' => '2026-10-10 12:10:00']],
		'can_start' => TRUE,
		'can_moderate' => getenv('N2J_CAN_MODERATE') !== '0',
	]);
}
elseif ($PAGE === 'view')
{
	$view = new View('nj667/view');
	$view->set($shared + [
		'project'      => $project,
		'graph'        => Service_Nj667_Graph3d::graph_of($project),
		'logged'       => FALSE,
		'can_start'    => FALSE,
		'can_moderate' => FALSE,
	]);
}
elseif ($PAGE === 'moderate')
{
	$view = new View('nj667/moderate');
	$view->set($shared + [
		'queue'   => [$project],
		'current' => $project,
		'graph'   => Service_Nj667_Graph3d::graph_of($project),
		'guard'   => Service_Nj667_Graph3d::graph_validate(Service_Nj667_Graph3d::graph_of($project)),
		'events'  => [['kind' => 'guard', 'note' => 'пре-модерация: ok',
			'created_at' => '2026-10-10 12:09:00']],
		'status'  => '',
	]);
}
elseif ($PAGE === 'settings')
{
	$view = new View('nj667/settings');
	$view->set($shared + [
		'models'       => Service_Nj667_Graph3d::models(),
		'model'        => '',
		'photo_max_px' => 512,
		'rag'          => TRUE,
		'error'        => '',
	]);
}
elseif ($PAGE === 'create')
{
	$view = new View('nj667/create');
	$view->set($shared + [
		'logged'       => TRUE,
		'can_start'    => TRUE,
		'can_moderate' => getenv('N2J_CAN_MODERATE') !== '0',
		'error'        => '',
		'post'         => [],
		'model'        => '',
		'rag'          => TRUE,
	]);
}
else
{
	$view = new View('nj667/index');
	$view->set($shared + [
		'logged'    => TRUE,
		'can_start' => TRUE,
		'showcase'  => [$project],
	]);
}

echo $view->render();
