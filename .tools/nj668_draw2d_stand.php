<?php
/**
 * Стенд nj668_draw2d («Плоские иллюстрации»): чистые функции сцены
 * и рендер страниц views/nj668/.
 *
 *   N2J_ROOT=$(pwd) node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj668_draw2d_stand.php
 *
 * Сети и БД в стенде нет: всё, что до них дотягивается, обязано деградировать,
 * а не падать. nj667 не трогаем — только читаем как образец.
 */

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
			N2J_STAND_ROOT.'/application/modules/nj668_draw2d/config/'.$name.'.php',
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
		$path = N2J_STAND_ROOT.'/application/modules/nj668_draw2d/'.trim($dir, '/').'/'.$file.'.'.$ext;

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
	public static function logged_in() { return TRUE; }

	public static function wall($return_url = '', array $options = [])
	{
		return '<div class="nj-wall">'.HTML::chars((string) Arr::get($options, 'title', 'Нужен аккаунт')).'</div>';
	}
}

class View
{
	protected $file;
	protected $data = [];

	public static function factory($file = NULL, $data = NULL)
	{
		return new self($file, $data);
	}

	public function __construct($file = NULL, $data = NULL)
	{
		$this->file = $file;

		if (is_array($data))
		{
			$this->data = $data;
		}
	}

	public function set($data)
	{
		$this->data = (array) $data + $this->data;

		return $this;
	}

	public function render()
	{
		$path = N2J_STAND_ROOT.'/application/modules/nj668_draw2d/views/'.$this->file.'.php';

		if ( ! is_file($path))
		{
			throw new Exception('вьюха не найдена: '.$this->file);
		}

		extract($this->data, EXTR_SKIP);
		ob_start();
		include $path;

		return ob_get_clean();
	}
}

require N2J_STAND_ROOT.'/application/modules/nj668_draw2d/classes/Service/Nj668/Scene.php';
require N2J_STAND_ROOT.'/application/modules/nj668_draw2d/classes/Service/Nj668/Draw2d.php';

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

function contains($label, $haystack, $needle)
{
	check($label, strpos((string) $haystack, (string) $needle) !== FALSE ? 'true' : 'false', 'true');
}

function render($file, array $data)
{
	global $ok, $bad;

	try
	{
		$view = new View($file);
		$view->set($data);
		$out  = $view->render();

		check('рендер '.$file.' ('.strlen($out).' байт)', strlen($out) > 400 ? 'true' : 'false', 'true');

		return $out;
	}
	catch (Throwable $e)
	{
		$bad++;
		echo "ФЕЙЛ рендер $file: ".$e->getMessage()." @ ".$e->getLine()."\n";

		return '';
	}
}

// ------------------------------------------------------------------ сцена

$empty = Service_Nj668_Scene::scene_new();
check('сцена: ширина', (int) $empty['w'], 960);
check('сцена: высота', (int) $empty['h'], 640);
check('сцена: фигур нет', count($empty['shapes']), 0);

$junk = Service_Nj668_Scene::clean([
	'w' => '960', 'h' => 640, 'bg' => 'red',
	'shapes' => [
		['id' => 'a', 'kind' => 'rect', 'x' => 10, 'y' => 20, 'w' => 30, 'h' => 40, 'fill' => '#ff0000'],
		['id' => 'b', 'kind' => 'nope', 'x' => 1],
		['id' => 'c', 'type' => 'circle', 'x' => 50, 'y' => 50, 'r' => 12, 'fill' => '#00ff00'],
		['kind' => 'line', 'x1' => 0, 'y1' => 0, 'x2' => 10, 'y2' => 10, 'stroke' => '#111111', 'sw' => 2],
		['id' => 'script', 'kind' => 'path', 'd' => 'javascript:alert(1)'],
		['id' => 'okp', 'kind' => 'path', 'd' => 'M 0 0 L 10 10 C 20 20 30 0 40 10 Z'],
		['id' => 'bez', 'kind' => 'cubic', 'pts' => [[0, 0], [10, 20], [30, 20], [40, 0]], 'stroke' => '#222222', 'sw' => 3],
		['id' => 'tri', 'kind' => 'poly', 'pts' => [[0, 0], [10, 0], [5, 8]], 'fill' => '#abcdef'],
	],
]);
$kinds = [];
foreach ($junk['shapes'] as $s) { $kinds[] = $s['kind']; }
check_true('чистка: выкинут неизвестный kind', ! in_array('nope', $kinds, TRUE));
check_true('чистка: type=circle принят как kind', in_array('circle', $kinds, TRUE));
check_true('чистка: path без команд выкинут', ! in_array('script', array_column($junk['shapes'], 'id'), TRUE));
check_true('чистка: path M/L/C/Z жив', in_array('okp', array_column($junk['shapes'], 'id'), TRUE));
check_true('чистка: cubic из 4 точек', in_array('cubic', $kinds, TRUE));
check_true('чистка: poly из 3 точек', in_array('poly', $kinds, TRUE));
check_true('чистка: line из x1/y1', in_array('line', $kinds, TRUE));
check('чистка: bg мусор → дефолт', $junk['bg'], '#eef6ff');

$d = Service_Nj668_Scene::cubic_d([[0, 0], [10, 20], [30, 20], [40, 0]], TRUE);
check_true('cubic_d: M и C', strpos($d, 'M 0 0') !== FALSE AND strpos($d, 'C 10 20') !== FALSE);
check_true('cubic_d: closed → Z', substr($d, -1) === 'Z');
check('cubic_d: мало точек', Service_Nj668_Scene::cubic_d([[0, 0]]), '');

check('path: javascript выкинут', Service_Nj668_Scene::clean_path('javascript:alert(1)'), '');
check('path: url выкинут', Service_Nj668_Scene::clean_path('M 0 0 url(http://x)'), '');
check_true('path: Q разрешён', Service_Nj668_Scene::clean_path('M 0 0 Q 10 10 20 0') !== '');

$sunset = Service_Nj668_Scene::sample('sunset');
$house  = Service_Nj668_Scene::sample('house');
$balloon = Service_Nj668_Scene::sample('balloon');
check_true('сэмпл sunset: есть фигуры', count($sunset['shapes']) >= 6);
check_true('сэмпл house: есть крыша', count($house['shapes']) >= 6);
check_true('сэмпл balloon: есть шар', count($balloon['shapes']) >= 6);

$svg = Service_Nj668_Scene::svg($sunset, 480, 320);
check_true('svg: xmlns', strpos($svg, 'xmlns="http://www.w3.org/2000/svg"') !== FALSE);
check_true('svg: viewBox', strpos($svg, 'viewBox="0 0 960 640"') !== FALSE);
check_true('svg: preserveAspectRatio', strpos($svg, 'preserveAspectRatio="xMidYMid meet"') !== FALSE);
check_true('svg: есть circle', strpos($svg, '<circle') !== FALSE);
check_true('svg: есть path Безье', strpos($svg, '<path d="M ') !== FALSE);
check_true('svg: нет <text', strpos($svg, '<text') === FALSE);
check_true('svg: нет script', strpos($svg, '<script') === FALSE);
check_true('svg: линия без заливки', strpos(Service_Nj668_Scene::svg($house), 'fill="none"') !== FALSE);

$dup = Service_Nj668_Scene::clean([
	'shapes' => [
		['id' => 's1', 'kind' => 'rect', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10, 'fill' => '#111111'],
		['id' => 's1', 'kind' => 'rect', 'x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'fill' => '#222222'],
	],
]);
check('чистка: дубль id переименован', $dup['shapes'][1]['id'], 's2');

// ------------------------------------------------------------------ дверь

check('канал generate', Service_Nj668_Draw2d::CH_GENERATE, 'nj668.generate');
check('канал ideas', Service_Nj668_Draw2d::CH_IDEAS, 'nj668.ideas');
check('статус draft', Service_Nj668_Draw2d::S_DRAFT, 'draft');
check('статус published', Service_Nj668_Draw2d::S_PUBLISHED, 'published');
check('статус rejected', Service_Nj668_Draw2d::S_REJECTED, 'rejected');
check_true('пресеты: не меньше трёх', count(Service_Nj668_Draw2d::presets()) >= 3);
$picked = Service_Nj668_Draw2d::idea_presets_pick(3);
check_true('идеи: три запасных', count($picked) === 3);
check('лимит фигур', (int) Service_Nj668_Draw2d::limits('max_shapes', 0), 120);
check('таймаут generate', Service_Nj668_Draw2d::timeout('generate'), 600);

$code = Service_Nj668_Draw2d::code_new(12);
check('код: длина 12', strlen($code), 12);
check_true('код: алфавит без 0/1', preg_match('~^[23456789abcdefghijkmnpqrstuvwxyz]{12}$~', $code) === 1);

$scene_of = Service_Nj668_Draw2d::scene_of(['scene_json' => json_encode($sunset)]);
check_true('scene_of: из строки', count($scene_of['shapes']) >= 6);
$scene_of2 = Service_Nj668_Draw2d::scene_of(['scene_json' => $sunset]);
check_true('scene_of: из массива', count($scene_of2['shapes']) >= 6);

check_true('job_failed: failed', Service_Nj668_Draw2d::job_failed(['status' => 'failed', 'error' => 'x']));
check_true('job_failed: queued+error', Service_Nj668_Draw2d::job_failed([
	'status' => 'queued', 'error' => 'ValueError: ответ не JSON',
]));
check_true('job_failed: running жива', Service_Nj668_Draw2d::job_failed([
	'status' => 'running', 'error' => '',
]) === FALSE);
check_true('job_live: пустая не жива', Service_Nj668_Draw2d::job_live([]) === FALSE);
check_true('job_live: none не жива', Service_Nj668_Draw2d::job_live([
	'found' => FALSE, 'status' => 'none', 'job_id' => 0,
]) === FALSE);
check_true('job_live: running жива', Service_Nj668_Draw2d::job_live([
	'found' => TRUE, 'status' => 'running',
]) === TRUE);
check_true('job_live: done не жива', Service_Nj668_Draw2d::job_live([
	'found' => TRUE, 'status' => 'done',
]) === FALSE);
check_true('job_live: queued с ошибкой не жива', Service_Nj668_Draw2d::job_live([
	'status' => 'queued', 'error' => 'ValueError: ответ не JSON',
]) === FALSE);
check('live_job_id без задачи — 0', Service_Nj668_Draw2d::live_job_id(['id' => 1, 'job_id' => 0]), 0);
check_true('job_error_text не пуст', Service_Nj668_Draw2d::job_error_text(['error' => 'нет фигур']) === 'нет фигур');

$row = ['code' => 'ab34cd56ef78', 'title' => 'Закат'];
check('preview href', Service_Nj668_Draw2d::preview_href($row), '/draw2d/api/preview?code=ab34cd56ef78');
$img = Service_Nj668_Draw2d::preview_img($row, 'Закат', 'h-16');
check_true('preview img', strpos($img, 'src="/draw2d/api/preview?code=ab34cd56ef78"') !== FALSE);

check_true('owns: тот же user', Service_Nj668_Draw2d::owns(['user_id' => 7, 'visitor_uid' => ''], 7, ''));
check_true('owns: чужой', Service_Nj668_Draw2d::owns(['user_id' => 7, 'visitor_uid' => ''], 8, '') === FALSE);
check_true('is_public: published', Service_Nj668_Draw2d::is_public(['status' => 'published']));
check_true('is_public: draft нет', Service_Nj668_Draw2d::is_public(['status' => 'draft']) === FALSE);

$svc = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj668_draw2d/classes/Service/Nj668/Draw2d.php');
check_true('сервис: генерация без retry', strpos($svc, "'max_attempts' => 1") !== FALSE);
check_true('сервис: витрина', strpos($svc, 'function showcase') !== FALSE);
check_true('сервис: accept_scene', strpos($svc, 'function accept_scene') !== FALSE);
check_true('сервис: set_error снимает job', strpos($svc, 'job_id = 0') !== FALSE);
check_true('сервис: job_live', strpos($svc, 'function job_live') !== FALSE);
check_true('сервис: live_job_id', strpos($svc, 'function live_job_id') !== FALSE);
$api = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj668_draw2d/classes/Controller/Nj668/Api.php');
check_true('api: status сбрасывает мёртвый job_id', strpos($api, 'job_live') !== FALSE);
$proj = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj668_draw2d/views/nj668/project.php');
check_true('страница: jobId только живой', strpos($proj, 'live_job_id') !== FALSE);
check_true('сервис: ревизия', strpos($svc, 'function commit_revision') !== FALSE);
check_true('сервис: откат без новой ревизии', strpos($svc, 'function restore') !== FALSE);
check_true('сервис: moderate', strpos($svc, 'function moderate') !== FALSE);
check_true('сервис: фото на диск', strpos($svc, 'storage/nj668') !== FALSE);
check_true('сервис: photo_max_px', strpos($svc, 'function photo_max_px') !== FALSE);

// ------------------------------------------------------------------ страницы

$project = [
	'id' => 1, 'code' => 'ab34cd56ef78', 'title' => 'Закат над морем',
	'summary' => 'Лодка и солнце', 'tags' => 'закат, море', 'status' => 'draft',
	'prompt' => 'Закат над морем', 'shapes_count' => count($sunset['shapes']),
	'views' => 3, 'job_id' => 0, 'error' => '', 'featured' => 0, 'model' => 'stand',
	'scene_json' => $sunset, 'updated_at' => '2026-10-11 12:00:00',
];

$html = render('nj668/index', [
	'logged' => TRUE, 'can_start' => TRUE, 'showcase' => [$project + ['status' => 'published']],
	'csrf' => 'stand-csrf-token',
]);
contains('витрина: заголовок', $html, 'Плоские иллюстрации');
contains('витрина: создать', $html, '/draw2d/create');
check_true('витрина: без mx-auto', strpos($html, 'mx-auto') === FALSE);
check_true('витрина: без крошек', strpos($html, 'breadcrumb') === FALSE);
check_true('витрина: без confirm', strpos($html, 'confirm(') === FALSE);
check_true('витрина: герой не режет', strpos($html, 'max-h-72') === FALSE);
contains('витрина: картинка целиком', $html, '[&_svg]:max-h-[min(70vh,720px)]');

$html = render('nj668/create', [
	'logged' => TRUE, 'can_start' => TRUE, 'can_moderate' => TRUE, 'error' => '', 'rate_text' => '6/мин',
	'limits' => Service_Nj668_Draw2d::limits(), 'ideas' => Service_Nj668_Draw2d::idea_presets_pick(3),
	'csrf' => 'stand-csrf-token', 'post' => [],
]);
contains('создать: POST форма', $html, 'action="/draw2d/create"');
contains('создать: промпт без autocomplete', $html, 'autocomplete="off"');
contains('создать: пресеты', $html, 'data-nj668-preset');
contains('создать: обновить идеи', $html, 'id="nj668-ideas-refresh"');
contains('создать: фото', $html, 'name="photos[]"');
contains('создать: multipart', $html, 'enctype="multipart/form-data"');
check_true('создать: без confirm', strpos($html, 'confirm(') === FALSE);

$html = render('nj668/projects', [
	'logged' => TRUE, 'mine' => [$project], 'statuses' => Service_Nj668_Draw2d::statuses(),
	'csrf' => 'stand-csrf-token', 'rate_text' => '6/мин', 'limits' => Service_Nj668_Draw2d::limits(),
]);
contains('проекты: удаление иконкой', $html, 'data-nj668-del');
contains('проекты: удаление — общий data-n2j-arm', $html, 'data-n2j-arm="Удалить?"');
check_true('проекты: своей логики взвода нет', strpos($html, 'var armed') === FALSE);
check_true('проекты: без confirm', strpos($html, 'confirm(') === FALSE);
check_true('проекты: без alert', strpos($html, 'alert(') === FALSE);

$html = render('nj668/catalog', [
	'logged' => TRUE, 'items' => [$project + ['status' => 'published']], 'q' => '', 'csrf' => 'stand-csrf-token',
]);
contains('каталог: поиск', $html, 'name="q"');
contains('каталог: карточка', $html, '/draw2d/c/ab34cd56ef78');
check_true('каталог: без mx-auto', strpos($html, 'mx-auto') === FALSE);

$html = render('nj668/view', [
	'logged' => TRUE, 'own' => TRUE, 'project' => $project, 'scene' => $sunset,
	'svg' => Service_Nj668_Scene::svg($sunset, 480, 320),
]);
contains('просмотр: это просмотр', $html, 'Это просмотр');
contains('просмотр: сцена JSON', $html, 'kind');
contains('просмотр: править', $html, '/draw2d/p/ab34cd56ef78');

$html = render('nj668/project', [
	'logged' => TRUE, 'can_start' => TRUE, 'project' => $project, 'scene' => $sunset,
	'revisions' => [['id' => 9, 'no' => 1, 'label' => 'модель', 'author_kind' => 'ai',
		'created_at' => '2026-10-11 12:00:00']],
	'limits' => Service_Nj668_Draw2d::limits(), 'rate_text' => '6/мин', 'csrf' => 'stand-csrf-token',
]);
contains('холст: css-спиннер — общий n2j-spin', $html, 'nj668-spin n2j-spin');
check_true('холст: не fa-spin', strpos($html, 'fa-spin') === FALSE);
contains('холст: выбрано на холсте', $html, 'id="nj668-selected"');
contains('холст: сохранить иконкой', $html, 'id="nj668-save-icon-btn"');
contains('холст: fullscreen', $html, 'id="nj668-full-btn"');
contains('холст: колонка, не наезд', $html, 'flex-direction: column');
contains('холст: тулбар не переносит', $html, 'flex-wrap: nowrap');
check_true('холст: сцена не inset-0', strpos($html, 'id="nj668-stage" class="absolute inset-0') === FALSE);
check_true('холст: нет top-[42px]', strpos($html, 'top-[42px]') === FALSE);
contains('холст: статус на холсте', $html, 'id="nj668-working"');
contains('холст: ошибка на холсте', $html, 'id="nj668-fail"');
contains('холст: пресеты правки', $html, 'data-nj668-preset="Исправь ошибки"');
contains('холст: промпт без autocomplete', $html, 'autocomplete="off"');
check_true('холст: без confirm', strpos($html, 'confirm(') === FALSE);
check_true('холст: день — серый 800', strpos($html, 'text-gray-800') !== FALSE);
$save_pos = strpos($html, 'id="nj668-save-icon-btn"');
$redo_pos = strpos($html, 'id="nj668-redo-btn"');
$full_pos = strpos($html, 'id="nj668-full-btn"');
check_true('холст: сохранить сразу после повторить', $save_pos !== FALSE AND $redo_pos !== FALSE AND $save_pos > $redo_pos AND ($full_pos === FALSE OR $save_pos < $full_pos));
contains('холст: поллинг status', $html, '/draw2d/api/status');
contains('холст: failJobText', $html, 'function failJobText');
contains('холст: поллинг только пока задача жива', $html, 'function jobIsLive');
contains('холст: jobFailed стопит поллинг', $html, 'stopPoll');
contains('холст: 2-click удаление проекта (data-n2j-arm)', $html, 'id="nj668-delete-btn" data-n2j-arm="Удалить иллюстрацию?"');
contains('холст: 2-click удаление фигуры (data-n2j-arm)', $html, 'id="nj668-del-shape-btn" data-n2j-arm="Удалить фигуру?"');
check_true('холст: своего взвода нет (клик мимо и Esc снимает общий app.js)', strpos($html, 'armedDel') === FALSE AND strpos($html, 'armedShape') === FALSE);
check_true('холст: без target !==', strpos($html, 'target !==') === FALSE AND strpos($html, 'event.target !==') === FALSE);
contains('холст: ревизии', $html, 'name="restore"');
contains('холст: удаление ревизии', $html, 'name="forget_revision"');
contains('холст: высота узкий', $html, 'height: 360px');
contains('холст: высота широкий', $html, 'min(70vh, 720px)');

$html = render('nj668/ideas', [
	'presets' => [['id' => 1, 'body' => 'Закат над морем', 'theme' => 'море', 'created_at' => '2026-10-11']],
	'job' => [], 'waiting' => FALSE, 'job_note' => '', 'error' => '',
	'csrf' => 'stand-csrf-token', 'theme' => 'море',
]);
contains('идеи: тема пачки', $html, 'name="theme"');
contains('идеи: 30', $html, 'Сгенерировать 30 идей');
contains('идеи: 2-click (data-n2j-arm)', $html, 'data-n2j-arm="Удалить?"');
check_true('идеи: без confirm', strpos($html, 'confirm(') === FALSE);

$html = render('nj668/moderate', [
	'queue' => [$project + ['status' => 'published']],
	'current' => $project + ['status' => 'published'],
	'scene' => $sunset, 'svg' => Service_Nj668_Scene::svg($sunset),
	'status' => '', 'statuses' => Service_Nj668_Draw2d::statuses(),
	'error' => '', 'csrf' => 'stand-csrf-token',
]);
contains('модерация: одобрить', $html, 'value="approve"');
contains('модерация: отклонить', $html, 'value="reject"');
contains('модерация: образец', $html, 'value="feature"');
check_true('модерация: без confirm', strpos($html, 'confirm(') === FALSE);

$html = render('nj668/card', []);
contains('карточка: ссылка создать', $html, '/draw2d/create');

// ------------------------------------------------------------------ проводка

$init = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj668_draw2d/init.php');
check_true('маршрут витрины', strpos($init, "Route::set('nj668_page', 'draw2d')") !== FALSE);
check_true('маршрут create', strpos($init, 'draw2d/create') !== FALSE);
check_true('маршрут projects', strpos($init, 'draw2d/projects') !== FALSE);
check_true('маршрут catalog', strpos($init, 'draw2d/catalog') !== FALSE);
check_true('маршрут ideas', strpos($init, 'draw2d/ideas') !== FALSE);
check_true('маршрут moderate', strpos($init, 'draw2d/moderate') !== FALSE);
check_true('маршрут холста', strpos($init, 'draw2d/p/<code>') !== FALSE);
check_true('маршрут просмотра', strpos($init, 'draw2d/c/<code>') !== FALSE);
check_true('приёмная context', strpos($init, 'draw2d/w/context') !== FALSE);
check_true('приёмная scene', strpos($init, 'draw2d/w/scene') !== FALSE);
check_true('приёмная fail', strpos($init, 'draw2d/w/fail') !== FALSE);
check_true('приёмная ideas', strpos($init, 'draw2d/w/ideas') !== FALSE);

$boot = (string) file_get_contents(N2J_STAND_ROOT.'/application/bootstrap.php');
$pos667 = strpos($boot, "'nj667_graph3d'");
$pos668 = strpos($boot, "'nj668_draw2d'");
check_true('bootstrap: nj668 сразу после nj667', $pos667 !== FALSE AND $pos668 !== FALSE AND $pos668 > $pos667);

$acl = (string) file_get_contents(N2J_STAND_ROOT.'/application/config/acl.php');
check_true('acl: nj668.start', strpos($acl, "'nj668.start'") !== FALSE);
check_true('acl: nj668.moderate', strpos($acl, "'nj668.moderate'") !== FALSE);

$man = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj668_draw2d/config/nj_module.php');
check_true('манифест: slug draw2d', strpos($man, "'slug'      => 'draw2d'") !== FALSE);
check_true('манифест: Плоские иллюстрации', strpos($man, 'Плоские иллюстрации') !== FALSE);
check_true('манифест: канал nj668.generate', strpos($man, 'nj668.generate') !== FALSE);
check_true('манифест: канал nj668.ideas', strpos($man, 'nj668.ideas') !== FALSE);

$env = (string) file_get_contents(N2J_STAND_ROOT.'/.env.example');
check_true('сайт .env.example: NJ668_MODEL', strpos($env, 'NJ668_MODEL') !== FALSE);
check_true('сайт .env.example: NJ668_API_BASE', strpos($env, 'NJ668_API_BASE') !== FALSE);

$wenv = (string) file_get_contents(N2J_STAND_ROOT.'/workers/.env.example');
check_true('воркер .env.example: NJ668_MODEL', strpos($wenv, 'NJ668_MODEL=') !== FALSE);

$wh = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/handlers/nj668_draw2d.py');
check_true('воркер: PREFIX NJ668', strpos($wh, 'PREFIX = "NJ668"') !== FALSE);
check_true('воркер: API_PATH /draw2d/w', strpos($wh, 'API_PATH = "/draw2d/w"') !== FALSE);
check_true('воркер: /fail', strpos($wh, '"/fail"') !== FALSE);
check_true('воркер: ТЕКУЩАЯ сцена', strpos($wh, 'ТЕКУЩАЯ сцена') !== FALSE);
check_true('воркер: канал nj668.generate', strpos($wh, 'nj668.generate') !== FALSE);
check_true('воркер: канал ideas', strpos($wh, 'nj668.ideas') !== FALSE);
check_true('воркер: фото vision', strpos($wh, '_photos_of') !== FALSE);
check_true('воркер: по фото', strpos($wh, 'нет ни описания, ни фото') !== FALSE);

$reg = (string) file_get_contents(N2J_STAND_ROOT.'/workers/n2j_workers/handlers/__init__.py');
check_true('реестр: import nj668', strpos($reg, 'nj668_draw2d') !== FALSE);
check_true('реестр: канал generate', strpos($reg, '"nj668.generate"') !== FALSE);
check_true('реестр: канал ideas', strpos($reg, '"nj668.ideas"') !== FALSE);

$mig = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj668_draw2d/migrations_pg/pgMigration_Nj_668_001_Draw2d_CreateTables.php');
check_true('миграция: nj_668_projects', strpos($mig, 'nj_668_projects') !== FALSE);
check_true('миграция: scene_json jsonb', strpos($mig, 'scene_json') !== FALSE);

$mig2 = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj668_draw2d/migrations_pg/pgMigration_Nj_668_002_Draw2d_RevisionsIdeas.php');
check_true('миграция 002: ревизии', strpos($mig2, 'nj_668_revisions') !== FALSE);
check_true('миграция 002: идеи', strpos($mig2, 'nj_668_idea_presets') !== FALSE);
check_true('миграция 002: настройки', strpos($mig2, 'nj_668_settings') !== FALSE);

$g3d = (string) file_get_contents(N2J_STAND_ROOT.'/application/modules/nj667_graph3d/init.php');
check_true('nj667 не тронут: init на месте', strpos($g3d, 'graph3d') !== FALSE);

echo "\n";
echo $bad === 0 ? "стенд nj668: $ok ок\n" : "стенд nj668: $ok ок, $bad фейл\n";
exit($bad === 0 ? 0 : 1);
