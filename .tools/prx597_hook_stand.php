<?php
/**
 * Стенд вебхуков prx597 (/prx597/hook/telegram, /prx597/hook/max).
 *
 * Вебхук — единственный вход в привязку мессенджеров снаружи, поэтому здесь
 * проверяем ровно то, на чём держатся безопасность и спокойствие бота:
 *   - секрет принимается и из адреса (?secret=), и из заголовков провайдера;
 *   - чужой секрет — 403 (бот перестанет долбить), а не 200;
 *   - режим бота off — обновление молча принимается и НЕ привязывает;
 *   - тело не JSON — 200 и никаких фаталов;
 *   - любое исключение внутри — всё равно 200: иначе мессенджер будет
 *     присылать одно и то же обновление бесконечно.
 *
 *   PRX_ROOT=/path/to/prx.ru node phpwasm_stand.mjs .tools/prx597_hook_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', dirname(__DIR__).'/system/');
}

// ---------------------------------------------------------------- заглушки

#[\AllowDynamicProperties]
class Controller
{
	public $request;
	public $response;
	public $auto_render = TRUE;

	public function __construct($request = NULL, $response = NULL)
	{
		$this->request  = $request;
		$this->response = $response;
	}

	public function before() {}
}

class Request
{
	const POST = 'POST';
	const GET  = 'GET';

	public $http_method;
	public $action_name;
	public $get;
	public $post_data;
	public $headers_map;
	public $raw_body;

	public function __construct($method, $action, array $get = [], array $post = [], array $headers = [], $raw = '')
	{
		$this->http_method = $method;
		$this->action_name = $action;
		$this->get         = $get;
		$this->post_data   = $post;
		$this->headers_map = $headers;
		$this->raw_body    = $raw;
	}

	public function method() { return $this->http_method; }
	public function action($value = NULL)
	{
		if ($value === NULL) return $this->action_name;
		$this->action_name = $value;
		return $this;
	}
	public function query($key) { return array_key_exists($key, $this->get) ? $this->get[$key] : NULL; }
	public function post($key = NULL) { return $key === NULL ? $this->post_data : (array_key_exists($key, $this->post_data) ? $this->post_data[$key] : NULL); }
	public function headers($key) { return array_key_exists($key, $this->headers_map) ? $this->headers_map[$key] : NULL; }
	public function body() { return $this->raw_body; }
}

#[\AllowDynamicProperties]
class Response
{
	public $code = 200;
	public $body = '';
	public $headers_list = [];

	public function status($code = NULL) { $this->code = $code; return $this; }
	public function body($body = NULL) { $this->body = $body; return $this; }
	public function headers($name, $value) { $this->headers_list[$name] = $value; return $this; }
}

class Arr
{
	public static function get($array, $key, $default = NULL)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

class Service_Prx597_Notify
{
	public static function ensure_tables() {}
}

class Service_Prx597_Bind
{
	/** Текущий режим бота: webhook | poll | off. */
	public static $mode = 'webhook';

	/** Правильный секрет. */
	const SECRET = 's3cr3t';

	/** Что пришло в handle_update() — проверим, что разбор доезжает. */
	public static $handled = [];

	/** Бросить исключение внутри handle_update() — проверка «всё равно 200». */
	public static $explode = FALSE;

	public static function mode($channel) { return self::$mode; }

	public static function secret_ok($channel, $given)
	{
		return is_string($given) AND $given !== '' AND hash_equals(self::SECRET, $given);
	}

	public static function handle_update($channel, array $update)
	{
		if (self::$explode)
		{
			throw new RuntimeException('база недоступна');
		}

		self::$handled[] = [$channel, $update];

		// Разбор текста в настоящем сервисе сложнее; здесь ровно то, что
		// нужно, чтобы проверить три ветки ответа вебхука.
		$text = isset($update['message']['text']) ? (string) $update['message']['text'] : '';

		if ($text === '')
		{
			return ['note' => 'обновление без текста'];
		}

		if (strpos($text, '/start') === 0)
		{
			return ['ok' => TRUE, 'user_id' => 42];
		}

		return ['error' => 'Не понимаю команду'];
	}
}

class Kohana
{
	public static $log;
}

Kohana::$log = new class { public function add($level, $message, array $values = NULL) { return $this; } };

class Log
{
	const WARNING = 3;
	const ERROR   = 4;
}

// Настоящий контроллер вебхуков.
require dirname(__DIR__).'/application/modules/prx597_notifications/classes/Controller/Prx597/Hook.php';

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

function run($request)
{
	$controller = new Controller_Prx597_Hook($request, new Response);
	$controller->before();

	$method = 'action_'.$request->action();

	if (method_exists($controller, $method))
	{
		$controller->{$method}();
	}

	return $controller->response;
}

$start = json_encode(['message' => ['text' => '/start AB12CD', 'chat' => ['id' => 555]]]);

// --- секрет из адреса --------------------------------------------------------
Service_Prx597_Bind::$mode    = 'webhook';
Service_Prx597_Bind::$handled = [];
$r = run(new Request(Request::POST, 'telegram', ['secret' => 's3cr3t'], [], [], $start));
check('секрет в адресе: 200', $r->code, 200);
check('секрет в адресе: привязано', json_decode($r->body, TRUE)['bound'] ? 'true' : 'false', 'true');
check('секрет в адресе: обновление дошло до Bind', count(Service_Prx597_Bind::$handled), 1);

// --- секрет в заголовке Telegram --------------------------------------------
Service_Prx597_Bind::$handled = [];
$r = run(new Request(Request::POST, 'telegram', [], [], ['X-Telegram-Bot-Api-Secret-Token' => 's3cr3t'], $start));
check('заголовок Telegram: 200', $r->code, 200);
check('заголовок Telegram: привязано', json_decode($r->body, TRUE)['bound'] ? 'true' : 'false', 'true');

// --- секрет в заголовке MAX --------------------------------------------------
Service_Prx597_Bind::$handled = [];
$r = run(new Request(Request::POST, 'max', [], [], ['X-Max-Secret' => 's3cr3t'], $start));
check('заголовок MAX: 200', $r->code, 200);
check('заголовок MAX: канал max', json_decode($r->body, TRUE)['channel'], 'max');

// --- чужой секрет: 403, обработка не идёт -----------------------------------
Service_Prx597_Bind::$handled = [];
$r = run(new Request(Request::POST, 'telegram', ['secret' => 'wrong'], [], [], $start));
check('чужой секрет: 403', $r->code, 403);
check('чужой секрет: Bind не звали', count(Service_Prx597_Bind::$handled), 0);

// --- секрет не передан вообще ------------------------------------------------
$r = run(new Request(Request::POST, 'telegram', [], [], [], $start));
check('без секрета: 403', $r->code, 403);

// --- режим off ---------------------------------------------------------------
Service_Prx597_Bind::$mode    = 'off';
Service_Prx597_Bind::$handled = [];
$r = run(new Request(Request::POST, 'telegram', ['secret' => 's3cr3t'], [], [], $start));
check('режим off: 200', $r->code, 200);
check('режим off: приём выключен', json_decode($r->body, TRUE)['note'], 'приём обновлений выключен');
check('режим off: Bind не звали', count(Service_Prx597_Bind::$handled), 0);

// --- мусор вместо JSON -------------------------------------------------------
Service_Prx597_Bind::$mode    = 'webhook';
Service_Prx597_Bind::$handled = [];
$r = run(new Request(Request::POST, 'telegram', ['secret' => 's3cr3t'], [], [], 'это не json'));
check('не JSON: 200', $r->code, 200);
check('не JSON: без фатала', json_decode($r->body, TRUE)['note'], 'пустое или не JSON');
check('не JSON: Bind не звали', count(Service_Prx597_Bind::$handled), 0);

// --- непонятная команда: текст ошибки уходит боту ----------------------------
$r = run(new Request(Request::POST, 'telegram', ['secret' => 's3cr3t'], [], [],
	json_encode(['message' => ['text' => 'что?', 'chat' => ['id' => 555]]])));
check('чужая команда: 200', $r->code, 200);
check('чужая команда: привязки нет', json_decode($r->body, TRUE)['bound'] ? 'true' : 'false', 'false');
check('чужая команда: текст для человека', json_decode($r->body, TRUE)['reply'], 'Не понимаю команду');

// --- исключение внутри: всё равно 200, иначе бот долбит повторами ------------
Service_Prx597_Bind::$explode = TRUE;
$r = run(new Request(Request::POST, 'telegram', ['secret' => 's3cr3t'], [], [], $start));
check('исключение внутри: 200', $r->code, 200);
check('исключение внутри: без трейса в ответе', json_decode($r->body, TRUE)['error'], 'внутренняя ошибка');
Service_Prx597_Bind::$explode = FALSE;

// --- ответ всегда JSON -------------------------------------------------------
$r = run(new Request(Request::POST, 'telegram', ['secret' => 's3cr3t'], [], [], $start));
check('заголовок Content-Type выставлен', $r->headers_list['Content-Type'], 'application/json; charset=utf-8');

// --- итог -------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
