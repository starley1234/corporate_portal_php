<?php
/**
 * Дамп страницы «Декодер JSON» (nj664_jsondecode) для UI-стенда.
 *
 * Отдаёт ровно тот HTML, что уезжает в браузер: настоящая вьюха
 * `views/664/index.php` вместе с `views/664/script.php`, настоящий сервис,
 * а Kohana заменена короткими заглушками (View, HTML, Arr, Security).
 *
 *   N2J_ROOT=/path/to/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj664_decode_dump.php
 *
 * Стенд сам по себе ничего не проверяет — его вывод забирает
 * `.tools/nj664_decode_ui.mjs` и прогоняет в jsdom.
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

define('APPPATH', '/app/application/');

// ---------------------------------------------------------------- заглушки

class Arr
{
	public static function get($array, $key, $default = NULL)
	{
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

class Security
{
	public static function token()
	{
		return 'stand-token';
	}
}

class View
{
	/** Путь вьюхи относительно каталога views модуля */
	public $file = '';

	/** Данные, которые увидит вьюха */
	public $data = [];

	public static function factory($file, array $data = NULL)
	{
		return new View($file, $data === NULL ? [] : $data);
	}

	public function __construct($file, array $data)
	{
		$this->file = $file;
		$this->data = $data;
	}

	public function __toString()
	{
		extract($this->data);

		ob_start();
		include '/app/application/modules/nj664_jsondecode/views/'.$this->file.'.php';

		return (string) ob_get_clean();
	}
}

// Настоящий сервис декодера.
require '/app/application/modules/nj664_jsondecode/classes/Service/Nj664/Decode.php';

// ------------------------------------------------------------------- ввод

// Стенд может попросить страницу с уже разобранным входом (режим без JS):
//   N2J_INPUT='{"a":1}' N2J_MODE=array node phpwasm_stand.mjs …dump.php
$input = (string) (getenv('N2J_INPUT') ?: '');
$mode  = (string) (getenv('N2J_MODE') ?: 'json');
$sent  = $input !== '';

$result = $sent ? Service_Nj664_Decode::decode($input) : NULL;

// ------------------------------------------------------------------ вывод

echo View::factory('664/index', [
	'input'  => $input,
	'mode'   => $mode,
	'result' => $result,
	'sent'   => $sent,
	'csrf'   => Security::token(),
]);
