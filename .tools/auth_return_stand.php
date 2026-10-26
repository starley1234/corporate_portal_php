<?php
/**
 * Стенд возврата после входа (Controller_Auth, 06.10.2026).
 *
 * Пользователь видит замочек «Сервис для зарегистрированных — войдите…»,
 * входит — и должен вернуться на ту же страницу.
 *
 * Проверяет:
 *   - Controller_Auth::return_redirect(): нормализация ?return_url=
 *     (локальные пути принимаются, схемы и '//host' — нет);
 *   - полный action_login() на заглушках Request/Session/Auth:
 *     return_url побеждает Referer, Referer — запасной путь, злой
 *     return_url игнорируется, цель доживает до повторного POST с
 *     неверным паролем, 2FA несёт цель в nj599_pending;
 *   - вьюхи: в местах замочка стоят ссылки /auth/login?return_url=…,
 *     мёртвых href="/login" (404) в вёрстке нет.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/auth_return_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

// ---------------------------------------------------------------- заглушки

#[\AllowDynamicProperties]
class View
{
	public function __construct($file = '') {}
	public static function factory($file = '', array $data = NULL) { return new self($file); }
	public function bind($key, & $value) { return $this; }
	public function set($key, $value) { return $this; }
	public function render() { return ''; }
}

class Controller
{
	public $request;
	public $response;
	public $template;

	public function __construct($request = NULL, $response = NULL)
	{
		$this->request = $request;
		$this->response = $response;
		$this->template = new View;
	}
}

class Controller_Erp100_Base extends Controller {}
class Controller_Erp100_Auth extends Controller_Erp100_Base {}

class Request
{
	const POST = 'POST';
	const GET  = 'GET';

	public $http_method;
	public $get;
	public $post_data;
	public $ref;

	public function __construct($method, array $get = [], array $post = [], $referrer = '')
	{
		$this->http_method = $method;
		$this->get = $get;
		$this->post_data = $post;
		$this->ref = $referrer;
	}

	public function method() { return $this->http_method; }
	public function query($key) { return array_key_exists($key, $this->get) ? $this->get[$key] : NULL; }
	public function post($key = NULL) { return $key === NULL ? $this->post_data : (array_key_exists($key, $this->post_data) ? $this->post_data[$key] : NULL); }
	public function referrer() { return $this->ref; }
}

class Session
{
	public static $data = [];
	private static $instance = NULL;

	public static function instance()
	{
		return self::$instance !== NULL ? self::$instance : self::$instance = new self;
	}

	public function set($key, $value) { self::$data[$key] = $value; }
	public function get($key, $default = NULL) { return array_key_exists($key, self::$data) ? self::$data[$key] : $default; }
	public function get_once($key, $default = NULL)
	{
		$value = $this->get($key, $default);
		$this->delete($key);
		return $value;
	}
	public function delete($key) { unset(self::$data[$key]); }
}

#[\AllowDynamicProperties]
class Auth
{
	public static $logged_in = FALSE;
	public static $login_ok = FALSE;
	public static $user = NULL;
	private static $instance = NULL;

	public static function instance()
	{
		return self::$instance !== NULL ? self::$instance : self::$instance = new self;
	}

	public function logged_in($role = NULL) { return self::$logged_in; }
	public function login($username, $password, $remember) { return self::$login_ok; }
	public function get_user() { return self::$user; }
	public function logout() { self::$logged_in = FALSE; }
	public function force_login($user) { self::$logged_in = TRUE; self::$user = $user; }
}

class HTTP_Exception extends Exception
{
	public $redirect;

	public static function factory($code, $message = NULL, array $variables = NULL, Exception $previous = NULL)
	{
		return new self((string) $message, (int) $code, $previous);
	}

	public function location($url = NULL)
	{
		if ($url === NULL)
		{
			return $this->redirect;
		}

		$this->redirect = $url;
		return $this;
	}
}

class Route
{
	public static function url($name, array $params = []) { return '/'; }
}

class Log
{
	const WARNING = 3;
	const ERROR   = 4;
}

class Kohana
{
	public static $log;
}

Kohana::$log = new class { public function add($level, $message, array $values = NULL) { return $this; } };

class Service_Nj599_Account
{
	public static $totp = FALSE;
	public static $after = [];

	public static function totp_enabled($user_id) { return self::$totp; }
	public static function totp_row($user_id) { return []; }
	public static function totp_verify($secret, $code) { return TRUE; }
	public static function after_login($user_id, $how) { self::$after[] = [$user_id, $how]; }
}

// Настоящий контроллер входа приложения.
require '/app/application/classes/Controller/Auth.php';

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

function login_user()
{
	$user = new stdClass;
	$user->id = 7;
	$user->email_confirmed = 1;
	return $user;
}

/** Прогон action_login: возвращает [код исключения|'page', редирект|NULL]. */
function run_login($request)
{
	Session::$data = Session::$data; // no-op, состояние носим снаружи
	$controller = new Controller_Auth($request);
	Auth::$user = login_user();

	try
	{
		$controller->action_login();
	}
	catch (HTTP_Exception $e)
	{
		return ['redirect', $e->redirect];
	}

	return ['page', NULL];
}

// --- нормализация цели возврата ---------------------------------------------
$r = 'Controller_Auth::return_redirect';
check("$r: относительный путь", Controller_Auth::return_redirect('docs'), '/docs');
check("$r: вложенный путь", Controller_Auth::return_redirect('docs/custom'), '/docs/custom');
check("$r: со слэшем не ломается", Controller_Auth::return_redirect('/design'), '/design');
check("$r: с query-строкой", Controller_Auth::return_redirect('radar?watch=1'), '/radar?watch=1');
check("$r: https-ссылка отвергнута", Controller_Auth::return_redirect('https://evil.example/x'), '');
check("$r: http-ссылка отвергнута", Controller_Auth::return_redirect('http://evil.example'), '');
check("$r: протокольный //host отвергнут", Controller_Auth::return_redirect('//evil.example/x'), '');
check("$r: пусто", Controller_Auth::return_redirect(''), '');
check("$r: пробелы", Controller_Auth::return_redirect('   '), '');
check("$r: без аргумента", Controller_Auth::return_redirect(NULL), '');
check("$r: CRLF-инъекция отвергнута", Controller_Auth::return_redirect("docs\r\nSet-Cookie: x=1"), '');
check("$r: javascript: отвергнут", Controller_Auth::return_redirect('javascript:alert(1)'), '');
check("$r: обратный слэш отвергнут", Controller_Auth::return_redirect('\\evil.example'), '');

// --- сценарий: ?return_url= побеждает Referer -------------------------------
Session::$data = [];
Auth::$logged_in = FALSE;
Auth::$login_ok = FALSE;
run_login(new Request(Request::GET, ['return_url' => 'docs'], [], 'http://n2j.ru/docs'));
check('GET: return_url записан в цель', Session::instance()->get('login_redirect'), '/docs');

run_login(new Request(Request::GET, ['return_url' => 'docs/custom'], [], 'http://n2j.ru/docs/custom'));
check('GET: вложенный return_url', Session::instance()->get('login_redirect'), '/docs/custom');

// --- сценарий: без return_url цель берётся из Referer -----------------------
Session::$data = [];
run_login(new Request(Request::GET, [], [], 'http://n2j.ru/docs/wizard'));
check('GET: Referer записан', Session::instance()->get('login_redirect'), 'http://n2j.ru/docs/wizard');

// --- сценарий: злой return_url игнорируется, Referer спасает ----------------
Session::$data = [];
run_login(new Request(Request::GET, ['return_url' => 'https://evil.example/x'], [], 'http://n2j.ru/docs'));
check('GET: злой return_url отвергнут, Referer спасает', Session::instance()->get('login_redirect'), 'http://n2j.ru/docs');

// --- сценарий: ни return_url, ни Referer — цель не пишется ------------------
Session::$data = [];
run_login(new Request(Request::GET, ['return_url' => '//evil.example'], [], ''));
check('GET: только злой return_url — цели нет', Session::instance()->get('login_redirect'), NULL);

// --- сценарий: успешный вход возвращает на страницу замочка -----------------
Session::$data = [];
Auth::$login_ok = TRUE;
run_login(new Request(Request::GET, ['return_url' => 'docs'], [], 'http://n2j.ru/docs'));
list($kind, $url) = run_login(new Request(Request::POST, [], ['username' => 'u', 'password' => 'p'], 'http://n2j.ru/auth/login?return_url=docs'));
check('POST: успешный вход — редирект', $kind, 'redirect');
check('POST: вернулся на /docs', $url, '/docs');
check('POST: цель израсходована', Session::instance()->get('login_redirect'), NULL);

// --- сценарий: неверный пароль — цель доживает до повторного POST -----------
Session::$data = [];
Auth::$login_ok = FALSE;
run_login(new Request(Request::GET, ['return_url' => 'docs'], [], 'http://n2j.ru/docs'));
list($kind, $url) = run_login(new Request(Request::POST, [], ['username' => 'u', 'password' => 'bad'], 'http://n2j.ru/auth/login'));
check('POST: неверный пароль — страница с ошибкой', $kind, 'page');
check('POST: цель не потеряна', Session::instance()->get('login_redirect'), '/docs');
Auth::$login_ok = TRUE;
list($kind, $url) = run_login(new Request(Request::POST, [], ['username' => 'u', 'password' => 'p'], 'http://n2j.ru/auth/login'));
check('POST: второй заход — редирект на /docs', $url, '/docs');

// --- сценарий: Referer-цель после входа --------------------------------------
Session::$data = [];
run_login(new Request(Request::GET, [], [], 'http://n2j.ru/docs/wizard'));
list($kind, $url) = run_login(new Request(Request::POST, [], ['username' => 'u', 'password' => 'p'], 'http://n2j.ru/auth/login'));
check('POST: возврат по Referer-цели', $url, 'http://n2j.ru/docs/wizard');

// --- сценарий: без цели — на главную ----------------------------------------
Session::$data = [];
list($kind, $url) = run_login(new Request(Request::POST, [], ['username' => 'u', 'password' => 'p'], ''));
check('POST: цели не было — на главную', $url, '/');

// --- сценарий: 2FA несёт цель сквозь второй шаг ------------------------------
Session::$data = [];
Service_Nj599_Account::$totp = TRUE;
Auth::$login_ok = TRUE;
run_login(new Request(Request::GET, ['return_url' => 'docs'], [], 'http://n2j.ru/docs'));
list($kind, $url) = run_login(new Request(Request::POST, [], ['username' => 'u', 'password' => 'p'], 'http://n2j.ru/auth/login'));
check('POST: 2FA — редирект на второй шаг', $url, '/auth/2fa');
$pending = Session::instance()->get('nj599_pending');
check('POST: 2FA несёт цель возврата', is_array($pending) ? $pending['redirect'] : '', '/docs');
Service_Nj599_Account::$totp = FALSE;

// --- вьюхи: в местах замочка — ссылки с возвратом ----------------------------
$v = function ($path) { return file_get_contents('/app/application/modules/'.$path); };
// /docs и /vacancies: замочек теперь общий (Gate::wall, 08.10.2026) — проверяем
// не литерал ссылки, а то, что вьюха отдаёт плашке верную цель возврата.
check('вьюха /docs: замочек с целью возврата docs', strpos($v('nj580_orgdocs/views/580/index.php'), "Gate::wall('docs'") !== FALSE ? 'true' : 'false', 'true');
check('вьюха /docs/custom: ссылка с возвратом', strpos($v('nj580_orgdocs/views/580/custom.php'), '/auth/login?return_url=docs/custom"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха /docs/wizard: ссылка с возвратом', strpos($v('nj580_orgdocs/views/580/wizard.php'), '/auth/login?return_url=docs/wizard"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха вакансий: замочек с целью возврата vacancies', strpos($v('nj642_vacancies/views/642/index.php'), "Gate::wall('vacancies'") !== FALSE ? 'true' : 'false', 'true');
check('вьюха CAD: ссылка живая и с возвратом', strpos($v('nj643_cad/views/643/index.php'), '/auth/login?return_url=cad"') !== FALSE ? 'true' : 'false', 'true');

// Кнопки «Войти или зарегистрироваться» и прочие ссылки входа — с возвратом
// (после входа пользователь возвращается на страницу, где увидел замочек).
check('вьюха /poll: кнопка с возвратом', strpos($v('nj520_poll/views/520/index.php'), '/auth/login?return_url=poll"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха /jump: кнопка с возвратом', strpos($v('nj530_jump/views/530/index.php'), '/auth/login?return_url=jump"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха /receipt: кнопка с возвратом', strpos($v('nj650_receipt/views/650/index.php'), '/auth/login?return_url=receipt"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха /meal: кнопка с возвратом', strpos($v('nj651_meal/views/651/index.php'), '/auth/login?return_url=meal"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха /plant: кнопка с возвратом', strpos($v('nj652_plant/views/652/index.php'), '/auth/login?return_url=plant"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха /3d: ссылка с возвратом', strpos($v('nj570_models/views/570/index.php'), '/auth/login?return_url=3d"') !== FALSE ? 'true' : 'false', 'true');
check('вьюха /tasks: ссылка с возвратом', strpos($v('nj120_jobs/views/120/tasks.php'), '/auth/login?return_url=tasks"') !== FALSE ? 'true' : 'false', 'true');
$s660 = $v('nj660_arch/views/660/view.php');
check('вьюха /arch: вход ведёт обратно к модели', (strpos($s660, '/auth/login?return_url=arch/view/') !== FALSE AND strpos($s660, "HTML::chars(\$design['code'])") !== FALSE) ? 'true' : 'false', 'true');

// мёртвых href="/login" (404) во всей вёрстке быть не должно
$dead = [];
$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator('/app/application', FilesystemIterator::SKIP_DOTS));

foreach ($files as $file)
{
	if ($file->isFile() AND substr($file->getFilename(), -4) === '.php'
		AND strpos($file->getPathname(), '/views/') !== FALSE)
	{
		if (strpos(file_get_contents($file->getPathname()), 'href="/login"') !== FALSE)
		{
			$dead[] = str_replace('/app/application/', '', $file->getPathname());
		}
	}
}

check('вьюхи: мёртвых ссылок href="/login" нет', $dead, []);

// --- замки страниц: одна плашка на всех и всегда цель возврата ---------------
// AGENTS.md §24: страницы «только по аккаунту» не рисуют свою строчку «войдите»
// — они зовут общую плашку `Gate::wall('<страница>')`, а ссылку с `return_url`
// собирает сама плашка. Здесь проверяем обе половины контракта: ни одной своей
// ссылки мимо плашки и ни одной плашки без цели возврата.
$gated = [
	'nj653_veclab/views/653/index.php',
	'nj641_imagegen/views/641/index.php',
	'nj639_qc/views/639/index.php',
	'nj640_cameras/views/640/index.php',
	'nj654_similar/views/654/search.php',
	'nj540_reply/views/540/form.php',
	'nj635_scout/views/635/index.php',
	'nj598_dossier/views/598/index.php',
	'nj642_vacancies/views/642/index.php',
	'nj612_touch/views/612/index.php',
	'nj609_watchdog/views/609/index.php',
	'nj606_leads/views/606/index.php',
	'nj591_company_profile/views/591/index.php',
	'nj592_coldmail/views/592/index.php',
	'nj605_radar/views/605/index.php',
	'nj580_orgdocs/views/580/index.php',
	'nj590_design/views/590/index.php',
];

$bad_wall = [];

foreach ($gated as $path)
{
	$src = $v($path);

	if ( ! preg_match_all("/Gate::wall\('([^']*)'/", $src, $mm))
	{
		$bad_wall[] = $path.': нет Gate::wall';
		continue;
	}

	foreach ($mm[1] as $target)
	{
		if (trim($target) === '')
		{
			$bad_wall[] = $path.': пустая цель возврата';
		}
	}
}

check('замки страниц зовут общую плашку и несут цель возврата', $bad_wall, []);

$wall = file_get_contents('/app/application/views/partials/auth_wall.php');
check('общая плашка строит ссылку входа с возвратом',
	(strpos($wall, '/auth/login') !== FALSE AND strpos($wall, 'return_url=') !== FALSE AND strpos($wall, 'rawurlencode') !== FALSE) ? 'true' : 'false', 'true');

// --- итог -------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
