<?php
/**
 * Дымовой рендер вьюх nj660_arch (edit/index/view) в PHP-in-WASM:
 * ловит runtime-ошибки (неопределённые переменные/индексы), которые
 * синтаксический линт не видит. Данные — стабы, похожие на реальные.
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs /tmp/phpwasm/nj660_views_stand.php
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
		foreach (['/app/application/modules/nj660_arch/config/'.$name.'.php', '/app/application/config/'.$name.'.php'] as $file)
		{
			if (is_file($file)) { $data = require $file; return new Kohana_Config_Group(is_array($data) ? $data : []); }
		}

		return new Kohana_Config_Group([]);
	}
}

class View_Exception extends Exception {}

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

		$path = '/app/application/modules/nj660_arch/'.trim($dir, '/').'/'.$file.'.'.$ext;

		return is_file($path) ? $path : FALSE;
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

	public static function anchor($uri, $title = NULL, array $attributes = NULL)
	{
		return '<a href="'.htmlspecialchars($uri, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars((string) ($title === NULL ? $uri : $title), ENT_QUOTES, 'UTF-8').'</a>';
	}
}

// Kohana_View (extract переменных в область функции)
require '/app/system/classes/Kohana/View.php';

class View extends Kohana_View {}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj660_arch/classes/Service/Nj660/Arch.php';
require '/app/application/modules/nj660_arch/classes/Service/Nj660/Expert.php';

$f = 'Service_Nj660_Arch';
$ok = 0; $bad = 0;

$messages = [];

function render($file, array $data)
{
	global $ok, $bad, $messages;

	try
	{
		$view = new View($file);
		$view->set($data);
		$out = $view->render();
		$messages[] = "ok   ".basename($file)." отрендерился (".strlen($out)." байт)";
		$ok++;
		return $out;
	}
	catch (Throwable $e)
	{
		$bad++;
		$messages[] = "ФЕЙЛ ".basename($file).": ".$e->getMessage()." @ line ".$e->getLine();
		return '';
	}
}

// ---------------------------------------------------------------- edit.php
$dsl = $f::preset('moe-small')['dsl'];
$out_edit = render('/app/application/modules/nj660_arch/views/660/edit.php', [
	'dsl'     => $dsl,
	'title'   => 'Мой MoE <тест> & escape',
	'code'    => 'abcdefghij',
	'palette' => $f::palette(),
	'families'=> $f::families(),
	'csrf'    => 'token123',
	'error'   => 'ошибка с <уголками>',
]);

// ---------------------------------------------------------------- index.php
$out_index = render('/app/application/modules/nj660_arch/views/660/index.php', [
	'rows'    => [],
	'presets' => $f::presets(),
	'zoo'     => $f::zoo(),
	'fmt'     => [$f, 'fmt_params'],
	'notice'  => 'Проект удалён',
	'error'   => 'Что-то <не так>',
]);

// регрессия (баг «Undefined variable $notice»): рендер БЕЗ опциональных
// флеш-переменных не должен падать и не должен выдавать варнинги
$out_index_bare = render('/app/application/modules/nj660_arch/views/660/index.php', [
	'rows'    => [],
	'presets' => $f::presets(),
	'zoo'     => $f::zoo(),
	'fmt'     => [$f, 'fmt_params'],
]);
no_warnings('регрессия: index без notice/error не падает', $out_index_bare);

// ---------------------------------------------------------------- view.php
$dsl = $f::preset('moe-small')['dsl'];
$eval = $f::evaluate($dsl);
$design = [
	'id' => 1, 'code' => 'abcdefghij', 'user_id' => NULL, 'visitor' => 'g:xyz',
	'title' => 'Тест & <модель>', 'family' => 'decoder',
	'dsl' => json_encode($dsl, JSON_UNESCAPED_UNICODE),
	'metrics' => json_encode(['totals' => $eval['totals']], JSON_UNESCAPED_UNICODE),
];
$chats = [
	['kind' => 'simulate', 'prompt' => 'привет', 'output' => 'Привет! Я — тестовая модель.', 'model' => 'local::test', 'ms' => 120, 'error' => '', 'created_at' => '2026-10-06 12:00:00'],
	['kind' => 'simulate', 'prompt' => 'кто ты?', 'output' => '', 'model' => 'local::test', 'ms' => 0, 'error' => 'timeout', 'created_at' => '2026-10-06 12:01:00'],
];
// как контроллер: parsed/raw считаются из output
$reviews = [];
foreach ([
	['kind' => 'review', 'prompt' => '', 'output' => json_encode(['score' => 7, 'verdict' => 'Крепкий дизайн', 'strengths' => ['GQA', 'MoE'], 'risks' => [['what' => 'мало KV', 'fix' => 'поднять до 8']], 'advice' => ['добавить ALiBi'], 'reading' => ['GQA-статья']], JSON_UNESCAPED_UNICODE), 'model' => 'local::test', 'ms' => 900, 'error' => '', 'created_at' => '2026-10-06 12:02:00'],
	['kind' => 'review', 'prompt' => '', 'output' => 'сырой не-JSON ответ', 'model' => 'local::test', 'ms' => 900, 'error' => '', 'created_at' => '2026-10-06 12:03:00'],
] as $run)
{
	$run['parsed'] = json_decode((string) $run['output'], TRUE);
	$run['raw'] = (string) $run['output'];
	$reviews[] = $run;
}

$out_view = render('/app/application/modules/nj660_arch/views/660/view.php', [
	'design'  => $design,
	'dsl'     => $dsl,
	'eval'    => $eval,
	'mine'    => TRUE,
	'logged'  => TRUE,
	'reviews' => $reviews,
	'chats'   => $chats,
	'csrf'    => 'token123',
	'engine'  => 'local::test-engine',
	'llm_left' => 18,
	'flash_saved'    => TRUE,
	'flash_reviewed' => TRUE,
	'error'   => 'Движок не ответил: timeout',
]);

function contains($label, $haystack, $needle)
{
	global $ok, $bad;

	if (strpos($haystack, $needle) !== FALSE)
	{
		$ok++;
		echo "ok   $label\n";
	}
	else
	{
		$bad++;
		echo "ФЕЙЛ $label (нет '".str_replace("'", "", $needle)."')\n";
	}
}

function not_contains($label, $haystack, $needle)
{
	global $ok, $bad;

	if (strpos($haystack, $needle) === FALSE)
	{
		$ok++;
		echo "ok   $label\n";
	}
	else
	{
		$bad++;
		echo "ФЕЙЛ $label (лишнее '".str_replace("'", "", $needle)."')\n";
	}
}

contains('edit: window.NJ660 эмбитится', $out_edit, 'window.NJ660 =');
contains('edit: форма сохранения', $out_edit, 'id="nj660-form"');
contains('edit: палитра для JS', $out_edit, 'id="nj660-palette"');
contains('edit: csrf в форме', $out_edit, 'value="token123"');
contains('edit: поле dsl', $out_edit, 'id="nj660-dsl"');
function no_warnings($label, $html)
{
	global $ok, $bad;

	if (strpos($html, '<b>Warning</b>') === FALSE AND strpos($html, 'Undefined') === FALSE)
	{
		$ok++;
		echo "ok   $label\n";
	}
	else
	{
		$bad++;
		echo "ФЕЙЛ $label (в выводе есть PHP-варнинги)\n";
	}
}

no_warnings('edit: без PHP-варнингов', $out_edit);
no_warnings('index: без PHP-варнингов', $out_index);
no_warnings('view: без PHP-варнингов', $out_view);
contains('index: флеш-уведомление показано', $out_index, 'Проект удалён');
contains('index: зоопарк отрисован', $out_index, 'Llama-3-8B');
contains('index: пресеты отрисованы', $out_index, 'Mixtral-8x7B');
contains('view: чат-контейнер', $out_view, 'id="nj660-chat"');
contains('view: data-атрибут для JS', $out_view, 'data-nj660-view');
contains('view: флеш сохранения', $out_view, 'Проект сохранён');
contains('view: разбор LLM с JSON', $out_view, 'Крепкий дизайн');
contains('view: сырой не-JSON показан', $out_view, 'сырой не-JSON ответ');
contains('view: экспорт pytorch', $out_view, '/arch/export/pytorch/abcdefghij');

// регрессия: view без флеш-переменных и edit без error тоже рендерятся чисто
$out_view_bare = render('/app/application/modules/nj660_arch/views/660/view.php', [
	'design'  => $design,
	'dsl'     => $dsl,
	'eval'    => $eval,
	'mine'    => FALSE,
	'logged'  => FALSE,
	'reviews' => [],
	'chats'   => [],
	'csrf'    => 'token123',
	'engine'  => 'local::test-engine',
	'llm_left' => 0,
]);
no_warnings('регрессия: view без флешей не падает', $out_view_bare);
not_contains('регрессия: view без флешей — нет «Проект сохранён»', $out_view_bare, 'Проект сохранён');
not_contains('регрессия: view без флешей — нет блока ошибки', $out_view_bare, 'Движок не ответил: timeout');

$out_edit_bare = render('/app/application/modules/nj660_arch/views/660/edit.php', [
	'dsl'     => $dsl,
	'title'   => '',
	'code'    => '',
	'palette' => $f::palette(),
	'families'=> $f::families(),
	'csrf'    => 'token123',
]);
no_warnings('регрессия: edit без error не падает', $out_edit_bare);

// контракт контроллера (статически): query($key, $default) в Kohana — СЕТТЕР,
// двухаргументные вызовы запрещены; флеш-переменные передаются во вьюхи
$ctrl = (string) file_get_contents('/app/application/modules/nj660_arch/classes/Controller/Nj660/Arch.php');
contains('контракт: index получает notice из query', $ctrl, "'notice'  => (string) \$this->request->query('notice')");
contains('контракт: view получает flash_saved', $ctrl, "'flash_saved'    => (bool) \$this->request->query('saved')");

if (preg_match_all("/query\('[a-z]+',/", $ctrl, $m))
{
	$bad++;
	echo 'ФЕЙЛ контракт: двухаргументные query-сеттеры: '.implode(', ', $m[0])."\n";
}
else
{
	$ok++;
	echo "ok   контракт: двухаргументных query-сеттеров нет\n";
}
echo implode("\n", $messages)."\n\n";
echo $bad === 0 ? "ВЬЮХИ NJ-660: OK ($ok)\n" : "ВЬЮХИ NJ-660: ПРОВАЛ ($bad из ".($ok + $bad).")\n";
if (getenv("N2J_DUMP")) { echo "\n<!--DUMP-->\n".$out_view."\n<!--/DUMP-->\n"; }
exit($bad === 0 ? 0 : 1);
