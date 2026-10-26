<?php
/**
 * Дымовой рендер вьюх nj662_dialog (PHP-in-WASM): ловит неопределённые
 * переменные/индексы и PHP-варнинги, которые синтаксический линт не видит.
 * Данные — стабы, повторяющие контракт контроллера.
 *
 * Проверяет, в том числе, вёрстку решений 09.10.2026: плашку «запуск —
 * привилегированным», кнопку «Стоп», форму своей реплики, читательскую
 * страницу /dialog/read/<id> и сводку админа /dialog/all.
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs /tmp/phpwasm/nj662_views_stand.php
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
		foreach (['/app/application/modules/nj662_dialog/config/'.$name.'.php', '/app/application/config/'.$name.'.php'] as $file)
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

	/** Ищем сначала в модуле, потом в приложении (partials — общие). */
	public static function find_file($dir, $file, $ext = 'php')
	{
		if (isset($file[0]) AND $file[0] === '/')
		{
			return is_file($file) ? $file : FALSE;
		}

		foreach ([
			'/app/application/modules/nj662_dialog/'.trim($dir, '/').'/'.$file.'.'.$ext,
			'/app/application/'.trim($dir, '/').'/'.$file.'.'.$ext,
		] as $path)
		{
			if (is_file($path)) return $path;
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

require '/app/system/classes/Kohana/View.php';

class View extends Kohana_View {}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj662_dialog/classes/Service/Nj662/Dialog.php';
require '/app/application/modules/nj662_dialog/classes/Service/Nj662/Models.php';

// Варнинги и notice в php-wasm уходят в stderr и в HTML не попадают, поэтому
// превращаем их в исключения: render() тогда честно сообщит «ФЕЙЛ», а не
// похоронит проблему в потоке ошибок.
set_error_handler(function ($no, $str, $file, $line) {
	throw new ErrorException($str, 0, $no, $file, $line);
});

$messages = [];
$ok  = 0;
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
		echo "ФЕЙЛ $label (в выводе есть PHP-варнинги)\n";
	}
}

$D  = 'Service_Nj662_Dialog';
$V  = '/app/application/modules/nj662_dialog/views/662/';

// ---------------------------------------------------------------- данные
$dialog = [
	'id'            => 12,
	'user_id'       => 5,
	'topic'         => 'Как проверить спрос на услугу <без> бюджета',
	'brief'         => "Факты: ниша локальная, бюджета нет.\nНужно решение за неделю.",
	'a_name'        => 'Структура',
	'b_name'        => 'Импульс',
	'a_system'      => 'Ты — Структура.',
	'b_system'      => 'Ты — Импульс.',
	'a_model'       => 'openrouter::google/gemini-2.5-flash-lite',
	'b_model'       => '',
	'pairs'         => 5,
	'context_pairs' => 3,
	'summarize'     => TRUE,
	'ddg'           => 2,
	'status'        => 'paused',
	'stage'         => 'Пауза',
	'stage_note'    => 'остановлено владельцем',
	'stage_at'      => '2026-10-09 10:00:00',
	'turn'          => 2,
	'searches_used' => 1,
	'tokens_total'  => 3200,
	'summary'       => 'Сошлись на проверке спроса по заявкам.',
	'outcome'       => '',
	'error'         => '',
	'job_id'        => 0,
	'is_public'     => TRUE,
	'published_at'  => '2026-10-09 12:00:00',
	'created_at'    => '2026-10-09 09:00:00',
	'updated_at'    => '2026-10-09 11:30:00',
	'started_at'    => '2026-10-09 09:05:00',
	'finished_at'   => '2026-10-09 11:30:00',
];

$messages_rows = [
	['id' => 1, 'dialog_id' => 12, 'turn' => 1, 'who' => 'a', 'author_name' => 'Структура',
		'content' => "Разложим задачу: что measurим.\n\nВторой абзац реплики.", 'summary' => '', 'model' => 'openrouter::x', 'tokens' => 120, 'created_at' => '2026-10-09 09:10:00'],
	['id' => 2, 'dialog_id' => 12, 'turn' => 1, 'who' => 'b', 'author_name' => 'Импульс',
		'content' => 'Да, и вот ход: обзвон десяти клиентов.', 'summary' => 'Обмен: план проверки.', 'model' => '', 'tokens' => 90, 'created_at' => '2026-10-09 09:12:00'],
	// Реплика владельца — новинка 09.10.2026: третий цвет в ленте.
	['id' => 3, 'dialog_id' => 12, 'turn' => 2, 'who' => 'user', 'author_name' => 'owner',
		'content' => 'Хватит общих слов — посчитайте деньги.', 'summary' => '', 'model' => '', 'tokens' => 0, 'created_at' => '2026-10-09 11:29:00'],
];

$searches_rows = [
	['id' => 7, 'dialog_id' => 12, 'turn' => 1, 'agent' => 'a', 'query' => 'проверка спроса без бюджета',
		'found' => 2, 'results' => [['title' => 'Спрос', 'url' => 'https://example.com/a', 'body' => 'текст находки']],
		'pages' => [], 'note' => '', 'error' => '', 'created_at' => '2026-10-09 09:11:00'],
];

$knowledge_rows = [
	['id' => 3, 'dialog_id' => 12, 'user_id' => 5, 'title' => 'Заметка о спросе', 'source' => 'https://example.com/a',
		'preview' => 'Короткий отрывок материала для карточки.',
		'chars' => 1200, 'index_state' => 'indexed', 'index_note' => 'ok', 'created_at' => '2026-10-09 09:00:00', 'updated_at' => '2026-10-09 09:00:00'],
];

$events_rows = [
	['id' => 1, 'dialog_id' => 12, 'kind' => 'say',    'message' => 'Своя реплика: «посчитайте деньги»', 'created_at' => '2026-10-09 11:29:00'],
	['id' => 2, 'dialog_id' => 12, 'kind' => 'stop',   'message' => 'Стоп: разговор остановлен на обмене 2', 'created_at' => '2026-10-09 11:28:00'],
	['id' => 3, 'dialog_id' => 12, 'kind' => 'pause',  'message' => 'Пауза: ход остановлен', 'created_at' => '2026-10-09 11:00:00'],
];

$models = Service_Nj662_Models::offers();
$limits = $D::limits();

// ---------------------------------------------------------------- index.php
$out_desk = render($V.'index.php', [
	'logged'      => TRUE,
	'can_start'   => TRUE,
	'gate'        => NULL,
	'wall'        => NULL,
	'dialogs'     => [$dialog + ['replies' => 3, 'last_reply' => 'Хватит общих слов', 'last_author' => 'owner']],
	'counters'    => ['dialogs' => 1, 'running' => 0, 'done' => 0, 'public' => 1],
	'samples'     => $D::samples(),
	'defaults'    => $D::defaults(),
	'limits'      => $limits,
	'roles'       => $D::roles(),
	'models'      => $models,
	'models_default' => Service_Nj662_Models::default_spec(),
	'csrf'        => 'token123',
	'flash'       => ['tone' => 'notice', 'text' => 'Диалог создан'],
]);

// Зарегистрированный БЕЗ права запуска: формы нет, есть объяснение.
$out_desk_gate = render($V.'index.php', [
	'logged'      => TRUE,
	'can_start'   => FALSE,
	'gate'        => render($V.'gate.php', ['logged' => TRUE, 'wall' => NULL]),
	'wall'        => NULL,
	'dialogs'     => [],
	'counters'    => ['dialogs' => 0, 'running' => 0, 'done' => 0, 'public' => 0],
	'samples'     => $D::samples(),
	'defaults'    => $D::defaults(),
	'limits'      => $limits,
	'roles'       => $D::roles(),
	'models'      => $models,
	'models_default' => Service_Nj662_Models::default_spec(),
	'csrf'        => 'token123',
	'flash'       => ['tone' => '', 'text' => ''],
]);

// ---------------------------------------------------------------- dialog.php
$out_dialog = render($V.'dialog.php', [
	'dialog'    => $dialog,
	'progress'  => $D::progress($dialog),
	'messages'  => $messages_rows,
	'searches'  => $searches_rows,
	'knowledge' => $knowledge_rows,
	'events'    => $events_rows,
	'own'       => TRUE,
	'can_start' => TRUE,
	'replies'   => 3,
	'models'    => $models,
	'limits'    => $limits,
	'samples'   => $D::samples(),
	'csrf'      => 'token123',
	'flash'     => ['tone' => '', 'text' => ''],
]);

// Идущий диалог: видны «Пауза» и «Стоп», формы своей реплики нет.
$out_dialog_live = render($V.'dialog.php', [
	'dialog'    => array_merge($dialog, ['status' => 'running', 'stage' => 'Обмен', 'stage_note' => 'агент пишет реплику']),
	'progress'  => $D::progress(array_merge($dialog, ['status' => 'running'])),
	'messages'  => $messages_rows,
	'searches'  => $searches_rows,
	'knowledge' => [],
	'events'    => $events_rows,
	'own'       => TRUE,
	'can_start' => TRUE,
	'replies'   => 3,
	'models'    => $models,
	'limits'    => $limits,
	'samples'   => $D::samples(),
	'csrf'      => 'token123',
	'flash'     => ['tone' => '', 'text' => ''],
]);

// Владелец без права запуска: продолжить нельзя, реплику тоже.
$out_dialog_noaccess = render($V.'dialog.php', [
	'dialog'    => $dialog,
	'progress'  => $D::progress($dialog),
	'messages'  => $messages_rows,
	'searches'  => [],
	'knowledge' => [],
	'events'    => [],
	'own'       => TRUE,
	'can_start' => FALSE,
	'replies'   => 3,
	'models'    => $models,
	'limits'    => $limits,
	'samples'   => $D::samples(),
	'csrf'      => 'token123',
	'flash'     => ['tone' => '', 'text' => ''],
]);

// Остановленный диалог: подпись «остановлен» и кнопка «Продолжить разговор».
$out_dialog_stopped = render($V.'dialog.php', [
	'dialog'    => array_merge($dialog, ['status' => 'stopped', 'stage' => 'Стоп', 'stage_note' => 'остановлено владельцем, задание снято с очереди']),
	'progress'  => $D::progress(array_merge($dialog, ['status' => 'stopped'])),
	'messages'  => $messages_rows,
	'searches'  => [],
	'knowledge' => [],
	'events'    => $events_rows,
	'own'       => TRUE,
	'can_start' => TRUE,
	'replies'   => 3,
	'models'    => $models,
	'limits'    => $limits,
	'samples'   => $D::samples(),
	'csrf'      => 'token123',
	'flash'     => ['tone' => '', 'text' => ''],
]);

// ---------------------------------------------------------------- read.php
$out_read = render($V.'read.php', [
	'dialog'    => $dialog,
	'progress'  => $D::progress($dialog),
	'messages'  => $messages_rows,
	'searches'  => $searches_rows,
	'events'    => $events_rows,
	'own'       => TRUE,
	'can_start' => TRUE,
	'csrf'      => 'token123',
	'flash'     => ['tone' => '', 'text' => ''],
]);

$out_read_guest = render($V.'read.php', [
	'dialog'    => $dialog,
	'progress'  => $D::progress($dialog),
	'messages'  => $messages_rows,
	'searches'  => [],
	'events'    => [],
	'own'       => FALSE,
	'can_start' => FALSE,
	'csrf'      => 'token123',
	'flash'     => ['tone' => '', 'text' => ''],
]);

$out_read_draft = render($V.'read.php', [
	'dialog'    => array_merge($dialog, ['is_public' => FALSE, 'status' => 'draft', 'summary' => '', 'brief' => '']),
	'progress'  => $D::progress(array_merge($dialog, ['status' => 'draft'])),
	'messages'  => [],
	'searches'  => [],
	'events'    => [],
	'own'       => TRUE,
	'can_start' => TRUE,
	'csrf'      => 'token123',
	'flash'     => ['tone' => '', 'text' => ''],
]);

// ---------------------------------------------------------------- admin.php
$admin_row = $dialog + [
	'replies' => 3, 'owner_replies' => 1, 'last_reply' => 'Хватит общих слов',
	'owner_email' => 'owner@example.com', 'owner_login' => 'owner',
];

$out_admin = render($V.'admin.php', [
	'rows'        => [$admin_row, array_merge($dialog, ['id' => 13, 'status' => 'stopped', 'is_public' => FALSE,
		'replies' => 0, 'owner_replies' => 0, 'owner_email' => '', 'owner_login' => ''])],
	'total'       => ['all' => 2, 'public' => 1, 'running' => 0, 'done' => 0, 'stopped' => 1],
	'q'           => '',
	'status'      => '',
	'status_list' => $D::$status_list,
	'page'        => 1,
	'pages'       => 1,
	'per'         => 30,
	'per_options' => [20, 30, 50, 100],
	'csrf'        => 'token123',
	'flash'       => ['tone' => '', 'text' => ''],
]);

$out_admin_empty = render($V.'admin.php', [
	'rows'        => [],
	'total'       => ['all' => 0, 'public' => 0, 'running' => 0, 'done' => 0, 'stopped' => 0],
	'q'           => 'ищем',
	'status'      => 'stopped',
	'status_list' => $D::$status_list,
	'page'        => 1,
	'pages'       => 1,
	'per'         => 30,
	'per_options' => [20, 30, 50, 100],
	'csrf'        => 'token123',
	'flash'       => ['tone' => 'error', 'text' => 'Ничего не найдено'],
]);

// ---------------------------------------------------------------- public.php
$out_public = render($V.'public.php', [
	'dialog'   => $dialog,
	'progress' => $D::progress($dialog),
	'messages' => $messages_rows,
	'searches' => $searches_rows,
	'own'      => FALSE,
	'csrf'     => 'token123',
	'flash'    => ['tone' => '', 'text' => ''],
]);

// ---------------------------------------------------------------- проверки
no_warnings('index: без PHP-варнингов', $out_desk);
no_warnings('index (без права запуска): без PHP-варнингов', $out_desk_gate);
no_warnings('dialog: без PHP-варнингов', $out_dialog);
no_warnings('dialog (идёт): без PHP-варнингов', $out_dialog_live);
no_warnings('dialog (без права запуска): без PHP-варнингов', $out_dialog_noaccess);
no_warnings('dialog (остановлен): без PHP-варнингов', $out_dialog_stopped);
no_warnings('read: без PHP-варнингов', $out_read);
no_warnings('read (гость): без PHP-варнингов', $out_read_guest);
no_warnings('read (черновик, пусто): без PHP-варнингов', $out_read_draft);
no_warnings('admin: без PHP-варнингов', $out_admin);
no_warnings('admin (пусто): без PHP-варнингов', $out_admin_empty);
no_warnings('public: без PHP-варнингов', $out_public);

// --- доступ: запуск только привилегированным
contains('index: форма создания есть у привилегированного', $out_desk, 'data-nj662-create');
not_contains('index: без права формы создания нет', $out_desk_gate, 'data-nj662-create');
contains('index: плашка «запуск — привилегированным»', $out_desk_gate, 'Запуск — привилегированным');
contains('index: без права объяснение про роль vip', $out_desk_gate, 'vip');

// --- стоп и продолжение
contains('dialog (идёт): кнопка «Стоп»', $out_dialog_live, '/stop');
contains('dialog (идёт): кнопка «Пауза»', $out_dialog_live, '/pause');
not_contains('dialog (идёт): формы реплики нет — разговор живой', $out_dialog_live, 'name="resume"');
contains('dialog (идёт): вместо формы — подсказка про паузу', $out_dialog_live, 'реплику добавляют на паузе');
contains('dialog: форма своей реплики', $out_dialog, 'data-nj662-say');
contains('dialog: реплика уходит на /say', $out_dialog, '/say');
contains('dialog: две кнопки — «продолжить» и «только реплику»', $out_dialog, 'name="resume" value="0"');
contains('dialog: про счётчик от начала', $out_dialog, 'от начала диалога');
contains('dialog (остановлен): подпись статуса', $out_dialog_stopped, 'остановлен');
contains('dialog (остановлен): кнопка «Продолжить разговор»', $out_dialog_stopped, 'Продолжить разговор');
contains('dialog: ссылка на читательскую страницу', $out_dialog, '/dialog/read/12');

// --- своя реплика в ленте: третий цвет и имя автора
contains('dialog: реплика владельца в ленте', $out_dialog, 'fa-user-pen');
contains('dialog: имя автора реплики', $out_dialog, 'owner');
contains('public: реплика владельца видна читателю', $out_public, 'fa-user-pen');
contains('public: ссылка на чтение', $out_public, '/dialog/read/12');

// --- доступ без права
contains('dialog (без права): вместо «Продолжить» — плашка', $out_dialog_noaccess, 'Продолжить могут привилегированные');
not_contains('dialog (без права): кнопки запуска нет', $out_dialog_noaccess, '/start');

// --- чтение: крупнее и без управлений
contains('read: заголовок разговора', $out_read, 'проверить спрос');
contains('read: крупный шрифт реплик', $out_read, 'text-lg leading-relaxed');
contains('read: тема — заголовок страницы эталона v6', $out_read, 'class="n2j-h1');
contains('read: источники обмена', $out_read, 'Что агенты искали в интернете');
contains('read: реплика владельца', $out_read, 'fa-user-pen');
contains('read: ссылка «управление» владельцу', $out_read, '/dialog/12');
not_contains('read: управлений на странице чтения нет', $out_read, 'data-nj662-run-box');
contains('read (черновик): предупреждение «не опубликован»', $out_read_draft, 'не опубликован');
contains('read (черновик): пустое состояние ленты', $out_read_draft, 'ещё не сказали ни слова');
not_contains('read (гость): управление не светим', $out_read_guest, 'Управление диалогом');

// --- сводка админа
contains('admin: заголовок сводки', $out_admin, 'Все диалоги сайта');
contains('admin: владелец в строке', $out_admin, 'owner@example.com');
contains('admin: опубликованный отмечен', $out_admin, 'опубликован');
contains('admin: неопубликованный отмечен', $out_admin, 'не опубликован');
contains('admin: остановленный диалог в сводке', $out_admin, 'остановлен');
contains('admin: счётчик реплик владельца', $out_admin, 'fa-user-pen');
contains('admin: фильтр по статусам', $out_admin, 'любой статус');
contains('admin: ссылка на чтение', $out_admin, '/dialog/read/12');
contains('admin (пусто): пустое состояние', $out_admin_empty, 'Под этот фильтр диалогов нет');
contains('admin: пагинация общим партиалом', $out_admin, 'Показано');

echo "\n".implode("\n", $messages)."\n\n";
echo $bad === 0 ? "ВЬЮХИ NJ-662: OK ($ok)\n" : "ВЬЮХИ NJ-662: ПРОВАЛ ($bad из ".($ok + $bad).")\n";
exit($bad === 0 ? 0 : 1);
