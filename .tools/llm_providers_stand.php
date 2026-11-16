<?php
/**
 * Стенд фабрики провайдеров LLM (модуль llm).
 *
 * 06.10.2026 в логе проды: WARNING «Unknown LLM provider configured:
 * embedding_server» при каждом обращении к Llm:: — провайдер сервера
 * эмбеддингов (config/llm.php, отдельный эндпоинт EMBEDDING_API_URL) не был
 * известен Llm_Service, и get_embedding() молча падал назад на 'local'.
 *
 * Проверяет:
 *   - Llm_Service создаёт провайдера embedding_server (без warning'а в лог);
 *   - конфиг llm.php: embedding_model переключается на embedding_server::
 *     только при заданном EMBEDDING_API_URL (иначе — local::);
 *   - карта провайдеров не потеряла openai/openrouter/local.
 *
 *   PRL_ROOT=/path/to/prl.ru node phpwasm_stand.mjs .tools/llm_providers_stand.php
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', dirname(__DIR__).'/system/');
}

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
		foreach ([
			dirname(__DIR__).'/application/config/'.$name.'.php',
			dirname(__DIR__).'/application/modules/llm/config/'.$name.'.php',
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
	public static $environment = 1; // PRODUCTION
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

// Классы модуля llm: интерфейс, провайдеры, сервис.
require dirname(__DIR__).'/application/modules/llm/classes/Interface/LlmProvider.php';
require dirname(__DIR__).'/application/modules/llm/classes/Llm/Provider/Abstract.php';
require dirname(__DIR__).'/application/modules/llm/classes/Llm/Provider/OpenAICompatible.php';
require dirname(__DIR__).'/application/modules/llm/classes/Llm/Provider/ToolforgeLocal.php';
require dirname(__DIR__).'/application/modules/llm/classes/Llm/Provider/Toolforge.php';
require dirname(__DIR__).'/application/modules/llm/classes/Llm/Service.php';

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

$providers = [
	'local' => ['api_key' => 'k', 'base_url' => 'http://lm', 'default_model' => 'm1', 'timeout' => 5, 'params' => []],
	'openai' => ['api_key' => 'k', 'base_url' => 'https://api', 'default_model' => 'gpt', 'timeout' => 5, 'params' => []],
	'embedding_server' => ['api_key' => 'k', 'base_url' => 'http://emb', 'default_model' => 'emb-model', 'timeout' => 5, 'params' => []],
];

Kohana::$log = new Kohana_Log_Stub;
$service = new Llm_Service('local', $providers, []);

check('embedding_server создан без warning', Kohana::$log->messages, []);

// provider() цепочечный (возвращает сервис), карту провайдеров читаем рефлексией
$ref = new ReflectionProperty('Llm_Service', '_providers');
$ref->setAccessible(TRUE);
$map = $ref->getValue($service);

check('провайдер embedding_server есть', isset($map['embedding_server']) ? 'true' : 'false', 'true');
check('embedding_server — Toolforge (умеет embeddings)', $map['embedding_server'] instanceof Llm_Provider_Toolforge ? 'true' : 'false', 'true');
check('у него дефолтная модель из конфига', $map['embedding_server']->getDefaultModel(), 'emb-model');
check('openai тоже на месте', isset($map['openai']) ? 'true' : 'false', 'true');

Kohana::$log = new Kohana_Log_Stub;
$service2 = new Llm_Service('local', [
	'local' => $providers['local'],
	'какой-то-мусор' => [],
], []);
check('неизвестный провайдер по-прежнему ругается', count(Kohana::$log->messages), 1);

// --- конфиг: embedding_model переключается по EMBEDDING_API_URL --------------
putenv('EMBEDDING_API_URL=');
putenv('EMBEDDING_MODEL=');
$cfg = require dirname(__DIR__).'/application/config/llm.php';
check('конфиг без EMBEDDING_API_URL → local::', $cfg['embedding_model'], 'local::text-embedding-qwen3-embedding-0.6b');

putenv('EMBEDDING_API_URL=http://emb:7999');
$cfg = require dirname(__DIR__).'/application/config/llm.php';
check('конфиг с EMBEDDING_API_URL → embedding_server::', $cfg['embedding_model'], 'embedding_server::text-embedding-qwen3-embedding-0.6b');

putenv('EMBEDDING_MODEL=my-embed-v2');
$cfg = require dirname(__DIR__).'/application/config/llm.php';
check('EMBEDDING_MODEL переопределяет модель', $cfg['embedding_model'], 'embedding_server::my-embed-v2');

putenv('EMBEDDING_MODEL=');
putenv('EMBEDDING_API_URL=');

// --- итог -------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
