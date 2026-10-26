<?php
/**
 * Стенд «регистрация-гейта» VLM-троицы nj650/nj651/nj652 (PHP-in-WASM).
 * Решение владельца 06.10.2026: создание фото-разборов — «как опросы»,
 * только для зарегистрированных; потребление (страницы, норма) — публично.
 *
 * Проверяет тремя способами:
 *   1. Поведение API: настоящий Controller_Vision_Base + настоящие Api
 *      модулей. Гость → 401 {ok:false, login}; залогиненный проходит гейт
 *      дальше по конвейеру (в стенде VLM выключен — ответ «недоступно»).
 *      Контроль: модуль без ключа 'auth' гейта не включает.
 *   2. SQL Vision::runs_mine(): история ищется по куке visitor ИЛИ user_id
 *      (аккаунт видит свои разборы с любого устройства).
 *   3. Дымовый рендер вьюх 650/651/652 (настоящий Kohana_View): гость видит
 *      плашку входа, залогиненный — блок съёмки; без PHP-варнингов.
 *
 *   N2J_ROOT=/home/user/n2j.ru node phpwasm_stand.mjs .tools/nj650_vision_gate_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

$ok  = 0;
$bad = 0;

function check($label, $cond)
{
	global $ok, $bad;

	if ($cond)
	{
		$ok++;
		echo "ok   $label\n";
	}
	else
	{
		$bad++;
		echo "ФЕЙЛ $label\n";
	}
}

function contains($label, $haystack, $needle)
{
	check($label, is_string($haystack) AND strpos($haystack, $needle) !== FALSE);
}

function not_contains($label, $haystack, $needle)
{
	check($label, is_string($haystack) AND strpos($haystack, $needle) === FALSE);
}

// ---------------------------------------------------------- стабы окружения

class Kohana_Config_Group extends ArrayObject
{
	public function as_array() { return (array) $this; }
	public function get($key, $default = NULL) { $a = (array) $this; return array_key_exists($key, $a) ? $a[$key] : $default; }
}

class Config
{
	public function load($name)
	{
		// Провайдера в стенде нет — Vision::ready() детерминированно FALSE.
		if ($name === 'llm' OR strpos($name, 'llm.') === 0)
		{
			return [];
		}

		$parts = explode('.', (string) $name);
		$file  = array_shift($parts);
		$data  = NULL;

		foreach (['nj650_receipt', 'nj651_meal', 'nj652_plant'] as $m)
		{
			$path = '/app/application/modules/'.$m.'/config/'.$file.'.php';

			if (is_file($path))
			{
				$data = require $path;
				break;
			}
		}

		if ($data === NULL)
		{
			$path = '/app/application/config/'.$file.'.php';
			$data = is_file($path) ? require $path : [];
		}

		foreach ($parts as $key)
		{
			$data = is_array($data) ? ($data[$key] ?? NULL) : NULL;
		}

		return $data;
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

		$map   = ['650' => 'nj650_receipt', '651' => 'nj651_meal', '652' => 'nj652_plant'];
		$first = explode('/', $file)[0];
		$base  = (trim($dir, '/') === 'views' AND isset($map[$first]))
			? '/app/application/modules/'.$map[$first].'/views/'
			: '/app/application/'.trim($dir, '/').'/';

		$path = $base.$file.'.'.$ext;

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

class Controller
{
	public $auto_render = FALSE;
	public $request;
	public $response;

	public function __construct($request = NULL, $response = NULL)
	{
		$this->request  = $request;
		$this->response = $response;
	}

	public function before() { }
}

class Request
{
	const POST = 'POST';

	public function method($v = NULL) { return self::POST; }
	public function body($v = NULL) { return ''; }
	public function post($key = NULL, $default = NULL) { return $key === NULL ? [] : $default; }
	public function query($key = NULL, $default = NULL) { return $key === NULL ? [] : $default; }
}

class Response
{
	public $status_code = 200;
	public $body_str = '';
	public $headers_list = [];

	public function status($code = NULL)
	{
		if ($code !== NULL) { $this->status_code = (int) $code; }
		return $this->status_code;
	}

	public function headers($key, $value, $replace = TRUE)
	{
		$this->headers_list[$key] = $value;
		return $this;
	}

	public function body($value = NULL)
	{
		if ($value !== NULL) { $this->body_str = (string) $value; }
		return $this->body_str;
	}
}

class Auth
{
	public static $on = FALSE;

	public static function instance() { return new self; }
	public function logged_in() { return self::$on; }
}

class FakeResult
{
	public function as_array() { return []; }
	public function current() { return FALSE; }
}

class FakeDb
{
	public function quote($v) { return "'".str_replace("'", "''", (string) $v)."'"; }
	public function query($type, $sql, $object = NULL) { Database::$sqls[] = $sql; return new FakeResult; }
}

class Database
{
	const SELECT = 1;

	/** @var string[] SQL всех запросов (для проверок runs_mine) */
	public static $sqls = [];

	public static function instance() { return new FakeDb; }
}

// --------------------------------------------- настоящие классы приложения

require '/app/application/classes/Vision.php';
require '/app/application/classes/Controller/Vision/Base.php';

require '/app/application/modules/nj650_receipt/classes/Controller/Nj650/Api.php';
require '/app/application/modules/nj651_meal/classes/Controller/Nj651/Api.php';
require '/app/application/modules/nj652_plant/classes/Controller/Nj652/Api.php';

/** Контроль: модуль БЕЗ ключа 'auth' гейта не включает (nj646-подобные). */
class Controller_Vision_Free_Api extends Controller_Vision_Base
{
	protected function _vision()
	{
		return [
			'module'  => 'test_free',
			'table'   => 'nj_650_runs',
			'channel' => 'test.free',
			'title'   => 'Контроль',
		];
	}
}

function photo_response($class, $logged_in)
{
	Auth::$on = $logged_in;

	$resp = new Response;
	$c    = new $class(new Request, $resp);
	$c->before();
	$c->action_photo();

	return $resp;
}

echo "1. Гейт создания в API (настоящий Controller_Vision_Base)\n";

$apis = [
	'650' => 'Controller_Nj650_Api',
	'651' => 'Controller_Nj651_Api',
	'652' => 'Controller_Nj652_Api',
];

foreach ($apis as $num => $class)
{
	$r    = photo_response($class, FALSE);
	$json = json_decode($r->body_str, TRUE);

	check($num.': гостю 401', $r->status_code === 401);
	check($num.': ok=false и адрес входа', $json['ok'] === FALSE AND ($json['login'] ?? '') === '/auth/login');
	contains($num.': сообщение про вход', $r->body_str, 'после входа');
	not_contains($num.': гость не дошёл до проверки кадра', $r->body_str, 'Прикрепите фото');

	$r = photo_response($class, TRUE);

	check($num.': залогиненный прошёл гейт (не 401)', $r->status_code !== 401);
	contains($num.': дальше по конвейеру (VLM в стенде выключен)', $r->body_str, 'недоступно');
}

$r = photo_response('Controller_Vision_Free_Api', FALSE);
check('контроль: без ключа auth гейта нет (гость не 401)', $r->status_code !== 401);

echo "\n2. Vision::runs_mine — история по куке ИЛИ по аккаунту\n";

Database::$sqls = [];
Vision::runs_mine('nj_650_runs', 'g:abc', 6, 42);
$sql = end(Database::$sqls);

contains('runs_mine: посетитель по куке', $sql, "visitor = 'g:abc'");
contains('runs_mine: история по аккаунту', $sql, 'user_id = 42');
contains('runs_mine: условия соединены OR', $sql, ' OR ');

Database::$sqls = [];
$rows = Vision::runs_mine('nj_651_runs', '', 6, NULL);
check('runs_mine: без куки и аккаунта — пусто и без запроса', $rows === [] AND Database::$sqls === []);

Database::$sqls = [];
Vision::runs_mine('nj_652_runs', '', 6, 7);
$sql = end(Database::$sqls);

contains('runs_mine: только user_id (нет пустой куки)', $sql, 'user_id = 7');
not_contains('runs_mine: без visitor-условия', $sql, 'visitor =');

Database::$sqls = [];
Vision::runs_mine('nj_651_runs', 'g:z', 6, NULL);
$sql = end(Database::$sqls);

contains('runs_mine: только visitor', $sql, "visitor = 'g:z'");
not_contains('runs_mine: без user_id-условия', $sql, 'user_id =');

$account_run = ['user_id' => 42, 'visitor' => 'browser-a'];
$guest_run   = ['user_id' => 0, 'visitor' => 'browser-a'];
check('run_owned: аккаунт видит свой прогон с другого устройства',
	Vision::run_owned($account_run, 'browser-b', 42));
check('run_owned: совпавшей куки недостаточно для чужого аккаунта',
	! Vision::run_owned($account_run, 'browser-a', 13));
check('run_owned: гость не открывает прогон аккаунта по той же куке',
	! Vision::run_owned($account_run, 'browser-a', NULL));
check('run_owned: анонимный прогон доступен только той же куке',
	Vision::run_owned($guest_run, 'browser-a', NULL)
	AND ! Vision::run_owned($guest_run, 'browser-b', NULL));

echo "\n3. Вьюхи: гость видит плашку, залогиненный — съёмку\n";

class View_Exception extends Exception
{
	public function __construct($message = '', array $variables = NULL, $code = 0)
	{
		parent::__construct(strtr($message, $variables ?: []), (int) $code);
	}
}

require '/app/system/classes/Kohana/View.php';

class View extends Kohana_View {}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

// Константы ACTIVITY/GOALS нужны вьюхе 651 (селекты профиля).
require '/app/application/modules/nj651_meal/classes/Service/Nj651/Meal.php';

function render($file, array $data)
{
	try
	{
		return View::factory($file)->set($data)->render();
	}
	catch (Throwable $e)
	{
		echo 'ФЕЙЛ рендер '.$file.': '.$e->getMessage().' @ line '.$e->getLine()."\n";

		return '';
	}
}

function no_warnings($label, $html)
{
	check($label, strpos($html, '<b>Warning</b>') === FALSE AND strpos($html, 'Undefined') === FALSE);
}

$views = [
	'650' => ['650/index', ['ready' => TRUE, 'limits' => ['photo_mb' => 8, 'per_hour' => 12, 'people' => 20], 'mine' => []]],
	'651' => ['651/index', ['ready' => TRUE, 'limits' => ['photo_mb' => 8, 'per_hour' => 12],
		'profile' => ['sex' => 'm', 'weight' => 80, 'height' => 180, 'age' => 35, 'activity' => 'medium', 'goal' => 'keep'],
		'norm' => ['kcal' => 2500, 'bmr' => 1700, 'protein' => 120, 'fat' => 80, 'carbs' => 300],
		'flash' => '', 'csrf' => 'csrf-token', 'profile_query' => 'sex=m&weight=80', 'mine' => []]],
	'652' => ['652/index', ['ready' => TRUE, 'limits' => ['photo_mb' => 8, 'per_hour' => 12, 'weeks' => 4], 'mine' => []]],
];

foreach ($views as $num => $spec)
{
	list($file, $data) = $spec;

	$guest = render($file, $data + ['guest' => TRUE]);
	contains($num.': плашка входа гостю', $guest, 'Войти или зарегистрироваться');
	contains($num.': заголовок плашки', $guest, 'после быстрого входа');
	not_contains($num.': гостю не рендерится блок съёмки', $guest, 'Снять или приложить фото');
	no_warnings($num.': гость — без PHP-варнингов', $guest);

	$user = render($file, $data + ['guest' => FALSE]);
	contains($num.': залогиненному — блок съёмки', $user, 'Снять или приложить фото');
	not_contains($num.': залогиненному — без плашки', $user, 'после быстрого входа');
	no_warnings($num.': залогиненный — без PHP-варнингов', $user);
}

// Публичное потребление никуда не делось: норма на /meal — без входа.
$guest651 = render('651/index', $views['651'][1] + ['guest' => TRUE]);
contains('651: суточная норма видна гостю', $guest651, 'Ваша суточная норма');

$meal_history = render('651/index', [
	'ready' => TRUE,
	'limits' => ['photo_mb' => 8, 'per_hour' => 12],
	'profile' => ['sex' => 'm', 'weight' => 80, 'height' => 180, 'age' => 35,
		'activity' => 'medium', 'goal' => 'keep'],
	'norm' => ['kcal' => 2500, 'bmr' => 1700, 'protein' => 120, 'fat' => 80, 'carbs' => 300],
	'mine' => [
		['id' => 91, 'status' => 'done', 'title' => 'Рис & рыба',
			'created_at' => '2026-10-05 10:00:00', 'preview_url' => '/meal/api/preview?id=91',
			'error' => '', 'meal' => ['total' => ['kcal' => 620, 'grams' => 340,
				'protein' => 30, 'fat' => 20, 'carbs' => 75],
				'dishes' => [['name' => 'Рис с лососем']]]],
		['id' => 92, 'status' => 'queued', 'title' => 'В очереди',
			'created_at' => '2026-10-05 10:01:00', 'preview_url' => '', 'error' => '', 'meal' => []],
	],
	'guest' => FALSE, 'flash' => '', 'csrf' => 'csrf-token',
	'profile_query' => 'sex=m&weight=80',
]);
contains('651: история использует приватное API превью', $meal_history, '/meal/api/preview?id=91');

$meal_api_source = (string) file_get_contents('/app/application/modules/nj651_meal/classes/Controller/Nj651/Api.php');
$meal_service_source = (string) file_get_contents('/app/application/modules/nj651_meal/classes/Service/Nj651/Meal.php');
$owner_check_pos = strpos($meal_api_source, 'Vision::run_owned(');
$preview_read_pos = strpos($meal_api_source, 'Service_Nj651_Meal::preview_bytes($id)');
check('651: приватный preview проверяет владельца до чтения файла',
	$owner_check_pos !== FALSE AND $preview_read_pos !== FALSE AND $owner_check_pos < $preview_read_pos);
check('651: приватное preview не кэшируется и защищено от MIME-sniffing',
	strpos($meal_api_source, "headers('Cache-Control', 'private, no-store, max-age=0')") !== FALSE
	AND strpos($meal_api_source, "headers('X-Content-Type-Options', 'nosniff')") !== FALSE);
check('651: каталог/файл preview переводятся в owner-only режимы',
	strpos($meal_service_source, '@chmod($dir, 0700)') !== FALSE
	AND strpos($meal_service_source, '@chmod($path, 0600)') !== FALSE);

contains('651: история показывает КБЖУ и массу порции', $meal_history, '620 ккал');
contains('651: история выводит макросы белков/жиров/углеводов', $meal_history, 'Б 30,0 г');
contains('651: готовый разбор можно удалить', $meal_history, 'action="/meal/91/delete"');
not_contains('651: очередь нельзя удалить до завершения', $meal_history, 'action="/meal/92/delete"');
contains('651: запрос на удаление защищён csrf', $meal_history, 'csrf-token');
no_warnings('651: плитки истории — без PHP-варнингов', $meal_history);

echo "\n".($bad === 0 ? "ГЕЙТ NJ-650/651/652: OK ($ok)\n" : "ГЕЙТ NJ-650/651/652: ПРОВАЛ ($bad из ".($ok + $bad).")\n");
exit($bad === 0 ? 0 : 1);
