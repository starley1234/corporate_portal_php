<?php
/**
 * Дымовой рендер вьюх nj661_airadar (PHP-in-WASM): ловит неопределённые
 * переменные/индексы и PHP-варнинги, которые синтаксический линт не видит.
 * Данные — стабы, повторяющие контракт контроллера.
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs /tmp/phpwasm/nj661_views_stand.php
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
		foreach (['/app/application/modules/nj661_airadar/config/'.$name.'.php', '/app/application/config/'.$name.'.php'] as $file)
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

		$path = '/app/application/modules/nj661_airadar/'.trim($dir, '/').'/'.$file.'.'.$ext;

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
}

require '/app/system/classes/Kohana/View.php';

class View extends Kohana_View {}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj661_airadar/classes/Service/Nj661/Airadar.php';

$f = 'Service_Nj661_Airadar';
$messages = [];
$ok = 0;
$bad = 0;

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

		// Показываем саму строку варнинга: по «есть варнинги» дефект не найти.
		$detail = '';

		foreach (preg_split('~<br\s*/?>~i', $html) as $line)
		{
			if (stripos($line, 'Warning') !== FALSE OR stripos($line, 'Undefined') !== FALSE)
			{
				$detail = ' — '.trim(strip_tags($line));
				break;
			}
		}

		echo "ФЕЙЛ $label (в выводе есть PHP-варнинги$detail)\n";
	}
}

$V = '/app/application/modules/nj661_airadar/views/661/';

// ---------------------------------------------------------------- index.php
$report = [
	'id' => 3, 'title' => 'Сводка <острая>', 'summary' => "Строка 1\nСтрока 2", 'created_at' => '2026-10-06 12:00:00',
	'emailed' => TRUE,
	'highlights' => ['Главное <b>жирно</b>'],
	'sections' => json_encode(['sections' => [], 'facts_new' => [], 'ideas' => [], 'max_score' => 0, 'count_items' => 0], JSON_UNESCAPED_UNICODE),
	'stats' => json_encode(['total_facts' => 5], JSON_UNESCAPED_UNICODE),
];
$run = ['id' => 9, 'status' => 'running', 'stage' => 'Чтение', 'stage_note' => '3/12 — huggingface.co', 'error' => '',
	'stats' => json_encode(['sources' => ['huggingface' => 200, 'openrouter' => 30, 'rss' => 0, 'ddg' => 0, 'arena' => 0], 'source_errors' => ['ddg' => 'TimeoutError']], JSON_UNESCAPED_UNICODE)];

$out_index = render($V.'index.php', [
	'ov' => [
		'report'    => $report,
		'run'       => $run,
		'counts'    => ['facts' => 42, 'facts_30d' => 5, 'ideas_new' => 3, 'ideas_accepted' => 1, 'ideas_done' => 0, 'items_30d' => 17, 'items_ok' => 30, 'reports' => 4, 'reports_emailed' => 2],
		'top_items' => [['id' => 1, 'source' => 'openrouter', 'url' => 'https://openrouter.ai/x', 'title' => 'Free Model', 'category' => 'window', 'score' => 95, 'why' => 'бесплатно']],
		'top_ideas' => [['id' => 2, 'title' => 'Идея', 'benefit' => 'польза', 'fit' => 'модуль', 'score' => 80, 'status' => 'new']],
	],
	'categories' => $f::$categories,
	'csrf'       => 'token123',
	'flash'      => ['notice' => 'Прогон запущен', 'error' => ''],
	'mail_to'    => '',
]);

// без отчёта и без прогона (первый запуск)
$out_index_bare = render($V.'index.php', [
	'ov' => ['report' => NULL, 'run' => NULL, 'counts' => [], 'top_items' => [], 'top_ideas' => []],
	'categories' => $f::$categories,
	'csrf'       => 'token123',
	'flash'      => ['notice' => '', 'error' => ''],
	'mail_to'    => 'owner@example.com',
]);

// ---------------------------------------------------------------- report.php
$report_full = [
	'id' => 3, 'title' => 'Отчёт & <тест>', 'summary' => 'Сводка', 'created_at' => '2026-10-06 12:00:00', 'emailed' => FALSE,
	'highlights' => ['пункт 1'],
	'sections' => json_encode([
		'sections' => [['key' => 'window', 'title' => 'Окна возможностей', 'items' => [['title' => 'Free', 'score' => 95, 'why' => 'w', 'url' => 'https://x', 'source' => 'openrouter']]]],
		'facts_new' => [['text' => 'факт', 'tags' => 'qwen,llm', 'source_url' => 'https://y', 'sightings' => 2]],
		'ideas' => [['title' => 'Идея', 'body' => 'b', 'benefit' => 'польза', 'fit' => 'модуль-сервис', 'score' => 70, 'source_url' => 'https://z']],
		'max_score' => 95, 'count_items' => 7,
	], JSON_UNESCAPED_UNICODE),
	'stats' => json_encode(['count_items' => 7, 'max_score' => 95, 'facts_new' => 3, 'ideas_new' => 1, 'total_facts' => 42, 'total_ideas' => 7], JSON_UNESCAPED_UNICODE),
];

// Контракт контроллера: published/is_owner приходят всегда (см. action_report).
$out_report = render($V.'report.php', [
	'report' => $report_full,
	'published' => TRUE,
	'is_owner'  => TRUE,
	'csrf'   => 'token123',
	'flash'  => ['notice' => '', 'error' => 'ошибка'],
]);

// ---------------------------------------------------------------- items.php
$out_items = render($V.'items.php', [
	'rows' => [
		['id' => 1, 'run_id' => 9, 'source' => 'ddg', 'url' => 'https://x', 'host' => 'x.com', 'title' => 'T <остр>', 'snippet' => 'S', 'category' => 'release', 'score' => 60, 'why' => 'w', 'status' => 'analyzed', 'starred' => TRUE, 'published_at' => NULL, 'created_at' => '2026-10-06 10:00:00'],
		['id' => 2, 'run_id' => 9, 'source' => 'rss', 'url' => 'https://y', 'host' => 'y.com', 'title' => 'T2', 'snippet' => '', 'category' => '', 'score' => 0, 'why' => '', 'status' => 'noise', 'starred' => FALSE, 'published_at' => NULL, 'created_at' => '2026-10-06 11:00:00'],
	],
	'categories' => $f::$categories,
	'csrf'       => 'token123',
	'q' => 'qwen', 'source' => 'ddg', 'category' => '', 'status' => '',
]);

$out_items_bare = render($V.'items.php', [
	'rows' => [], 'categories' => $f::$categories, 'csrf' => 't', 'q' => '', 'source' => '', 'category' => '', 'status' => '',
]);

// ---------------------------------------------------------------- facts.php
$out_facts = render($V.'facts.php', [
	'rows' => [
		['id' => 1, 'text' => 'Qwen3: 235B <params>', 'tags' => 'qwen, llm', 'source_url' => 'https://h', 'sightings' => 3, 'first_seen' => '2026-10-01', 'last_seen' => '2026-10-06'],
	],
	'q' => 'qwen',
]);

$out_facts_bare = render($V.'facts.php', ['rows' => [], 'q' => '']);

// ---------------------------------------------------------------- ideas.php
$out_ideas = render($V.'ideas.php', [
	'rows' => [
		['id' => 1, 'title' => 'Идея <X>', 'body' => 'как', 'benefit' => 'польза', 'fit' => 'модуль-сервис Y', 'score' => 80, 'source_url' => 'https://s', 'status' => 'new', 'created_at' => '2026-10-05 10:00:00', 'updated_at' => '2026-10-05 10:00:00'],
		['id' => 2, 'title' => 'Готовая', 'body' => '', 'benefit' => '', 'fit' => '', 'score' => 40, 'source_url' => '', 'status' => 'done', 'created_at' => '2026-10-04 10:00:00', 'updated_at' => '2026-10-05 10:00:00'],
	],
	'status' => '',
	'csrf'   => 'token123',
	'flash'  => ['notice' => 'Статус идеи обновлён', 'error' => ''],
]);

$out_ideas_done = render($V.'ideas.php', ['rows' => [], 'status' => 'done', 'csrf' => 't', 'flash' => ['notice' => '', 'error' => '']]);

// ---------------------------------------------------------------- проверки
no_warnings('index: без PHP-варнингов', $out_index);
no_warnings('index bare: без PHP-варнингов', $out_index_bare);
no_warnings('report: без PHP-варнингов', $out_report);
no_warnings('items: без PHP-варнингов', $out_items);
no_warnings('items bare: без PHP-варнингов', $out_items_bare);
no_warnings('facts: без PHP-варнингов', $out_facts);
no_warnings('facts bare: без PHP-варнингов', $out_facts_bare);
no_warnings('ideas: без PHP-варнингов', $out_ideas);
no_warnings('ideas done: без PHP-варнингов', $out_ideas_done);

contains('index: статус прогона с этапом', $out_index, 'Чтение');
contains('index: пульс живого прогона', $out_index, 'animate-spin');
contains('report: плашка публикации', $out_report, 'опубликован');
contains('report: владелец видит переключатель', $out_report, 'Снять с публикации');
$out_report_off = render($V.'report.php', [
	'report' => $report_full,
	'published' => FALSE,
	'is_owner'  => TRUE,
	'csrf'   => 'token123',
	'flash'  => ['notice' => '', 'error' => ''],
]);
contains('report: снятый с публикации помечен', $out_report_off, 'снят с публикации');
contains('report: снятый можно вернуть', $out_report_off, 'Вернуть в каталог');
contains('index: источники прогона', $out_index, 'Источники: huggingface 200 · openrouter 30');
contains('index: упавшие источники видны', $out_index, 'Не отдались: ddg');
contains('index: подсказка про mail.to_email', $out_index, 'mail.to_email');
contains('index: флеш-уведомление', $out_index, 'Прогон запущен');
contains('index bare: пустое состояние отчёта', $out_index_bare, 'Отчётов ещё нет');
not_contains('index bare: нет подсказки про почту (адрес задан)', $out_index_bare, 'mail.to_email');
contains('report: заголовок экранирован', $out_report, 'Отчёт &amp; &lt;тест&gt;');
contains('report: секция с материалом', $out_report, 'Окна возможностей');
contains('report: идея с пользой', $out_report, 'Польза:');
contains('report: флеш ошибки', $out_report, 'ошибка');
contains('items: карточка с избранным', $out_items, '★');
contains('items: пустое состояние', $out_items_bare, 'Ничего не найдено');
contains('facts: счётчик встреч', $out_facts, 'встречался ×3');
contains('facts: пустое состояние', $out_facts_bare, 'Пока пусто');
contains('ideas: кнопка «В работу»', $out_ideas, 'В работу');
contains('ideas: сброс есть только не для new', $out_ideas, 'Сброс');

echo "\n".implode("\n", $messages)."\n\n";
echo $bad === 0 ? "ВЬЮХИ NJ-661: OK ($ok)\n" : "ВЬЮХИ NJ-661: ПРОВАЛ ($bad из ".($ok + $bad).")\n";
exit($bad === 0 ? 0 : 1);
