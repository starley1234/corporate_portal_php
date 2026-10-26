<?php
/**
 * Стенд NJ-659 «Вход через Яндекс» на PHP-in-WASM.
 *
 * Проверяет без сети и без БД:
 *   - состояние «настроен/не настроен» по env (кнопка не рисуется без секретов);
 *   - сборку authorize_url (response_type=code, client_id, redirect_uri, state);
 *   - обмен code → токен и нормализацию профиля login.yandex.ru/info
 *     (HTTP-методы _post/_get подменены фейком);
 *   - ошибки: нет access_token, нет id в профиле, провайдер не настроен;
 *   - экранирование подписи кнопки, формат URL аватара.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/nj659_yandexauth_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

// ---------------------------------------------------------------- заглушки
class Kohana_Config_Group extends ArrayObject
{
	public function as_array()
	{
		return (array) $this;
	}

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
		$file = '/app/application/modules/nj659_yandexauth/config/'.$name.'.php';

		if (is_file($file))
		{
			$data = require $file;

			return new Kohana_Config_Group(is_array($data) ? $data : []);
		}

		return new Kohana_Config_Group([]);
	}
}

class Kohana
{
	public static $config;
	public static $charset = 'UTF-8';
}

Kohana::$config = new Config;

// Ядро без зависимостей.
require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';
require '/app/system/classes/Kohana/HTML.php';
require '/app/system/classes/HTML.php';

// Класс модуля.
require '/app/application/modules/nj659_yandexauth/classes/Service/Nj659/Yandexauth.php';

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

function check_exception($label, $callable, $needle)
{
	global $ok, $bad;

	try
	{
		$callable();
		$bad++;
		echo "ФЕЙЛ $label\n     получили: нет исключения\n     ожидалось: исключение с \"$needle\"\n";
	}
	catch (Exception $e)
	{
		if (strpos($e->getMessage(), $needle) !== FALSE)
		{
			$ok++;
			echo "ok   $label\n";
		}
		else
		{
			$bad++;
			echo "ФЕЙЛ $label\n     получили: «".$e->getMessage()."»\n     ожидалось: \"$needle\"\n";
		}
	}
}

/** Фейк сети: подменяет защищённые _post/_get. */
class Fake_Yandexauth extends Service_Nj659_Yandexauth
{
	public $post_calls = [];
	public $get_calls = [];
	public $token_response = [];
	public $info_response = [];

	protected function _post($url, array $params)
	{
		$this->post_calls[] = [$url, $params];

		return $this->token_response;
	}

	protected function _get($url, array $headers = [])
	{
		$this->get_calls[] = [$url, $headers];

		return $this->info_response;
	}
}

// ---- без env провайдер «не настроен»
$plain = new Service_Nj659_Yandexauth;
check('is_configured: без env — false', $plain->is_configured(), FALSE);
check('login_button: не настроен — пустая строка', $plain->login_button(), '');
check_exception('authorize_url: не настроен — исключение', function () use ($plain) {
	$plain->authorize_url('state-1');
}, 'не настроен');

// ---- настраиваем env (конфиг читает getenv в момент загрузки)
putenv('YANDEX_OAUTH_CLIENT_ID=stand-client-id');
putenv('YANDEX_OAUTH_CLIENT_SECRET=stand-secret');
putenv('YANDEX_OAUTH_REDIRECT_URI=https://n2j.ru/auth/yandex/callback');

$svc = new Fake_Yandexauth;
check('is_configured: с env — true', $svc->is_configured(), TRUE);

// ---- кнопка
$button = $svc->login_button('Войти<b>через</b> Яндекс', 'btn-yandex');
check('login_button: ссылка на старт потока', strpos($button, 'href="/auth/yandex"') !== FALSE, TRUE);
check('login_button: класс передан', strpos($button, 'class="btn-yandex"') !== FALSE, TRUE);
check('login_button: подпись экранируется', strpos($button, 'Войти&lt;b&gt;через&lt;/b&gt; Яндекс') !== FALSE, TRUE);
check('login_button: иконка Яндекса', strpos($button, 'fab fa-yandex') !== FALSE, TRUE);

// ---- authorize_url
$url = $svc->authorize_url('state-abc-123');
check('authorize_url: хост Яндекса', strpos($url, 'https://oauth.yandex.ru/authorize?') === 0, TRUE);
check('authorize_url: response_type=code', strpos($url, 'response_type=code') !== FALSE, TRUE);
check('authorize_url: client_id', strpos($url, 'client_id=stand-client-id') !== FALSE, TRUE);
check('authorize_url: redirect_uri', strpos($url, 'redirect_uri='.urlencode('https://n2j.ru/auth/yandex/callback')) !== FALSE, TRUE);
check('authorize_url: state проходит как есть', strpos($url, 'state=state-abc-123') !== FALSE, TRUE);
check('authorize_url: секрет НЕ попадает в url', strpos($url, 'stand-secret') === FALSE, TRUE);

// ---- обмен кода на токен
$svc->token_response = ['access_token' => 'tok-777'];
$svc->info_response = [
	'id' => '1130000000001',
	'login' => 'stand.user',
	'real_name' => 'Стенд Тестов',
	'default_email' => 'stand.user@yandex.ru',
	'sex' => 'm',
	'birthday' => '1990-01-02',
	'avatar_id' => '0/0-0',
];
$access = $svc->fetch_access('good-code');
check('fetch_access: токен', $access['access_token'], 'tok-777');
check('fetch_access: grant_type', $access !== NULL AND $svc->post_calls[0][1]['grant_type'] === 'authorization_code', TRUE);
check('fetch_access: code передан', $svc->post_calls[0][1]['code'] === 'good-code', TRUE);
check('fetch_access: секрет уходит в POST, не в url', $svc->post_calls[0][1]['client_secret'] === 'stand-secret' AND strpos($svc->post_calls[0][0], 'secret') === FALSE, TRUE);
check('fetch_access: профиль нормализован', $access['profile'], [
	'provider_id' => '1130000000001',
	'login' => 'stand.user',
	'real_name' => 'Стенд Тестов',
	'email' => 'stand.user@yandex.ru',
	'sex' => 'm',
	'birthday' => '1990-01-02',
	'avatar_id' => '0/0-0',
]);

// ---- профиль: email из поля email, когда default_email нет
$svc->info_response = ['id' => '42', 'login' => 'u', 'email' => 'u@ya.ru'];
$profile = $svc->fetch_profile('tok');
check('fetch_profile: фолбэк email', $profile['email'], 'u@ya.ru');
check('fetch_profile: пустые поля нормализованы', $profile['real_name'].$profile['sex'].$profile['birthday'].$profile['avatar_id'], '');

// ---- профиль: заголовок Authorization: OAuth
check('fetch_profile: Authorization заголовок', $svc->get_calls[0][1] === ['Authorization: OAuth tok-777'], TRUE);

// ---- ошибки
$svc->token_response = ['error' => 'invalid_grant'];
check_exception('fetch_access: без access_token — исключение', function () use ($svc) {
	$svc->fetch_access('bad-code');
}, 'access_token');

$svc->token_response = ['access_token' => 'tok-777'];
$svc->info_response = ['login' => 'no-id-user'];
check_exception('fetch_profile: без id — исключение', function () use ($svc) {
	$svc->fetch_profile('tok-777');
}, 'не вернул профиль');

// ---- аватар
check('avatar_url: формат', $svc->avatar_url('12345/abc-islands'), 'https://avatars.yandex.net/get-yapic/12345/abc-islands/islands-200');
check('avatar_url: пустой id', $svc->avatar_url('  '), '');

// ---- real_name → first_name/last_name (колонки users_oauth)
check('split: имя и фамилия', $svc->_split_name('Стенд Тестов'), ['Стенд', 'Тестов']);
check('split: двойной пробел', $svc->_split_name('Иван   Петров'), ['Иван', 'Петров']);
check('split: одно слово', $svc->_split_name('Одноимя'), ['Одноимя', '']);
check('split: пустая строка', $svc->_split_name('  '), ['', '']);

// ---------------------------------------------------------------- итог
echo "\n";
echo $bad === 0 ? "СТЕНД NJ-659: OK ($ok проверок)\n" : "СТЕНД NJ-659: ПРОВАЛ ($bad из ".($ok + $bad).")\n";
exit($bad === 0 ? 0 : 1);
