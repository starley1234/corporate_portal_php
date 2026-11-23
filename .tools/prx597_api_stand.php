<?php
/**
 * Стенд API уведомлений prx597 (колокольчик в шапке).
 *
 * 06.10.2026 в логе проды: EMERGENCY «Attempt to read property "id" on
 * null» в Controller/Prx597/Api.php:67 — before() честно отвечал 401 и ставил
 * $this->_halt, но Kohana выполняет действие ВСЁ РАВНО, и action_unread()
 * падал на get_user()->id у анонима/протухшей сессии.
 *
 * Проверяет на заглушках (Request/Response/Auth/Security/Notify):
 *   - аноним: /unread и /read отвечают 401 JSON и НЕ падают;
 *   - воркер с неверным токеном: /outbox отвечает 403 и не выполняется;
 *   - живой пользователь: /unread отдаёт список, /read отмечает прочитанным;
 *   - присутствие: /unread?visible=1 отмечает человека на сайте (свёрнутая
 *     вкладка и аноним — нет), это же защищает от уведомления «о своём»;
 *   - настройки: /prefs, /sub (обязательное событие — 403), /events,
 *     /bind, /unbind, /archive, /clear — и везде проверка CSRF.
 *
 *   PRX_ROOT=/path/to/prx.ru node phpwasm_stand.mjs .tools/prx597_api_stand.php
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
		$this->request = $request;
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

	public function __construct($method, $action, array $get = [], array $post = [], array $headers = [])
	{
		$this->http_method = $method;
		$this->action_name = $action;
		$this->get = $get;
		$this->post_data = $post;
		$this->headers_map = $headers;
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
	public function headers($key) { foreach ($this->headers_map as $name => $value) { if (strcasecmp($name, $key) === 0) return $value; } return NULL; }
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

#[\AllowDynamicProperties]
class Auth
{
	public static $user = NULL;
	private static $instance = NULL;

	public static function instance() { return self::$instance !== NULL ? self::$instance : self::$instance = new self; }
	public function logged_in($role = NULL) { return self::$user !== NULL; }
	public function get_user() { return self::$user; }
	public function logout($destroy = FALSE) { self::$user = NULL; }
}

class Security
{
	public static $ok = TRUE;
	public static function check($token) { return self::$ok; }
	public static function token() { return 'csrf'; }
}

class Service_Prx599_Account
{
	public static function session_valid($user_id) { return TRUE; }
}

class Arr
{
	/** Только то, что использует контроллер: $array[$key] ?? $default. */
	public static function get($array, $key, $default = NULL)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

class Service_Prx597_Notify
{
	public static $latest = [];
	public static $marked = [];
	public static $unread = 0;

	public static function ensure_tables() {}
	public static function latest($uid, $limit) { return self::$latest; }
	public static function mark_read($uid, $ids = NULL) { self::$marked[] = [$uid, $ids]; }
	public static function unread_count($uid) { return self::$unread; }
	public static $presence = [];
	public static function touch_presence($uid) { self::$presence[] = (int) $uid; return TRUE; }
	public static function sweep_jobs($n) { return 0; }
	public static function send_digests($a, $b) { return 0; }
	public static $prefs_saved = [];
	public static $archived = 0;
	public static $cleared = 0;

	public static function user_pref_fields(array $p) { return array_intersect_key($p, self::PREF_FIELDS); }
	public static function save_prefs($uid, array $p) { self::$prefs_saved[] = [$uid, $p]; return $p; }
	// Свежий пользователь: ничего не включено (владелец, 16.10.2026).
	public static function prefs($uid) { return ['in_app_enabled' => FALSE, 'digest_mode' => 'off']; }
	public static function archive($uid, array $ids = NULL) { return self::$archived; }
	public static function clear_read($uid) { return self::$cleared; }
	public static function emit($alias, array $payload = [], array $options = []) { return TRUE; }

	// Поля настроек — те же, что в настоящем сервисе: bool/int/string.
	const PREF_FIELDS = [
		'in_app_enabled'   => 'bool',
		'email_enabled'    => 'bool',
		'telegram_enabled' => 'bool',
		'max_enabled'      => 'bool',
		'digest_mode'      => 'string',
		'quiet_from'       => 'int',
		'quiet_to'         => 'int',
		'tz'               => 'string',
	];
}

class Service_Prx597_Base
{
	public static function user_channels() { return Service_Prx597_Registry::$channels; }
	public static function channel_driver($key) { return ''; }
}
class Service_Prx597_Registry extends Service_Prx597_Base
{
	public static $events = [];
	public static $subs   = [];
	public static $channels = [
		'in_app'   => ['key' => 'in_app',   'label' => 'На сайте', 'icon' => 'fas fa-bell',        'bind' => FALSE],
		'email'    => ['key' => 'email',    'label' => 'Почта',    'icon' => 'fas fa-envelope',    'bind' => FALSE],
		'telegram' => ['key' => 'telegram', 'label' => 'Telegram', 'icon' => 'fab fa-telegram',    'bind' => TRUE],
		'max'      => ['key' => 'max',      'label' => 'MAX',      'icon' => 'fas fa-comment',     'bind' => TRUE],
	];

	public static function event($alias) { return isset(self::$events[$alias]) ? self::$events[$alias] : NULL; }
	public static function events($only_enabled = FALSE) { return self::$events; }
	public static function channels($only_enabled = TRUE) { return self::$channels; }
	// Как в жизни (владелец, 16.10.2026): пока человек не отметил клетку — «нет».
	public static function sub_enabled($event, $channel, $prefs, $subs) { return $subs[$event['alias']][$channel] ?? FALSE; }
	public static function subs($user_id) { return self::$subs; }
	public static function save_sub($user_id, $alias, $channel, $enabled) { self::$subs[$alias][$channel] = (bool) $enabled; }
	public static function roles_of($user_id) { return ['login']; }
	public static function user_allowed(array $event, array $roles, $user_id = 1) { return empty($event['denied']); }
	public static function channel_label($channel) { return isset(self::$channels[$channel]) ? self::$channels[$channel]['label'] : $channel; }
	public static function channel_icon($channel) { return isset(self::$channels[$channel]) ? self::$channels[$channel]['icon'] : 'fas fa-bell'; }
	public static function binding_channels() { return ['telegram', 'max']; }
	public static function forget_settings() {}
	public static function setting($key, $default = NULL) { return $default; }
	public static function set_setting($key, $value) {}
}

class Service_Prx597_Bind
{
	public static $started = [];
	public static $unbound = [];

	public static function start($user_id, $channel)
	{
		self::$started[] = [$user_id, $channel];

		if ($channel === 'max')
		{
			return ['error' => 'Канал MAX отключён'];
		}

		return ['code' => 'AB12CD', 'channel' => $channel, 'url' => 'https://t.me/bot?start=AB12CD',
		        'expires_at' => date('Y-m-d H:i:s', time() + 900)];
	}

	public static function unbind($user_id, $channel) { self::$unbound[] = [$user_id, $channel]; }
}

class Service_Prx597_Engine
{
	public static function stats() { return []; }
}

class Kohana
{
	public static $log;
	public static $config;
}

Kohana::$log = new class { public function add($level, $message, array $values = NULL) { return $this; } };

class Log
{
	const WARNING = 3;
	const ERROR   = 4;
}

// Настоящий контроллер API уведомлений.
require dirname(__DIR__).'/application/classes/Prx/Gate.php';
require dirname(__DIR__).'/application/modules/prx597_notifications/classes/Controller/Prx597/Api.php';

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

/** Исполнить запрос как Kohana: before() → действие (даже если before «отказал»). */
function call_hidden($class, $method, array $args)
{
    $r = new ReflectionMethod($class, $method);
    $r->setAccessible(TRUE);
    return $r->invokeArgs(NULL, $args);
}

function run($request)
{
	$controller = new Controller_Prx597_Api($request, new Response);
	$controller->before();

	$method = 'action_'.$request->action();

	if (method_exists($controller, $method))
	{
		$controller->{$method}();
	}

	return $controller->response;
}

// --- аноним: раньше здесь был EMERGENCY «read property "id" on null» --------
Auth::$user = NULL;
$r = run(new Request(Request::GET, 'unread'));
check('аноним /unread: код 401', $r->code, 401);
check('аноним /unread: JSON без падения', json_decode($r->body, TRUE)['error'], 'Только для зарегистрированных');

$r = run(new Request(Request::POST, 'read', [], ['csrf' => 'x', 'id' => '[1]']));
check('аноним /read: код 401', $r->code, 401);
check('аноним /read: JSON без падения', json_decode($r->body, TRUE)['error'], 'Только для зарегистрированных');

// --- воркер с неверным токеном: outbox не выполняется ------------------------
$r = run(new Request(Request::POST, 'outbox', [], [], ['X-Worker-Token' => 'wrong']));
check('чужой токень /outbox: код 403', $r->code, 403);
check('чужой токень /outbox: JSON', json_decode($r->body, TRUE)['error'], 'Токен воркера не совпал');

// --- живой пользователь ------------------------------------------------------
$user = new stdClass;
$user->id = 42;
Auth::$user = $user;
Service_Prx597_Notify::$latest = [
	['id' => 1, 'kind' => 'job', 'title' => 'Готово', 'body' => 'Пакет собран', 'href' => '/docs/x1', 'read_at' => NULL, 'created_at' => date('Y-m-d H:i:s', time() - 120)],
];
$r = run(new Request(Request::GET, 'unread'));
check('юзер /unread: код 200', $r->code, 200);
$payload = json_decode($r->body, TRUE);
check('юзер /unread: один пункт', count($payload['items']), 1);
check('юзер /unread: поле id', $payload['items'][0]['id'], 1);
check('юзер /unread: unread=true', $payload['items'][0]['unread'] ? 'true' : 'false', 'true');
check('юзер /unread: ago человекочитаемый', $payload['items'][0]['ago'], '2 мин назад');

// --- присутствие: вкладка на виду отмечается, свёрнутая молчит ---------------
Service_Prx597_Notify::$presence = [];
$r = run(new Request(Request::GET, 'unread', ['visible' => '1']));
check('присутствие: видимая вкладка отметилась', Service_Prx597_Notify::$presence, [42]);

Service_Prx597_Notify::$presence = [];
$r = run(new Request(Request::GET, 'unread', ['visible' => '0']));
check('присутствие: свёрнутая вкладка не отмечается', Service_Prx597_Notify::$presence, []);

Service_Prx597_Notify::$presence = [];
$r = run(new Request(Request::GET, 'unread'));
check('присутствие: без метки вкладка не считается видимой', Service_Prx597_Notify::$presence, []);

// Аноним с visible=1 — по-прежнему 401 и ничего не отмечено.
Auth::$user = NULL;
Service_Prx597_Notify::$presence = [];
$r = run(new Request(Request::GET, 'unread', ['visible' => '1']));
check('присутствие: аноним ничего не отмечает', Service_Prx597_Notify::$presence, []);
Auth::$user = $user;

// --- отметка прочитанным -----------------------------------------------------
Service_Prx597_Notify::$marked = [];
Security::$ok = TRUE;
$r = run(new Request(Request::POST, 'read', [], ['csrf' => 'ok', 'id' => '[1,2]']));
check('юзер /read: код 200', $r->code, 200);
check('юзер /read: отмечены id юзера 42', json_encode(Service_Prx597_Notify::$marked), '[[42,[1,2]]]');

Security::$ok = FALSE;
$r = run(new Request(Request::POST, 'read', [], ['csrf' => 'bad']));
check('юзер /read: неверный csrf — 403', $r->code, 403);

// --- настройки: /prefs -------------------------------------------------------
Service_Prx597_Notify::$prefs_saved = [];
Security::$ok = TRUE;
$r = run(new Request(Request::POST, 'prefs', [], ['csrf' => 'ok', 'in_app_enabled' => '1', 'digest_mode' => 'daily']));
check('/prefs: код 200', $r->code, 200);
check('/prefs: настройки ушли в сервис', count(Service_Prx597_Notify::$prefs_saved), 1);
check('/prefs: digest_mode доехал', Service_Prx597_Notify::$prefs_saved[0][1]['digest_mode'], 'daily');

Security::$ok = FALSE;
$r = run(new Request(Request::POST, 'prefs', [], ['csrf' => 'bad']));
check('/prefs: без CSRF — 403', $r->code, 403);
Security::$ok = TRUE;

// --- матрица: /sub -----------------------------------------------------------
Service_Prx597_Registry::$events = [
	'prx120.job.done' => ['alias' => 'prx120.job.done', 'title' => 'Задача выполнена', 'mandatory' => FALSE,
	                     'channels' => ['in_app', 'email'], 'category' => 'jobs', 'icon' => 'fas fa-check', 'description' => ''],
	'security.login' => ['alias' => 'security.login', 'title' => 'Вход в аккаунт', 'mandatory' => TRUE,
	                     'channels' => ['in_app', 'email'], 'category' => 'security', 'icon' => 'fas fa-key', 'description' => ''],
];
Service_Prx597_Registry::$subs = [];

$r = run(new Request(Request::POST, 'sub', [], ['csrf' => 'ok', 'event' => 'prx120.job.done', 'channel' => 'email', 'enabled' => '0']));
check('/sub: код 200', $r->code, 200);
check('/sub: подписка выключена', Service_Prx597_Registry::$subs['prx120.job.done']['email'] ? 'true' : 'false', 'false');

$r = run(new Request(Request::POST, 'sub', [], ['csrf' => 'ok', 'event' => 'security.login', 'channel' => 'email', 'enabled' => '0']));
check('/sub: обязательное событие — 403', $r->code, 403);
check('/sub: обязательное событие не записано', isset(Service_Prx597_Registry::$subs['security.login']) ? 'true' : 'false', 'false');

$r = run(new Request(Request::POST, 'sub', [], ['csrf' => 'ok', 'event' => 'нет.такого', 'channel' => 'email', 'enabled' => '1']));
check('/sub: неизвестное событие — 404', $r->code, 404);

// --- /events: матрица для настроек ------------------------------------------
$r = run(new Request(Request::GET, 'events'));
check('/events: код 200', $r->code, 200);
$matrix = json_decode($r->body, TRUE);
check('/events: два события', count($matrix['events']), 2);
check('/events: каналы отданы', count($matrix['channels']), 4);
$gates = ['in_app_enabled' => 1, 'email_enabled' => 1, 'telegram_enabled' => 1, 'max_enabled' => 1];
check('/events: наружу клетки выключены по умолчанию', array_filter(array_diff_key($matrix['events'][0]['enabled'], ['in_app' => 1])), []);
check('/events: наружу галочки каналов выключены', array_filter(array_diff_key(array_intersect_key($matrix['prefs'], $gates), ['in_app_enabled' => 1])), []);

// Настоящий ответ API (без заглушки настроек): наружу — «нет», колокольчик — «да».
$public = call_hidden('Controller_Prx597_Api', '_public_prefs', [[]]);
check('/prefs: без строки настроек наружу выключено', array_filter(array_diff_key(array_intersect_key($public, $gates), ['in_app_enabled' => 1])), []);
check('/prefs: колокольчик по умолчанию включён', $public['in_app_enabled'], TRUE);

// Скрытый канал не возвращается нигде и не принимает подписки.
unset(Service_Prx597_Registry::$channels['telegram'], Service_Prx597_Registry::$channels['max']);
Service_Prx597_Registry::$events['prx120.job.done']['channels'][] = 'telegram';
$r = run(new Request(Request::GET, 'events'));
$matrix = json_decode($r->body, TRUE);
check('/events: только доступные каналы', count($matrix['channels']), 2);
check('/events: скрытые привязки отсутствуют', isset($matrix['binding']['telegram']), FALSE);
check('/events: скрытые настройки отсутствуют', isset($matrix['prefs']['telegram_enabled']), FALSE);
check('/events: каналы события отфильтрованы', json_encode($matrix['events'][0]['channels']), '["in_app","email"]');
check('/events: скрытые клетки отсутствуют', isset($matrix['events'][0]['enabled']['telegram']), FALSE);
foreach (['telegram', 'sms'] as $channel)
{
	$r = run(new Request(Request::POST, 'sub', [], ['csrf' => 'ok', 'event' => 'prx120.job.done', 'channel' => $channel, 'enabled' => '1']));
	check('/sub: недоступный канал '.$channel, $r->code, 403);
}
Service_Prx597_Registry::$events['prx120.job.done']['denied'] = TRUE;
$r = run(new Request(Request::POST, 'sub', [], ['csrf' => 'ok', 'event' => 'prx120.job.done', 'channel' => 'email', 'enabled' => '1']));
check('/sub: чужое событие запрещено', $r->code, 403);
Service_Prx597_Registry::$events['prx120.job.done']['denied'] = FALSE;

// --- привязка мессенджеров: /bind и /unbind ---------------------------------
Service_Prx597_Bind::$started = [];
$r = run(new Request(Request::POST, 'bind', [], ['csrf' => 'ok', 'channel' => 'telegram']));
check('/bind: код 200', $r->code, 200);
check('/bind: код выдан', json_decode($r->body, TRUE)['code'], 'AB12CD');

$r = run(new Request(Request::POST, 'bind', [], ['csrf' => 'ok', 'channel' => 'max']));
check('/bind: выключенный канал — 400', $r->code, 400);

$r = run(new Request(Request::POST, 'bind', [], ['csrf' => 'ok', 'channel' => 'email']));
check('/bind: канал без привязки — 400', $r->code, 400);

Service_Prx597_Bind::$unbound = [];
$r = run(new Request(Request::POST, 'unbind', [], ['csrf' => 'ok', 'channel' => 'telegram']));
check('/unbind: код 200', $r->code, 200);
check('/unbind: отвязка дошла до сервиса', json_encode(Service_Prx597_Bind::$unbound), '[[42,"telegram"]]');

// --- архив и очистка --------------------------------------------------------
Service_Prx597_Notify::$archived = 3;
Service_Prx597_Notify::$cleared  = 7;
$r = run(new Request(Request::POST, 'archive', [], ['csrf' => 'ok', 'id' => '[1,2]']));
check('/archive: код 200', $r->code, 200);
check('/archive: сколько убрано', json_decode($r->body, TRUE)['archived'], 3);

$r = run(new Request(Request::POST, 'clear', [], ['csrf' => 'ok']));
check('/clear: код 200', $r->code, 200);
check('/clear: сколько удалено', json_decode($r->body, TRUE)['deleted'], 7);

// --- аноним не проходит ни в одно новое действие ----------------------------
Auth::$user = NULL;

foreach (['prefs', 'sub', 'bind', 'unbind', 'events', 'archive', 'clear'] as $action)
{
	$r = run(new Request(Request::POST, $action, [], ['csrf' => 'ok']));
	check('аноним /'.$action.': 401, не падает', $r->code, 401);
}

// --- итог -------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
