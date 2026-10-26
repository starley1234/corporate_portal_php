<?php
/**
 * Стенд: модель из $options доезжает до провайдера (модуль llm).
 *
 * 08.10.2026 на проде: в навыке Алисы
 * (/alice/admin/skill/maindialog1) в форме выбрана модель, она уходит в
 * Llm::chat($messages, ['model' => 'openrouter::...']) — но отвечает модель
 * платформы. Причина: Llm_Factory клала default_model в _current_model_name,
 * то есть «модель по умолчанию» выглядела как явный выбор вызывающего и в
 * Llm_Service::chat() перетирала $options['model']; а после reset() (в конце
 * каждого chat()) модель из $options всё равно заменялась на модель
 * провайдера. Проверено: модель не доезжала никогда.
 *
 * Проверяет (по последнему ушедшему в CurlRequest запросу — то есть то, что
 * реально полетит в сеть):
 *   - модель из $options выигрывает у модели платформы (регресс бага);
 *   - то же самое на втором и третьем вызове (синглтон + reset());
 *   - цепочка Llm::model('провайдер::модель') работает;
 *   - без модели — модель платформы (llm.default_model);
 *   - картинки без модели — vision_model из конфига;
 *   - алиасы llm.model_aliases применяются к модели из $options;
 *   - Llm::provider('openrouter') — модель провайдера, а не платформы;
 *   - навык Алисы: модель из настроек навыка уходит в провайдера.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/llm_options_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

// Провайдер 'local' должен иметь-base_url: конфиг llm.php читает окружение.
putenv('TOOLFORGE_API_URL=http://lm-studio:1234/v1');
putenv('EMBEDDING_API_URL=');

// ---------------------------------------------------------------- заглушки

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
		// Как в Kohana: 'llm.vision_model' — путь внутри файла конфига.
		$parts = explode('.', (string) $name);
		$file = array_shift($parts);

		foreach ([
			'/app/application/config/'.$file.'.php',
			'/app/application/modules/llm/config/'.$file.'.php',
			'/app/application/modules/nj657_alice/config/'.$file.'.php',
		] as $path)
		{
			if (is_file($path))
			{
				$data = require $path;
				$data = is_array($data) ? $data : [];

				foreach ($parts as $part)
				{
					$data = (is_array($data) AND array_key_exists($part, $data)) ? $data[$part] : NULL;
				}

				return is_array($data) ? new Kohana_Config_Group($data) : $data;
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

class Log
{
	const WARNING = 3;
	const ERROR   = 4;
}

class Kohana_Log_Stub
{
	public $messages = [];

	public function add($level, $message, array $values = NULL)
	{
		$this->messages[] = [$level, $message];

		return $this;
	}
}

Kohana::$config = new Config;
Kohana::$log    = new Kohana_Log_Stub;

class Kohana_Exception extends Exception {}

class Log_Plain
{
	public static $events = [];

	public static function event($level, $message, array $values = NULL, array $additional = NULL)
	{
		self::$events[] = [$level, $message];
	}
}

class Database
{
	const SELECT = 0;
	const INSERT = 1;

	public static $instances = [];

	public static function instance($group = 'default') { return new Database; }
	public function query($type, $sql) { return TRUE; }
	public function disconnect() { }
}

class Session
{
	public static function instance() { return new Session; }
	public function id() { return 'stand-session'; }
}

class ORM
{
	public static $saved = [];
	protected $_values = [];

	public static function factory($name) { return new ORM; }
	public function __set($key, $value) { $this->_values[$key] = $value; }
	public function __get($key) { return isset($this->_values[$key]) ? $this->_values[$key] : NULL; }

	public function save()
	{
		self::$saved[] = $this->_values;

		return $this;
	}
}

/** Перехватчик исходящих запросов: сюда смотрим, какая модель реально ушла. */
class CurlRequest
{
	public static $last = NULL;
	public static $calls = [];

	public static function post(string $url, array $data, array $headers = [], int $timeout = 30, string $proxy = null): array
	{
		self::$last = ['url' => $url, 'data' => $data];
		self::$calls[] = self::$last;

		return [
			'model'   => isset($data['model']) ? $data['model'] : '',
			'choices' => [['message' => ['content' => 'ответ модели']]],
		];
	}
}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

// Классы модуля llm: интерфейс, провайдеры, сервис, фабрика, фасад.
require '/app/application/modules/llm/classes/Interface/LlmProvider.php';
require '/app/application/modules/llm/classes/Llm/Provider/Abstract.php';
require '/app/application/modules/llm/classes/Llm/Provider/OpenAICompatible.php';
require '/app/application/modules/llm/classes/Llm/Provider/ToolforgeLocal.php';
require '/app/application/modules/llm/classes/Llm/Provider/Toolforge.php';
require '/app/application/modules/llm/classes/Llm/Service.php';
require '/app/application/modules/llm/classes/Llm/Factory.php';
require '/app/application/modules/llm/classes/Llm.php';

// Сервис навыков Алисы (ask_llm — тот самый вызов с моделью из настроек).
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

/** Модель из последнего ушедшего запроса. */
function sent_model()
{
	return isset(CurlRequest::$last['data']['model']) ? CurlRequest::$last['data']['model'] : '';
}

/** Адрес последнего запроса (по нему видно провайдера). */
function sent_url()
{
	return isset(CurlRequest::$last['url']) ? CurlRequest::$last['url'] : '';
}

$text = [['role' => 'user', 'content' => 'привет']];

// --- 1. Главный регресс: модель из $options, фасад только что создан --------
Llm::chat($text, ['model' => 'openrouter::google/gemini-2.5-flash']);
check('модель из $options доехала до провайдера', sent_model(), 'google/gemini-2.5-flash');
check('провайдер из префикса модели', strpos(sent_url(), 'openrouter.ai') !== FALSE ? 'true' : 'false', 'true');

// --- 2. Второй и третий вызов: синглтон пережил reset() ---------------------
Llm::chat($text, ['model' => 'local::qwen/qwen3.5-9b']);
check('второй вызов: своя модель', sent_model(), 'qwen/qwen3.5-9b');

Llm::chat($text, ['model' => 'openrouter::deepseek/deepseek-v4-flash']);
check('третий вызов: своя модель', sent_model(), 'deepseek/deepseek-v4-flash');
check('третий вызов: провайдер openrouter', strpos(sent_url(), 'openrouter.ai') !== FALSE ? 'true' : 'false', 'true');

// --- 3. Модель без префикса провайдера --------------------------------------
Llm::chat($text, ['model' => 'my-finetuned-model']);
check('модель без префикса ушла как есть', sent_model(), 'my-finetuned-model');

// --- 4. Цепочка Llm::model('провайдер::модель') -----------------------------
Llm::model('openrouter::google/gemini-2.5-flash-lite')->ask('привет');
check('цепочка Llm::model() задаёт модель', sent_model(), 'google/gemini-2.5-flash-lite');

// --- 5. Без модели — модель платформы из конфига ----------------------------
Llm::chat($text);
check('без модели — llm.default_model', sent_model(), 'qwen3-coder-30b-a3b-instruct');
check('без модели — провайдер local', strpos(sent_url(), 'lm-studio:1234') !== FALSE ? 'true' : 'false', 'true');

// --- 6. Картинки без модели — vision_model из конфига ------------------------
$with_image = [[
	'role'    => 'user',
	'content' => [
		['type' => 'text', 'text' => 'что на фото?'],
		// data-URI: провайдер 'local' не должен ничего качать из сети
		['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
	],
]];

Llm::chat($with_image);
check('картинка без модели — llm.vision_model', sent_model(), 'qwen/qwen3.5-9b');

// --- 7. Алиас из llm.model_aliases для модели из $options -------------------
Llm::chat($text, ['model' => 'openrouter::gemini-pro-old']);
check('алиас применился к модели из $options', sent_model(), 'google/gemini-1.5-pro-latest');

// --- 8. Явный провайдер в цепочке важнее модели платформы -------------------
Llm::provider('openrouter')->ask('привет');
check('Llm::provider() — модель провайдера', sent_model(), 'google/gemini-2.5-flash-lite');
check('Llm::provider() — провайдер openrouter', strpos(sent_url(), 'openrouter.ai') !== FALSE ? 'true' : 'false', 'true');

// --- 9. Фасад: ask() и vision() с моделью в опциях ---------------------------
Llm::ask('привет', ['model' => 'openrouter::qwen/qwen3.7-plus']);
check('Llm::ask() с моделью в опциях', sent_model(), 'qwen/qwen3.7-plus');

Llm::vision('data:image/png;base64,iVBORw0KGgo=', 'что на фото?', ['model' => 'openrouter::google/gemini-2.5-flash']);
check('Llm::vision() с моделью в опциях', sent_model(), 'google/gemini-2.5-flash');
check('Llm::vision() с моделью — провайдер openrouter', strpos(sent_url(), 'openrouter.ai') !== FALSE ? 'true' : 'false', 'true');

// --- 10. Навык Алисы: модель из формы навыка ---------------------------------
$skill = [
	'id'         => 7,
	'code'       => 'maindialog1',
	'model'      => 'openrouter::google/gemini-3-flash-preview',
	'temperature' => '0.4',
	'max_tokens' => 512,
];

$out = Service_Nj657_Alice::ask_llm($skill, $text);
check('Алиса: модель навыка ушла в провайдера', sent_model(), 'google/gemini-3-flash-preview');
check('Алиса: температура навыка ушла в провайдера', CurlRequest::$last['data']['temperature'], 0.4);
check('Алиса: лимит токенов ушёл в провайдера', CurlRequest::$last['data']['max_tokens'], 512);
check('Алиса: ответ получен', $out['answer'], 'ответ модели');
check('Алиса: в мета — модель навыка', $out['model'], 'openrouter::google/gemini-3-flash-preview');

// Навык без модели — модель платформы
$skill['model'] = '';
$out = Service_Nj657_Alice::ask_llm($skill, $text);
check('Алиса: навык без модели — модель платформы', sent_model(), 'qwen3-coder-30b-a3b-instruct');
check('Алиса: навык без модели — мета платформы', $out['model'], 'local::qwen3-coder-30b-a3b-instruct');

// --- итог -------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
