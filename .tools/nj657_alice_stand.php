<?php
/**
 * Стенд NJ-657 «Навыки Алисы» на PHP-in-WASM.
 *
 * Проверяет чистые функции модуля без БД и без Яндекса:
 *   - разбор и нормализацию протокола Алисы (extract_request);
 *   - маршрутизацию сценариев (route_intent) и отрезание имени активации;
 *   - сборку ответа протокола 1.0 (build_response);
 *   - чистку markdown для озвучки (clean_for_tts) и обрезку ответа;
 *   - разбор кнопок и показ их только экранным устройствам;
 *   - MQTT-пакеты побайтово (CONNECT/PUBLISH, variable-length integer);
 *   - pg-булевы поля навыка приходят строками 't'/'f' — регрессия бага
 *     «навык закрывал сессию после первого ответа» ((bool)'f' === true).
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/nj657_alice_stand.php
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
		// конфиг модуля и конфиг приложения (llm — для модели по умолчанию)
		foreach ([
			'/app/application/modules/nj657_alice/config/'.$name.'.php',
			'/app/application/config/'.$name.'.php',
		] as $file)
		{
			if (is_file($file))
			{
				$data = require $file;

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
}

Kohana::$config = new Config;
Kohana::$log    = new class { public function add($l, $m, $v = []) { } };

class Log
{
	const INFO    = 6;
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

// Настоящий Arr из ядра (без зависимостей).
require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

// Классы модуля.
require '/app/application/modules/nj657_alice/classes/Service/Nj657/Mqtt.php';
require '/app/application/modules/nj657_alice/classes/Service/Nj657/Alice.php';

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

function check_true($label, $condition)
{
	check($label, $condition ? 'true' : 'false', 'true');
}

function alice_request($command, $is_new, $screen = TRUE)
{
	return [
		'meta' => ['locale' => 'ru-RU', 'timezone' => 'Europe/Moscow', 'interfaces' => $screen ? ['screen' => []] : []],
		'request' => [
			'command' => $command,
			'original_utterance' => $command,
			'type' => 'SimpleUtterance',
			'markup' => ['dangerous_context' => FALSE],
			'payload' => [],
		],
		'session' => [
			'message_id' => 4,
			'session_id' => '2eac4854-fce721f0',
			'skill_id' => '7c7cc605-8b9b',
			'user_id' => 'legacy',
			'user' => ['user_id' => 'F5B99B8C9A'],
			'new' => $is_new,
		],
		'version' => '1.0',
	];
}

$skill = [
	'id' => 1,
	'code' => 'maindialog1',
	'title' => 'Мой диалог',
	'yandex_skill_id' => '',
	'webhook_token' => 'tok1234567890abcdef',
	'is_active' => 't',
	'is_default' => 't',
	'system_text' => 'Ты опытный Врач.',
	'activation_names' => "Мой диалог\nмой навык",
	'welcome_text' => '',
	'welcome_tts' => '',
	'help_text' => '',
	'farewell_text' => '',
	'fallback_text' => '',
	'model' => '',
	'temperature' => '0.200',
	'max_tokens' => 400,
	'answer_max_chars' => 1024,
	'history_depth' => 6,
	'history_window_min' => 120,
	'end_session' => 'f',
	'buttons_text' => "Ещё вопрос\nПомощь",
	'mqtt_topic' => 'hasp/esp32/command/p1b1.text',
];

// --- extract_request --------------------------------------------------------
$x = Service_Nj657_Alice::extract_request(alice_request('привет', TRUE));
check('extract: ok', $x['ok'] ? 'true' : 'false', 'true');
check('extract: session_id', $x['session']['session_id'], '2eac4854-fce721f0');
check('extract: message_id', $x['session']['message_id'], 4);
check('extract: skill_id', $x['session']['skill_id'], '7c7cc605-8b9b');
check('extract: is_new', $x['session']['is_new'] ? 'true' : 'false', 'true');
check('extract: has_screen', $x['request']['has_screen'] ? 'true' : 'false', 'true');
check('extract: dangerous off', $x['request']['dangerous'] ? 'true' : 'false', 'false');
check('extract: user_key = хеш постоянного id', $x['session']['user_key'], Service_Nj657_Alice::user_key('F5B99B8C9A'));

$bad_request = Service_Nj657_Alice::extract_request(['session' => []]);
check('extract: мусор отвергнут', $bad_request['ok'] ? 'true' : 'false', 'false');

$danger = alice_request('что-то', FALSE);
$danger['request']['markup']['dangerous_context'] = TRUE;
$dx = Service_Nj657_Alice::extract_request($danger);
check('extract: dangerous on', $dx['request']['dangerous'] ? 'true' : 'false', 'true');

// --- маршрутизация ----------------------------------------------------------
$config = Service_Nj657_Alice::config();
check('route: новая сессия без слов → welcome', Service_Nj657_Alice::route_intent('', TRUE, $skill, $config), 'welcome');
check('route: тишина в разговоре → help', Service_Nj657_Alice::route_intent('', FALSE, $skill, $config), 'help');
check('route: «Помощь!»', Service_Nj657_Alice::route_intent('Помощь!', FALSE, $skill, $config), 'help');
check('route: «стоп.»', Service_Nj657_Alice::route_intent('стоп.', FALSE, $skill, $config), 'exit');
check('route: приветствие в новой сессии', Service_Nj657_Alice::route_intent('Привет', TRUE, $skill, $config), 'welcome');
check('route: приветствие в разговоре — вопрос ИИ', Service_Nj657_Alice::route_intent('Привет', FALSE, $skill, $config), 'llm');
check('route: вопрос — ИИ', Service_Nj657_Alice::route_intent('Что делать при ожоге?', TRUE, $skill, $config), 'llm');

// --- вопрос: отрезание имени активации --------------------------------------
$qx = Service_Nj657_Alice::extract_request(alice_request('Мой диалог, что делать при гриппе?', TRUE));
check('question: имя активации отрезано', Service_Nj657_Alice::question_text($qx, $skill), 'что делать при гриппе?');
$ox = Service_Nj657_Alice::extract_request(alice_request('Мой диалог, что делать при гриппе?', FALSE));
check('question: вне новой сессии не режется', Service_Nj657_Alice::question_text($ox, $skill), 'Мой диалог, что делать при гриппе?');

// --- сборка ответа ----------------------------------------------------------
$response = Service_Nj657_Alice::build_response($x['session'], 'Ответ', 'Отв+ет', [], FALSE);
check('response: version', $response['version'], '1.0');
check('response: session echo', $response['session']['session_id'].'|'.$response['session']['message_id'], '2eac4854-fce721f0|4');
check('response: text', $response['response']['text'], 'Ответ');
check('response: tts', $response['response']['tts'], 'Отв+ет');
check('response: end_session', $response['response']['end_session'] ? 'true' : 'false', 'false');

// --- правда pg-булитов (BOOLEAN отдаётся строками 't'/'f') -----------------
check('truthy: pg «f» — ложь', Service_Nj657_Alice::truthy('f') ? 'true' : 'false', 'false');
check('truthy: pg «t» — истина', Service_Nj657_Alice::truthy('t') ? 'true' : 'false', 'true');
check('truthy: «0»/«»/false/NULL — ложь', (Service_Nj657_Alice::truthy('0') OR Service_Nj657_Alice::truthy('') OR Service_Nj657_Alice::truthy(FALSE) OR Service_Nj657_Alice::truthy(NULL)) ? 'true' : 'false', 'false');
check('truthy: «1»/true/1 — истина', (Service_Nj657_Alice::truthy('1') AND Service_Nj657_Alice::truthy(TRUE) AND Service_Nj657_Alice::truthy(1)) ? 'true' : 'false', 'true');

// --- кнопки -----------------------------------------------------------------
$buttons = Service_Nj657_Alice::response_buttons($skill);
check('buttons: parse', json_encode($buttons, JSON_UNESCAPED_UNICODE), json_encode([
	['title' => 'Ещё вопрос', 'payload' => ['command' => 'Ещё вопрос'], 'hide' => TRUE],
	['title' => 'Помощь', 'payload' => ['command' => 'Помощь'], 'hide' => TRUE],
], JSON_UNESCAPED_UNICODE));
check('buttons: экрану — да', count(Service_Nj657_Alice::buttons_for($skill, $x)), 2);
$vx = Service_Nj657_Alice::extract_request(alice_request('вопрос', FALSE, FALSE));
check('buttons: колонке без экрана — нет', count(Service_Nj657_Alice::buttons_for($skill, $vx)), 0);
check('buttons: лимит 5 строк', count(Service_Nj657_Alice::parse_buttons("1\n2\n3\n4\n5\n6\n7")), 5);

// --- TTS и обрезка ----------------------------------------------------------
$tts = Service_Nj657_Alice::clean_for_tts("# Заголовок\n\n**Жирный** и `код`, [текст](https://example.ru/p).\n- пункт\nОбычная https://example.ru/x строка.");
check_true('tts: без **', strpos($tts, '**') === FALSE);
check_true('tts: без `', strpos($tts, '`') === FALSE);
check_true('tts: без ссылок', strpos($tts, 'https://') === FALSE);
check_true('tts: без #', strpos($tts, '#') === FALSE);
check_true('tts: текст ссылки сохранён', strpos($tts, 'текст') !== FALSE);

$long_text = str_repeat('слово ', 399).'слово'; // без краевых пробелов
$cut = Service_Nj657_Alice::trim_answer($long_text, 100);
check_true('trim: не длиннее лимита+многоточие', mb_strlen($cut) <= 101);
check_true('trim: по границе слова', substr($cut, -2, 1) !== ' ');
check('trim: без лимита — как есть', Service_Nj657_Alice::trim_answer($long_text, 0), $long_text);
check('trim: жёсткая резка одного слова', mb_strlen(Service_Nj657_Alice::trim_answer(str_repeat('а', 500), 100)), 101);

// --- ключ пользователя и вебхук --------------------------------------------
check('user_key: пустой id', Service_Nj657_Alice::user_key(''), '');
check('user_key: 32 символа', strlen(Service_Nj657_Alice::user_key('x')), 32);
check('user_key: разные id — разные ключи', Service_Nj657_Alice::user_key('a') === Service_Nj657_Alice::user_key('b') ? 'true' : 'false', 'false');
check('webhook: без токена', Service_Nj657_Alice::webhook_url(), rtrim((string) Service_Nj657_Alice::config()['webhook_base'], '/').'/alice/webhook');
check('webhook: с токеном навыка', Service_Nj657_Alice::webhook_url($skill), rtrim((string) Service_Nj657_Alice::config()['webhook_base'], '/').'/alice/webhook/tok1234567890abcdef');

// --- пресеты ----------------------------------------------------------------
$presets = Service_Nj657_Alice::presets();
check('presets: есть «Без настроек» и роли', (count($presets) >= 10 AND $presets[0]['title'] === 'Без настроек' AND $presets[0]['text'] === '') ? 'true' : 'false', 'true');

// --- MQTT -------------------------------------------------------------------
check('mqtt: топик валиден', Service_Nj657_Mqtt::topic_valid('hasp/esp32/command/p1b1.text') ? 'true' : 'false', 'true');
check('mqtt: wildcard запрещён', Service_Nj657_Mqtt::topic_valid('home/#') ? 'true' : 'false', 'false');
check('mqtt: wildcard плюс запрещён', Service_Nj657_Mqtt::topic_valid('home/+') ? 'true' : 'false', 'false');
check('mqtt: пробелы запрещены', Service_Nj657_Mqtt::topic_valid('home with spaces') ? 'true' : 'false', 'false');
check('mqtt: CONNECT без авторизации', bin2hex(Service_Nj657_Mqtt::build_connect_packet('testclient')), '101600044d5154540402001e000a74657374636c69656e74');
check('mqtt: CONNECT с авторизацией', bin2hex(Service_Nj657_Mqtt::build_connect_packet('nj657', 'u', 'p')), '101700044d51545404c2001e00056e6a363537000175000170');
check('mqtt: PUBLISH', bin2hex(Service_Nj657_Mqtt::build_publish_packet('a/b', 'hi')), '30070003612f626869');
check('mqtt: длина > 127 двумя байтами', substr(bin2hex(Service_Nj657_Mqtt::build_publish_packet('t', str_repeat('x', 200))), 0, 6), '30cb01');

// ===========================================================================
// Пайплайн handle(): база и модель — заглушки, сценарии — настоящие.
// ===========================================================================

class FakeResult
{
	private $rows;

	public function __construct(array $rows)
	{
		$this->rows = array_values($rows);
	}

	public function current()
	{
		return $this->rows[0] ?? NULL;
	}

	public function as_array()
	{
		return $this->rows;
	}
}

class Database
{
	const SELECT = 20;
	const INSERT = 21;
	const UPDATE = 22;
	const DELETE = 23;

	/** @var array строки навыков для SELECT * FROM nj_657_skills */
	public static $skills = [];
	/** @var array|null ответ на SELECT памяти (контекст) */
	public static $context_rows = [];
	/** @var array|null строка-дубликат для SELECT повторов */
	public static $duplicate_row = NULL;
	/** @var string[] выполненные INSERT (для проверок) */
	public static $inserts = [];

	private static $instance = NULL;

	public static function instance()
	{
		return self::$instance !== NULL ? self::$instance : self::$instance = new self;
	}

	public function quote($value)
	{
		if ($value === NULL) return 'NULL';
		if ($value === TRUE) return "'1'";
		if ($value === FALSE) return "'0'";
		if (is_int($value) OR is_float($value)) return (string) $value;

		return "'".addcslashes((string) $value, "'\\")."'";
	}

	public function query($type, $sql, $as_object = FALSE)
	{
		if ($type === self::INSERT OR $type === self::UPDATE OR $type === self::DELETE)
		{
			if ($type === self::INSERT)
			{
				self::$inserts[] = $sql;
			}

			return new FakeResult([]);
		}

		if (strpos($sql, 'FROM nj_657_skills') !== FALSE)
		{
			foreach (self::$skills as $row)
			{
				if (strpos($sql, 'webhook_token') !== FALSE AND strpos($sql, $this->quote($row['webhook_token'])) !== FALSE) return new FakeResult([$row]);
				if (strpos($sql, 'yandex_skill_id') !== FALSE AND strpos($sql, $this->quote($row['yandex_skill_id'])) !== FALSE) return new FakeResult([$row]);
				if (strpos($sql, 'is_default') !== FALSE AND Service_Nj657_Alice::truthy($row['is_default'])) return new FakeResult([$row]);
				if (strpos($sql, 'code =') !== FALSE AND strpos($sql, $this->quote($row['code'])) !== FALSE) return new FakeResult([$row]);
			}

			return new FakeResult([]);
		}

		if (strpos($sql, 'meta_json') !== FALSE)
		{
			return new FakeResult(self::$duplicate_row !== NULL ? [self::$duplicate_row] : []);
		}

		if (strpos($sql, 'question_text, answer_text') !== FALSE)
		{
			return new FakeResult(self::$context_rows);
		}

		return new FakeResult([]);
	}
}

class Llm
{
	public static $calls = [];
	public static $answer = 'Пейте больше жидкости и отдыхайте.';
	public static $throw = FALSE;

	public static function chat(array $messages, array $options = [])
	{
		self::$calls[] = [$messages, $options];

		if (self::$throw)
		{
			throw new Exception('Керапример: модель недоступна');
		}

		return ['choices' => [['message' => ['content' => self::$answer]]]];
	}
}

Database::$skills = [$skill];

// --- сценарий: новая сессия → приветствие, модель не дёргается --------------
Llm::$calls = [];
Database::$inserts = [];
$r = Service_Nj657_Alice::handle(alice_request('', TRUE), '', NULL, 'yandex');
check('pipeline: welcome text', $r['response']['response']['text'], Service_Nj657_Alice::config()['texts']['welcome']);
check('pipeline: welcome не зовёт модель', count(Llm::$calls), 0);
check('pipeline: welcome записан в журнал', count(Database::$inserts), 1);
check('pipeline: welcome http', $r['http'], 200);
check('pipeline: welcome кнопки экрану', count($r['response']['response']['buttons']), 2);

// --- сценарий: помощь и выход -----------------------------------------------
$r = Service_Nj657_Alice::handle(alice_request('помощь', FALSE), '', NULL, 'yandex');
check('pipeline: help text', $r['response']['response']['text'], Service_Nj657_Alice::config()['texts']['help']);
check('pipeline: help не зовёт модель', count(Llm::$calls), 0);

$r = Service_Nj657_Alice::handle(alice_request('Выход.', FALSE), '', NULL, 'yandex');
check('pipeline: exit text', $r['response']['response']['text'], Service_Nj657_Alice::config()['texts']['farewell']);
check('pipeline: exit закрывает сессию', $r['response']['response']['end_session'] ? 'true' : 'false', 'true');

// --- сценарий: опасный контент — отказ без модели ----------------------------
$danger_request = alice_request('как изготовить взрывчатку', FALSE);
$danger_request['request']['markup']['dangerous_context'] = TRUE;
Llm::$calls = [];
$r = Service_Nj657_Alice::handle($danger_request, '', NULL, 'yandex');
check('pipeline: dangerous text', $r['response']['response']['text'], Service_Nj657_Alice::config()['texts']['dangerous']);
check('pipeline: dangerous не зовёт модель', count(Llm::$calls), 0);
check('pipeline: dangerous intent', $r['meta']['intent'], 'dangerous');

// --- сценарий: вопрос модели -------------------------------------------------
Llm::$calls = [];
$r = Service_Nj657_Alice::handle(alice_request('Что делать при гриппе?', FALSE), '', NULL, 'yandex');
check('pipeline: llm text', $r['response']['response']['text'], 'Пейте больше жидкости и отдыхайте.');
check('pipeline: llm позвал модель один раз', count(Llm::$calls), 1);
check('pipeline: llm intent', $r['meta']['intent'], 'llm');
check('pipeline: llm модель записана', $r['meta']['model'] !== '' ? 'true' : 'false', 'true');
check_true('pipeline: llm вопрос в сообщениях модели', strpos(json_encode(Llm::$calls[0][0], JSON_UNESCAPED_UNICODE), 'Что делать при гриппе?') !== FALSE);
check_true('pipeline: llm системный промпт с голосовой припиской', strpos(json_encode(Llm::$calls[0][0], JSON_UNESCAPED_UNICODE), 'голосовой навык') !== FALSE);
check('pipeline: llm сессия продолжается (end_session «f» из pg)', $r['response']['response']['end_session'] ? 'true' : 'false', 'false');
check('pipeline: навык из БД с pg-строками распознан', ($r['skill']['is_active'] === TRUE AND $r['skill']['is_default'] === TRUE AND $r['skill']['end_session'] === FALSE) ? 'true' : 'false', 'true');

// --- сценарий: галка «закрывать сессию» (pg «t») ----------------------------
$skill_close = $skill;
$skill_close['end_session'] = 't';
Database::$skills = [$skill_close];
Llm::$calls = [];
$r = Service_Nj657_Alice::handle(alice_request('Ещё вопрос', FALSE), '', NULL, 'yandex');
check('pipeline: галка end_session «t» закрывает сессию', $r['response']['response']['end_session'] ? 'true' : 'false', 'true');

// --- сценарий: выключенный навык (pg «f») -----------------------------------
$skill_off = $skill;
$skill_off['is_active'] = 'f';
Database::$skills = [$skill_off];
Llm::$calls = [];
$r = Service_Nj657_Alice::handle(alice_request('Что делать при гриппе?', FALSE), '', NULL, 'yandex');
check('pipeline: навык с is_active «f» отвечает «отключён»', $r['response']['response']['text'], Service_Nj657_Alice::config()['texts']['inactive']);
check('pipeline: выключенный навык не зовёт модель', count(Llm::$calls), 0);
check('pipeline: выключенный навык закрывает сессию', $r['response']['response']['end_session'] ? 'true' : 'false', 'true');
Database::$skills = [$skill];

// --- сценарий: память диалога уходит в контекст ------------------------------
Database::$context_rows = [
	['question_text' => 'Что делать при гриппе?', 'answer_text' => 'Пейте больше жидкости и отдыхайте.'],
];
Llm::$calls = [];
$r = Service_Nj657_Alice::handle(alice_request('А при температуре?', FALSE), '', NULL, 'yandex');
$roles = array_map(function ($m) { return $m['role']; }, Llm::$calls[0][0]);
check('pipeline: контекст из журнала', implode(',', $roles), 'system,user,assistant,user');

// --- сценарий: сбой модели → запасной ответ ----------------------------------
Llm::$throw = TRUE;
Llm::$calls = [];
$r = Service_Nj657_Alice::handle(alice_request('Что делать при гриппе?', FALSE), '', NULL, 'yandex');
check('pipeline: fallback text', $r['response']['response']['text'], Service_Nj657_Alice::config()['texts']['fallback']);
check('pipeline: fallback intent', $r['meta']['intent'], 'fallback');
check_true('pipeline: fallback ошибка записана', $r['meta']['error'] !== '');
Llm::$throw = FALSE;

// --- сценарий: повтор запроса Яндекса — сохранённый ответ ---------------------
Llm::$calls = [];
$stored_response = Service_Nj657_Alice::build_response(
	['session_id' => 'dup-1', 'message_id' => 9, 'user_id' => 'u'],
	'Сохранённый ответ', '', [], FALSE
);
Database::$duplicate_row = [
	'id' => 5,
	'answer_text' => 'Сохранённый ответ',
	'intent' => 'llm',
	'meta_json' => json_encode(['response' => $stored_response], JSON_UNESCAPED_UNICODE),
];
$dup_request = alice_request('Что делать при гриппе?', FALSE);
$dup_request['session']['session_id'] = 'dup-1';
$dup_request['session']['message_id'] = 9;
$r = Service_Nj657_Alice::handle($dup_request, '', NULL, 'yandex');
check('pipeline: дубликат отдаёт сохранённый ответ', $r['response']['response']['text'], 'Сохранённый ответ');
check('pipeline: дубликат не зовёт модель', count(Llm::$calls), 0);
check('pipeline: дубликат помечен', $r['duplicate'] ? 'true' : 'false', 'true');
Database::$duplicate_row = NULL;

// --- сценарий: вебхук по токену навыка ----------------------------------------
Llm::$calls = [];
$r = Service_Nj657_Alice::handle(alice_request('Что делать при гриппе?', FALSE), 'tok1234567890abcdef', NULL, 'yandex');
check('pipeline: токен нашёл навык', $r['skill']['code'], 'maindialog1');

// --- сценарий: чужой токен не проваливается на навык по умолчанию -------------
$r = Service_Nj657_Alice::handle(alice_request('Что делать при гриппе?', FALSE), 'wrong-token', NULL, 'yandex');
check('pipeline: чужой токен → навык не найден', $r['skill'] === NULL ? 'true' : 'false', 'true');
check('pipeline: чужой токен → честный ответ', $r['response']['response']['text'], Service_Nj657_Alice::config()['texts']['unmatched']);

// --- сценарий: битый JSON — 400 ----------------------------------------------
$r = Service_Nj657_Alice::handle(['foo' => 'bar'], '', NULL, 'yandex');
check('pipeline: битый запрос http 400', $r['http'], 400);

// --- сценарий: тестовая консоль идёт тем же пайплайном ------------------------
Llm::$calls = [];
$test_request = Service_Nj657_Alice::build_test_request($skill, 'Что делать при гриппе?', FALSE);
$r = Service_Nj657_Alice::handle($test_request, '', $skill, 'test');
check('pipeline: тест зовёт модель', count(Llm::$calls), 1);
check('pipeline: тест получает ответ', $r['response']['response']['text'], 'Пейте больше жидкости и отдыхайте.');
check_true('pipeline: тестовая сессия в журнале', strpos(end(Database::$inserts), "'test-") !== FALSE);

// ===========================================================================
// Вёрстка: рендер всех вьюх модуля с типовыми данными (ловим fatal/notice
// и непропаренные переменные; пользовательский ввод — через HTML::chars).
// ===========================================================================

class View
{
	private $file;

	public function __construct($file)
	{
		$this->file = $file;
	}

	public static function factory($file)
	{
		return new self($file);
	}

	public function __toString()
	{
		ob_start();
		require '/app/application/modules/nj657_alice/views/'.str_replace(['\\', "\0"], '', $this->file).'.php';

		return (string) ob_get_clean();
	}
}

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

function nj657_render($view, array $data)
{
	extract($data, EXTR_SKIP);
	ob_start();
	require '/app/application/modules/nj657_alice/views/'.$view.'.php';

	return (string) ob_get_clean();
}

// --- публичная страница ------------------------------------------------------
$index_html = nj657_render('657/index', [
	'webhook_url'   => Service_Nj657_Alice::webhook_url(),
	'presets'       => Service_Nj657_Alice::presets(),
	'can_admin'     => TRUE,
	'mqtt_enabled'  => FALSE,
	'active_skills' => 2,
]);
check_true('view: index отрендерился', strlen($index_html) > 2000);
check_true('view: index показывает вебхук', strpos($index_html, '/alice/webhook') !== FALSE);
check_true('view: index — ссылка на админку', strpos($index_html, '/alice/admin') !== FALSE);

// --- список навыков ----------------------------------------------------------
$stats_row = [
	'id' => 1, 'code' => 'maindialog1', 'title' => 'Мой диалог & друзья',
	'is_active' => TRUE, 'is_default' => TRUE, 'yandex_skill_id' => '',
	'webhook_token' => 'tok1234567890abcdef', 'mqtt_topic' => '', 'updated_at' => '2026-10-06 10:00:00',
	'dialogs' => 12, 'day_dialogs' => 3, 'last_at' => '2026-10-06 09:30:00',
];
$admin_html = nj657_render('657/admin', [
	'stats' => [$stats_row], 'total_dialogs' => 12, 'day_dialogs' => 3,
	'webhook_url' => Service_Nj657_Alice::webhook_url(), 'mqtt_enabled' => FALSE,
	'csrf' => 'csrf-token-1', 'notice' => 'Настройки сохранены', 'error' => NULL,
]);
check_true('view: admin отрендерился', strlen($admin_html) > 2000);
check_true('view: admin экранирует амперсанд в названии', strpos($admin_html, 'Мой диалог &amp; друзья') !== FALSE);
check_true('view: admin показывает вебхук с токеном', strpos($admin_html, '/alice/webhook/tok1234567890abcdef') !== FALSE);

// --- форма навыка (создание) -------------------------------------------------
$form_defaults = [
	'code' => '', 'title' => '', 'yandex_skill_id' => '', 'webhook_token' => 'newtok123',
	'activation_names' => '', 'system_text' => '', 'welcome_text' => '', 'welcome_tts' => '',
	'help_text' => '', 'farewell_text' => '', 'fallback_text' => '', 'model' => '',
	'temperature' => '0.2', 'max_tokens' => 400, 'answer_max_chars' => 1024,
	'history_depth' => 6, 'history_window_min' => 120, 'buttons_text' => '', 'mqtt_topic' => '',
	'is_active' => TRUE, 'is_default' => FALSE, 'end_session' => FALSE,
];
$skill_new_html = nj657_render('657/skill', [
	'skill' => NULL, 'is_new' => TRUE, 'form' => $form_defaults,
	'presets' => Service_Nj657_Alice::presets(),
	'default_model' => 'local::qwen3-coder-30b-a3b-instruct',
	'webhook_base' => Service_Nj657_Alice::webhook_url(),
	'csrf' => 'csrf-token-1', 'errors' => [], 'saved' => FALSE,
]);
check_true('view: skill (новый) отрендерился', strlen($skill_new_html) > 4000);
check_true('view: skill — все пресеты-кнопки', substr_count($skill_new_html, 'nj657-preset') >= 10);
check_true('view: skill — csrf в форме', strpos($skill_new_html, 'csrf-token-1') !== FALSE);

// --- форма навыка (правка, ошибки валидации) ---------------------------------
$form_edit = array_merge($form_defaults, [
	'code' => 'maindialog1', 'title' => 'Мой <диалог>', 'webhook_token' => 'tok1234567890abcdef',
	'system_text' => "Ты опытный Врач.", 'is_default' => TRUE,
]);
$skill_edit_html = nj657_render('657/skill', [
	'skill' => NULL, 'is_new' => FALSE, 'form' => $form_edit,
	'presets' => Service_Nj657_Alice::presets(),
	'default_model' => 'local::qwen3-coder-30b-a3b-instruct',
	'webhook_base' => Service_Nj657_Alice::webhook_url(),
	'csrf' => 'csrf-token-1', 'errors' => ['Название: 1–100 символов.'], 'saved' => TRUE,
]);
check_true('view: skill (правка) отрендерился', strlen($skill_edit_html) > 4000);
check_true('view: skill экранирует < в названии', strpos($skill_edit_html, 'Мой &lt;диалог&gt;') !== FALSE);
check_true('view: skill показывает ошибки валидации', strpos($skill_edit_html, 'Название: 1–100 символов.') !== FALSE);
check_true('view: skill — кнопка удаления (data-n2j-arm)', strpos($skill_edit_html, 'data-n2j-arm="Точно удалить?"') !== FALSE);

// --- журнал ------------------------------------------------------------------
$history_html = nj657_render('657/history', [
	'rows' => [
		['id' => 2, 'skill_title' => 'Мой диалог', 'user_key' => 'abcdef1234567890', 'question_text' => 'Что делать при <гриппе>?',
		 'answer_text' => 'Пейте больше жидкости и отдыхайте.', 'intent' => 'llm', 'source' => 'yandex',
		 'created_at' => '2026-10-06 09:30:00', 'meta' => json_encode(['ms' => 850, 'model' => 'local::qwen', 'error' => ''], JSON_UNESCAPED_UNICODE)],
		['id' => 1, 'skill_title' => 'Мой диалог', 'user_key' => 'abcdef1234567890', 'question_text' => '',
		 'answer_text' => 'Здравствуйте!', 'intent' => 'welcome', 'source' => 'test',
		 'created_at' => '2026-10-06 09:00:00', 'meta' => '{}'],
	],
	'total' => 120, 'page' => 2, 'pages' => 3,
	'filters' => ['skill' => 1, 'intent' => '', 'source' => ''],
	'skills' => [['id' => 1, 'title' => 'Мой диалог']],
	'intents' => Service_Nj657_Alice::INTENTS,
	'intent_labels' => ['welcome' => 'приветствие', 'help' => 'помощь', 'exit' => 'выход', 'llm' => 'ответ ИИ', 'fallback' => 'сбой ИИ', 'dangerous' => 'опасный контент'],
]);
check_true('view: history отрендерился', strlen($history_html) > 2000);
check_true('view: history экранирует вопрос', strpos($history_html, '&lt;гриппе&gt;') !== FALSE);
check_true('view: history — пагинация', strpos($history_html, 'страница 2 из 3') !== FALSE);

// --- тестовая консоль ---------------------------------------------------------
$test_result = Service_Nj657_Alice::handle(Service_Nj657_Alice::build_test_request($skill, 'Что делать при гриппе?', FALSE), '', $skill, 'test');
$test_html = nj657_render('657/test', [
	'skills' => [array_merge($skill, ['code' => 'maindialog1'])],
	'skill'  => $skill, 'text' => 'Что делать при гриппе?', 'is_new' => FALSE,
	'result' => $test_result, 'csrf' => 'csrf-token-1',
]);
check_true('view: test отрендерился', strlen($test_html) > 2000);
check_true('view: test показывает ответ', strpos($test_html, 'Пейте больше жидкости и отдыхайте.') !== FALSE);
check_true('view: test показывает JSON для Яндекса', strpos($test_html, 'session_id') !== FALSE);

// --- карточка каталога ---------------------------------------------------------
$card_html = nj657_render('nj657/card', []);
check_true('view: card отрендерился', strlen($card_html) > 200 AND strpos($card_html, '/alice') !== FALSE);

// ===========================================================================
// Контроллер вебхука: настоящий файл, каркас — заглушки.
// ===========================================================================

class Controller
{
	public $auto_render = TRUE;
	public $request;
	public $response;

	public function __construct($request, $response)
	{
		$this->request = $request;
		$this->response = $response;
	}

	public function before() {}
	public function after() {}
}

class Request
{
	const POST = 'POST';
	const GET  = 'GET';

	public $http_method = 'POST';
	public $raw_body = '';
	public $params = [];

	public function method($value = NULL)
	{
		return $this->http_method;
	}

	public function body($content = NULL)
	{
		return $this->raw_body;
	}

	public function param($key, $default = NULL)
	{
		return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
	}
}

class Response
{
	public $code = 200;
	public $raw_body = '';
	public $headers_list = [];

	public function headers($key, $value = NULL)
	{
		if ($value === NULL) return isset($this->headers_list[$key]) ? $this->headers_list[$key] : NULL;
		$this->headers_list[$key] = $value;
		return $this;
	}

	public function status($code)
	{
		$this->code = (int) $code;
		return $this;
	}

	public function body($content = NULL)
	{
		if ($content === NULL) return $this->raw_body;
		$this->raw_body = (string) $content;
		return $this;
	}
}

require '/app/application/modules/nj657_alice/classes/Controller/Nj657/Webhook.php';

function nj657_webhook_call($method, $raw_body, $token = '')
{
	$request = new Request();
	$request->http_method = $method;
	$request->raw_body = $raw_body;

	if ($token !== '')
	{
		$request->params['token'] = $token;
	}

	$response = new Response();
	$controller = new Controller_Nj657_Webhook($request, $response);
	$controller->before();
	$controller->action_index();

	return $response;
}

// GET → 405 с подсказкой.
$get = nj657_webhook_call(Request::GET, '');
check('webhook: GET → 405', $get->code, 405);
check_true('webhook: GET отвечает JSON', json_decode($get->raw_body, TRUE) !== NULL);

// Битое тело → 400.
$bad_json = nj657_webhook_call(Request::POST, 'не json вовсе');
check('webhook: битый JSON → 400', $bad_json->code, 400);
check('webhook: битый JSON — ошибка', json_decode($bad_json->raw_body, TRUE)['error'] !== '' ? 'true' : 'false', 'true');

// Настоящий запрос Алисы → 200 и валидный протокол.
Llm::$calls = [];
Database::$inserts = [];
$alice_json = json_encode(alice_request('Что делать при гриппе?', FALSE), JSON_UNESCAPED_UNICODE);
$ok_response = nj657_webhook_call(Request::POST, $alice_json);
$payload = json_decode($ok_response->raw_body, TRUE);
check('webhook: POST → 200', $ok_response->code, 200);
check('webhook: version 1.0', $payload['version'], '1.0');
check('webhook: эхо сессии', $payload['session']['session_id'], '2eac4854-fce721f0');
check('webhook: ответ модели', $payload['response']['text'], 'Пейте больше жидкости и отдыхайте.');
check('webhook: tts почищен', $payload['response']['tts'], 'Пейте больше жидкости и отдыхайте.');
check('webhook: кнопки', count($payload['response']['buttons']), 2);
check('webhook: реплика в журнале', count(Database::$inserts), 1);
check('webhook: тип содержимого JSON', strpos((string) $ok_response->headers_list['Content-Type'], 'application/json') === 0 ? 'true' : 'false', 'true');

// Запрос по токену навыка.
Llm::$calls = [];
$token_response = nj657_webhook_call(Request::POST, $alice_json, 'tok1234567890abcdef');
check('webhook: токен → 200', $token_response->code, 200);

// --- итог -------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
