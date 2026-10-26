<?php
/**
 * Стенд сервиса декодера JSON (nj664_jsondecode).
 *
 * 09.10.2026 владелец: «Сделай модуль такой же как codebeautify.org/json-decode-online».
 * Значит, разбор обязан узнавать JSON в любом виде, в котором он прилетает:
 *   - как есть (честный JSON не должен портиться догадками);
 *   - экранированный ({\"a\":1}) — самый частый случай из логов;
 *   - URL-кодированный (%7B%22a%22%3A1%7D) — из адреса браузера;
 *   - с HTML-сущностями (&quot;) — из разметки;
 *   - завёрнутый в кавычки — из дампа строки;
 * и честно сообщать, что именно пришлось поправить. Плюс: ошибка с позицией,
 * вид глазами PHP (print_r) и готовый код json_decode().
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/nj664_decode_stand.php
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

// Настоящий сервис декодера.
require '/app/application/modules/nj664_jsondecode/classes/Service/Nj664/Decode.php';

// ------------------------------------------------------------------ проверки

$GLOBALS['ok']   = 0;
$GLOBALS['fail'] = 0;

function check($name, $got, $want)
{
	if ($got === $want)
	{
		$GLOBALS['ok']++;
		echo "ok   {$name}\n";

		return;
	}

	$GLOBALS['fail']++;
	echo "ФЕЙЛ {$name}\n";
	echo "     получили: ".var_export($got, TRUE)."\n";
	echo "     ожидалось: ".var_export($want, TRUE)."\n";
}

function yes($name, $got)
{
	check($name, (bool) $got, TRUE);
}

// ------------------------------------------------------- 1. узнаём вход

$r = Service_Nj664_Decode::decode('{"a":1,"b":[1,2]}');
yes('честный JSON разобран', $r['ok']);
check('честный JSON: режим raw', $r['mode'], Service_Nj664_Decode::MODE_RAW);
check('честный JSON: значение', $r['data']['b'][1], 2);

$r = Service_Nj664_Decode::decode('{\"InsuranceCompanies\":{\"source\":\"investopedia.com\"}}');
yes('экранированный JSON разобран', $r['ok']);
check('экранированный: режим slashes', $r['mode'], Service_Nj664_Decode::MODE_SLASHES);
check('экранированный: значение', $r['data']['InsuranceCompanies']['source'], 'investopedia.com');

$r = Service_Nj664_Decode::decode('%7B%22a%22%3A1%7D');
yes('URL-кодированный разобран', $r['ok']);
check('URL: режим url', $r['mode'], Service_Nj664_Decode::MODE_URL);
check('URL: значение', $r['data']['a'], 1);

$r = Service_Nj664_Decode::decode('{&quot;a&quot;:1}');
yes('HTML-сущности разобраны', $r['ok']);
check('HTML: режим html', $r['mode'], Service_Nj664_Decode::MODE_HTML);
check('HTML: значение', $r['data']['a'], 1);

$r = Service_Nj664_Decode::decode("'{\"a\":1}'");
yes('строка в кавычках разобрана', $r['ok']);
check('кавычки: режим quoted', $r['mode'], Service_Nj664_Decode::MODE_QUOTED);

$r = Service_Nj664_Decode::decode('  {"a":1}  '."\n");
check('пробелы по краям не мешают', $r['ok'], TRUE);

// Экранированный перевод строки внутри строки — частая боль логов
$r = Service_Nj664_Decode::decode('{"text":"первая строка\nвторая строка"}');
check('\n внутри строки развёрнут', $r['data']['text'], "первая строка\nвторая строка");

// Юникод не должен превращаться в \uXXXX
$r = Service_Nj664_Decode::decode('{"name":"Хрущёв"}');
check('юникод не экранирован', Service_Nj664_Decode::beautify($r['data']), "{\n    \"name\": \"Хрущёв\"\n}");

// Честный JSON со слешем внутри не должен портиться
$r = Service_Nj664_Decode::decode('{"path":"C:\\\\temp"}');
check('обратный слеш в данных цел', $r['data']['path'], 'C:\\temp');

// ------------------------------------------------------- 2. ошибки

$r = Service_Nj664_Decode::decode('');
check('пусто: не ок', $r['ok'], FALSE);
yes('пусто: сообщение есть', $r['error'] !== '');

$r = Service_Nj664_Decode::decode('{"a":1,}');
check('лишняя запятая: не ок', $r['ok'], FALSE);
yes('лишняя запятая: сообщение есть', strpos($r['error'], 'Синтаксическая') === 0);

$r = Service_Nj664_Decode::decode("{\n  \"a\": 1\n");
check('незакрытая скобка: не ок', $r['ok'], FALSE);
yes('незакрытая скобка: подсказка про {', strpos(Service_Nj664_Decode::decode("{\n  \"a\": 1\n")['error'], 'Синтаксическая') === 0);

$r = Service_Nj664_Decode::decode(str_repeat('a', 3 * 1024 * 1024));
check('больше 2 МБ: отказ', $r['ok'], FALSE);
yes('больше 2 МБ: про предел сказано', strpos($r['error'], 'МБ') !== FALSE);

// Позиция: битая скобка на второй строке
$r = Service_Nj664_Decode::decode("{\n  \"a\": [1, 2}\n]");
check('битая скобка: строка найдена', $r['line'], 2);
yes('битая скобка: позиция найдена', $r['col'] > 0);

// ------------------------------------------------------- 3. выводы

$data = json_decode('{"No":"1","Name":"Berkshire","nested":{"x":true,"y":null,"z":1.5}}', TRUE);

$pretty = Service_Nj664_Decode::beautify($data);
yes('красиво: с отступами', strpos($pretty, "\n    \"No\": \"1\"") !== FALSE);

$min = Service_Nj664_Decode::minify($data);
check('сжато: одна строка', strpos($min, "\n"), FALSE);
check('сжато: то же самое значение', json_decode($min, TRUE), $data);

// print_r — эталон, с которым сравниваем PHP Array
// print_r() в конце ставит перевод строки — в выводе на странице он лишний,
// поэтому сравниваем с ним, а не требуем его внутри сервиса.
$etalon = print_r($data, TRUE);
check('PHP Array совпал с print_r', Service_Nj664_Decode::php_array($data)."\n", $etalon);

// Вложенность в три уровня и список внутри объекта — самый читаемый вид
$deep = json_decode('{"a":{"b":[1,{"c":"d"}]},"e":[]}', TRUE);
check('PHP Array: глубокая вложенность', Service_Nj664_Decode::php_array($deep)."\n", print_r($deep, TRUE));
check('PHP Array: пустой массив', Service_Nj664_Decode::php_array([]), "Array\n(\n)");

$code = Service_Nj664_Decode::php_code($data);
yes('код: есть json_decode', strpos($code, 'json_decode($json, TRUE)') !== FALSE);
yes('код: есть проверка ошибки', strpos($code, 'json_last_error()') !== FALSE);
yes('код: есть разбор внутри', strpos($code, '[Name] => Berkshire') !== FALSE);
check('код: начинается с <?php', substr($code, 0, 5), '<?php');

// Список, а не объект
$list_r = Service_Nj664_Decode::decode('[1,2,3]');
check('список: корень — список', Service_Nj664_Decode::stats($list_r['data'])['type'], 'массив (список)');
check('список: узлов 4', Service_Nj664_Decode::stats($list_r['data'])['nodes'], 4);
check('список: глубина 1', Service_Nj664_Decode::stats($list_r['data'])['depth'], 1);

$obj = Service_Nj664_Decode::decode('{"a":{"b":{"c":1}}}');
check('объект: корень — объект', Service_Nj664_Decode::stats($obj['data'])['type'], 'объект');
check('объект: глубина 3', Service_Nj664_Decode::stats($obj['data'])['depth'], 3);
check('объект: ключей 3', Service_Nj664_Decode::stats($obj['data'])['keys'], 3);

$num = Service_Nj664_Decode::decode('42');
check('число: корень — число', Service_Nj664_Decode::stats($num['data'])['type'], 'целое число');

$null = Service_Nj664_Decode::decode('null');
check('null разобран', $null['ok'], TRUE);
check('null: корень — null', Service_Nj664_Decode::stats($null['data'])['type'], 'null');

// ------------------------------------------------------- 4. пример и подписи

$sample = Service_Nj664_Decode::decode(Service_Nj664_Decode::sample());
yes('пример разобран', $sample['ok']);
check('пример: режим raw', $sample['mode'], Service_Nj664_Decode::MODE_RAW);
check('пример: есть top', count($sample['data']['InsuranceCompanies']['top']), 2);
check(
	'подпись режима raw',
	Service_Nj664_Decode::mode_label(Service_Nj664_Decode::MODE_RAW),
	'как есть — это уже JSON'
);
yes('подпись неизвестного режима не пустая', Service_Nj664_Decode::mode_label('zzz') !== '');

// ------------------------------------------------------------------ итог

echo "\nитог: {$GLOBALS['ok']} ок, {$GLOBALS['fail']} фейл\n";

if ($GLOBALS['fail'])
{
	throw new Exception('Стенд не прошёл');
}
