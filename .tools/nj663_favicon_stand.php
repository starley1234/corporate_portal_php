<?php
/**
 * Стенд nj663_favicon («Генератор favicon»): чистые функции сервиса.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/nj663_favicon_stand.php
 *
 * GD в php-wasm нет, поэтому стенд проверяет то, что можно проверить без
 * рисования — и это ровно те места, где ошибка не видна глазом:
 *   - размеры и состав комплекта (какой файл какого размера);
 *   - цвет и путь сайта (в сниппет не должен утечь хост или «..»);
 *   - HTML-сниппет и webmanifest;
 *   - белый список имён файлов: traversal наружу не проходит;
 *   - код набора: свой алфавит, угадывать нечего;
 *   - СТРУКТУРА favicon.ico побайтово: заголовок, каталог, BGRA снизу вверх
 *     (перепутать порядок строк — классика: иконка выходит перевёрнутой);
 *   - разбор опций запроса (composed, пустые поля формы).
 */

if ( ! defined('SYSPATH'))
{
	define('SYSPATH', '/app/system/');
}

if ( ! defined('DOCROOT'))
{
	define('DOCROOT', '/app/public_html/');
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
		$path = '/app/application/modules/nj663_favicon/config/'.$name.'.php';

		if (is_file($path))
		{
			$data = require $path;

			return new Kohana_Config_Group(is_array($data) ? $data : []);
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

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj663_favicon/classes/Service/Nj663/Favicon.php';

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

$sizes = Service_Nj663_Favicon::sizes();

// --- размеры и состав комплекта ----------------------------------------------
check('базовый размер', Service_Nj663_Favicon::base_size(), 512);
check('размеры по возрастанию', $sizes === array_values(array_unique($sizes)) ? 'true' : 'false', 'true');
check('в наборе есть 16', in_array(16, $sizes, TRUE) ? 'true' : 'false', 'true');
check('в наборе есть 512', in_array(512, $sizes, TRUE) ? 'true' : 'false', 'true');
check('размеры внутри ico', implode(',', Service_Nj663_Favicon::ico_sizes()), '16,32,48');

$targets = Service_Nj663_Favicon::targets();
check('имя файла по размеру', $targets['favicon-32x32.png'], 32);
check('apple-touch-icon = 180', $targets['apple-touch-icon.png'], 180);
check('android-chrome-192 = 192', $targets['android-chrome-192x192.png'], 192);
check('android-chrome-512 = 512', $targets['android-chrome-512x512.png'], 512);
check('все размеры из конфига получили файл', count($targets), count($sizes) + 3);

// --- белый список имён (безопасность выдачи) ----------------------------------
check('favicon.ico разрешён', Service_Nj663_Favicon::safe_file_name('favicon.ico'), 'favicon.ico');
check('site.webmanifest разрешён', Service_Nj663_Favicon::safe_file_name('site.webmanifest'), 'site.webmanifest');
check('zip разрешён', Service_Nj663_Favicon::safe_file_name('favicon-pack.zip'), 'favicon-pack.zip');
check('favicon-16x16.png разрешён', Service_Nj663_Favicon::safe_file_name('favicon-16x16.png'), 'favicon-16x16.png');
check('traversal наружу запрещён', Service_Nj663_Favicon::safe_file_name('../../../etc/passwd'), NULL);
check('точка-точка в имени запрещена', Service_Nj663_Favicon::safe_file_name('..%2f.nj663'), NULL);
check('чужое имя запрещено', Service_Nj663_Favicon::safe_file_name('index.php'), NULL);
check('пусто запрещено', Service_Nj663_Favicon::safe_file_name(''), NULL);

// --- цвет ----------------------------------------------------------------------
check('#abc разворачивается', Service_Nj663_Favicon::normalize_color('#abc'), '#aabbcc');
check('регистр не важен', Service_Nj663_Favicon::normalize_color('#ABCDEF'), '#abcdef');
check('без решётки', Service_Nj663_Favicon::normalize_color('4f46e5'), '#4f46e5');
check('мусор → умолчание', Service_Nj663_Favicon::normalize_color('red'), '');
check('пусто → умолчание', Service_Nj663_Favicon::normalize_color('', '#ffffff'), '#ffffff');
check('rgb_of', implode(',', Service_Nj663_Favicon::rgb_of('#4f46e5')), '79,70,229');

// --- путь сайта (в сниппет не должен утечь хост) --------------------------------
check('пустой путь', Service_Nj663_Favicon::normalize_base_path(''), '/');
check('без слешей добавляет', Service_Nj663_Favicon::normalize_base_path('icons'), '/icons/');
check('хост отбрасывается', Service_Nj663_Favicon::normalize_base_path('https://site.ru/assets/icons'), '/assets/icons/');
check('двойные слеши схлопываются', Service_Nj663_Favicon::normalize_base_path('/a//b'), '/a/b/');
check('хост из protocol-relative отбрасывается', Service_Nj663_Favicon::normalize_base_path('//cdn.example.com/icons'), '/icons/');
check('«..» вырезается', Service_Nj663_Favicon::normalize_base_path('/a/../../b'), '/a/b/');

// --- HTML-сниппет ---------------------------------------------------------------
$html = Service_Nj663_Favicon::html_snippet('/assets/icons/', '#4f46e5');
check_true('сниппет: apple-touch-icon', strpos($html, 'rel="apple-touch-icon"') !== FALSE);
check_true('сниппет: путь сайта соблюдён', strpos($html, 'href="/assets/icons/favicon-32x32.png"') !== FALSE);
check_true('сниппет: 16×16', strpos($html, 'favicon-16x16.png') !== FALSE);
check_true('сниппет: manifest', strpos($html, 'href="/assets/icons/site.webmanifest"') !== FALSE);
check_true('сниппет: ico', strpos($html, 'href="/assets/icons/favicon.ico"') !== FALSE);
check_true('сниппет: theme-color', strpos($html, '<meta name="theme-color" content="#4f46e5">') !== FALSE);
check('сниппет без цвета — без theme-color',
	strpos(Service_Nj663_Favicon::html_snippet('/'), 'theme-color') === FALSE ? 'true' : 'false', 'true');

// --- webmanifest ---------------------------------------------------------------
$manifest = Service_Nj663_Favicon::manifest_json('/', 'Мой сайт', '#4f46e5');
$decoded  = json_decode($manifest, TRUE);
check('манифест — валидный JSON', is_array($decoded) ? 'true' : 'false', 'true');
check('манифест: икон 2', count($decoded['icons']), 2);
check('манифест: первая икона', $decoded['icons'][0]['src'], '/android-chrome-192x192.png');
check('манифест: размеры', $decoded['icons'][1]['sizes'], '512x512');
check('манифест: theme_color', $decoded['theme_color'], '#4f46e5');

// --- побайтовая структура favicon.ico -------------------------------------------
// Кадр 2×2: строка сверху (1,2,3,255) (4,5,6,0); строка снизу (7,8,9,128) (10,11,12,255).
$rgba = chr(1).chr(2).chr(3).chr(255)
	. chr(4).chr(5).chr(6).chr(0)
	. chr(7).chr(8).chr(9).chr(128)
	. chr(10).chr(11).chr(12).chr(255);

$ico = Service_Nj663_Favicon::ico_bytes([['size' => 2, 'rgba' => $rgba]]);

// 6 (ICONDIR) + 16 (каталог) + 40 (DIB) + 16 (XOR) + 8 (AND-маска) = 86
check('ico: длина', strlen($ico), 86);
check('ico: заголовок (reserved, type=1, count=1)', bin2hex(substr($ico, 0, 6)), '000001000100');
check('ico: ширина в каталоге', ord($ico[6]), 2);
check('ico: высота в каталоге', ord($ico[7]), 2);
check('ico: бит на пиксель', implode(',', array_values(unpack('v', substr($ico, 12, 2)))), '32');

$dib = unpack('Vsize/lwidth/lheight/vplanes/vbits/Vcompression/Vbytes', substr($ico, 22, 40));
check('ico: DIB size', $dib['size'], 40);
check('ico: DIB width', $dib['width'], 2);
check('ico: DIB height (XOR + AND)', $dib['height'], 4);
check('ico: DIB planes', $dib['planes'], 1);
check('ico: DIB bitcount', $dib['bits'], 32);
check('ico: DIB sizeImage (XOR + маска)', $dib['bytes'], 24);

// Строки идут СНИЗУ ВВЕРХ, пиксели — BGRA.
$xor = substr($ico, 62, 16);
check('ico: первый пиксель XOR — левый нижний, BGRA', bin2hex(substr($xor, 0, 4)), bin2hex(chr(9).chr(8).chr(7).chr(128)));
check('ico: второй пиксель нижней строки', bin2hex(substr($xor, 4, 4)), bin2hex(chr(12).chr(11).chr(10).chr(255)));
check('ico: первый пиксель верхней строки — в конце', bin2hex(substr($xor, 8, 4)), bin2hex(chr(3).chr(2).chr(1).chr(255)));
check('ico: альфа прозрачного пикселя сохранена', ord($xor[15]), 0);

// Несколько кадров: каталог растёт, смещения ведут на свои кадры.
$two = Service_Nj663_Favicon::ico_bytes([
	['size' => 2, 'rgba' => $rgba],
	['size' => 1, 'rgba' => chr(1).chr(2).chr(3).chr(255)],
]);
check('ico: два кадра — счётчик', implode(',', array_values(unpack('v', substr($two, 4, 2)))), '2');
check('ico: два кадра — длина каталога и данных', strlen($two), 6 + 32 + (40 + 16 + 8) + (40 + 4 + 4));

check('ico: битый кадр (не та длина) отброшен', Service_Nj663_Favicon::ico_bytes([['size' => 2, 'rgba' => 'abc']]), '');
check('ico: пустой вход — пустой выход', Service_Nj663_Favicon::ico_bytes([]), '');
check('ico: размер 257 не пройдёт', Service_Nj663_Favicon::ico_bytes([['size' => 257, 'rgba' => $rgba]]), '');

// --- код набора ------------------------------------------------------------------
$code = Service_Nj663_Favicon::new_code();
check('код: длина', strlen($code), 10);
check('код: свой шаблон', Service_Nj663_Favicon::is_code($code) ? 'true' : 'false', 'true');
check('код: коды не повторяются', Service_Nj663_Favicon::new_code() === Service_Nj663_Favicon::new_code() ? 'false' : 'true', 'true');
check('код: заглавные буквы не пройдут', Service_Nj663_Favicon::is_code('ABCdefghij') ? 'true' : 'false', 'false');
check('код: короткий не пройдёт', Service_Nj663_Favicon::is_code('abc') ? 'true' : 'false', 'false');
check('код: traversal не пройдёт', Service_Nj663_Favicon::is_code('../../etc') ? 'true' : 'false', 'false');

// --- мелочи ----------------------------------------------------------------------
check('clamp: сверху', Service_Nj663_Favicon::clamp_int(999, 0, 50, 0), 50);
check('clamp: снизу', Service_Nj663_Favicon::clamp_int(-5, 0, 50, 0), 0);
check('clamp: не число — умолчание', Service_Nj663_Favicon::clamp_int('abc', 0, 50, 7), 7);
check('media: png', Service_Nj663_Favicon::media_type('favicon-32x32.png'), 'image/png');
check('media: ico', Service_Nj663_Favicon::media_type('favicon.ico'), 'image/x-icon');
check('media: zip', Service_Nj663_Favicon::media_type('favicon-pack.zip'), 'application/zip');
check('media: manifest', Service_Nj663_Favicon::media_type('site.webmanifest'), 'application/manifest+json');
check('ссылка на файл', Service_Nj663_Favicon::file_url('abc2345678', 'favicon.ico'), '/favicon-gen/f/abc2345678/favicon.ico');
check('ссылка на набор', Service_Nj663_Favicon::pack_url('abc2345678'), '/favicon-gen/p/abc2345678');
check('ссылка на чужие имена пуста', Service_Nj663_Favicon::file_url('abc2345678', 'index.php'), '');

// --- разбор опций запроса ---------------------------------------------------------
$opt = Service_Nj663_Favicon::options_from_post([
	'kind'      => 'text',
	'text'      => '🚀',
	'bg'        => '#4f46e5',
	'bg_alpha'  => '1',
	'radius'    => '30',
	'base_path' => '/icons',
]);

check('опции: вид', $opt['kind'], 'text');
check('опции: текст', $opt['text'], '🚀');
check('опции: composed выключен по умолчанию', $opt['composed'] ? 'true' : 'false', 'false');
check('опции: прозрачный фон из формы', $opt['bg_alpha'] ? 'true' : 'false', 'true');
check('опции: радиус проброшен', $opt['radius'], '30');
check('опции: непустой путь нормализован', $opt['base_path'], '/icons');
check('опции: пустой pad не подменяется нулём', array_key_exists('pad', $opt) ? 'true' : 'false', 'false');
check('опции: мусорный fit отброшен',
	array_key_exists('fit', Service_Nj663_Favicon::options_from_post(['fit' => 'угадай'])) ? 'true' : 'false', 'false');
check('опции: composed=true проброшен',
	Service_Nj663_Favicon::options_from_post(['composed' => TRUE])['composed'] ? 'true' : 'false', 'true');

// Размер символа. Верхний предел поднят (владелец, 09.10.2026: «верхний
// предел надо делать больше»): раньше символ упирался в 95 % стороны и букву
// во весь квадрат было не поставить. Предел живёт в конфиге — ползунок во
// вьюхе и приёмка запроса обязаны читать одно и то же число, иначе сервер
// молча срежет крупный символ, выбранный человеком.
$_options = new ReflectionMethod('Service_Nj663_Favicon', '_options');
$_options->setAccessible(TRUE);

$share_max = (int) Arr::get(Service_Nj663_Favicon::limits(), 'font_share_max', 0);
$share_min = (int) Arr::get(Service_Nj663_Favicon::limits(), 'font_share_min', 0);

check('опции: крупный символ не срезается',
	(int) $_options->invoke(NULL, ['font_share' => $share_max])['font_share'], $share_max);
check('опции: символ не выходит за предел конфига',
	(int) $_options->invoke(NULL, ['font_share' => 99999])['font_share'], $share_max);
check('опции: ниже нижнего предела не уходит',
	(int) $_options->invoke(NULL, ['font_share' => -50])['font_share'], $share_min);
check_true('предел размера символа больше прежних 95 % стороны', $share_max > 95);
check_true('верхний предел — не меньше «во весь квадрат» (140 %)', $share_max >= 140);

// =============================================================================
// Сквозная сборка: GD + диск + «база». Работает, только если в PHP есть GD
// (в php-wasm он есть; в песочницах без GD эта часть пропускается).
// =============================================================================
if (function_exists('imagecreatetruecolor') AND function_exists('imagepng'))
{
	echo "\n-- сборка комплекта (GD) --\n";

	class Database
	{
		const SELECT = 0;
		const INSERT = 1;
		const UPDATE = 2;
		const DELETE = 3;

		public static $queries = [];

		public static function instance($group = NULL)
		{
			return new Database;
		}

		public function quote($value)
		{
			return "'".str_replace("'", "''", (string) $value)."'";
		}

		public function query($type, $sql, $as_object = NULL)
		{
			self::$queries[] = [$type, $sql];

			// INSERT … RETURNING id — журнал ждёт строку с id.
			if (strpos($sql, 'RETURNING id') !== FALSE)
			{
				return new Db_Result([['id' => 1]]);
			}

			return new Db_Result([]);
		}
	}

	class Db_Result
	{
		protected $_rows;

		public function __construct(array $rows) { $this->_rows = $rows; }
		public function current() { return $this->_rows ? $this->_rows[0] : FALSE; }
		public function as_array() { return $this->_rows; }
	}

	/** Квадрат 64×64, полностью непрозрачный красный — по нему видно скругление. */
	function nj663_square()
	{
		$im = imagecreatetruecolor(64, 64);
		imagealphablending($im, FALSE);
		imagesavealpha($im, TRUE);
		imagefilledrectangle($im, 0, 0, 63, 63, imagecolorallocatealpha($im, 220, 40, 40, 0));

		ob_start();
		imagepng($im);
		$png = (string) ob_get_clean();
		imagedestroy($im);

		return $png;
	}

	$src = nj663_square();

	$made = Service_Nj663_Favicon::generate($src, [
		'kind'      => 'text',
		'text'      => 'N',
		'title'     => 'Тест',
		'bg'        => '#4f46e5',
		'radius'    => 40,
		'pad'       => 10,
		'base_path' => '/i/',
	]);

	check('сборка: ok', $made['ok'] ? 'true' : 'false', 'true');
	check('сборка: ошибки нет', $made['error'], '');
	check('сборка: код выдан', Service_Nj663_Favicon::is_code($made['code']) ? 'true' : 'false', 'true');

	$names = [];

	foreach ($made['files'] as $file)
	{
		$names[] = $file['name'];

		$path = Service_Nj663_Favicon::file_path($made['code'], $file['name']);
		check('сборка: файл на диске '.$file['name'], $path !== NULL ? 'true' : 'false', 'true');

		if ($path !== NULL)
		{
			check('сборка: размер файла '.$file['name'], filesize($path) > 0 ? 'true' : 'false', 'true');
		}
	}

	check('сборка: есть favicon.ico', in_array('favicon.ico', $names, TRUE) ? 'true' : 'false', 'true');
	check('сборка: есть site.webmanifest', in_array('site.webmanifest', $names, TRUE) ? 'true' : 'false', 'true');
	check('сборка: есть zip', in_array('favicon-pack.zip', $names, TRUE) ? 'true' : 'false', 'true');
	check('сборка: есть apple-touch-icon', in_array('apple-touch-icon.png', $names, TRUE) ? 'true' : 'false', 'true');
	check('сборка: путь сайта в HTML', strpos($made['html'], 'href="/i/favicon-32x32.png"') !== FALSE ? 'true' : 'false', 'true');

	// --- размеры PNG ---------------------------------------------------------
	foreach ([16 => 'favicon-16x16.png', 32 => 'favicon-32x32.png', 180 => 'apple-touch-icon.png', 512 => 'favicon-512x512.png'] as $size => $name)
	{
		$path = Service_Nj663_Favicon::file_path($made['code'], $name);
		$info = $path ? @getimagesize($path) : FALSE;

		check('сборка: '.$name.' — '.$size.'×'.$size,
			is_array($info) ? $info[0].'x'.$info[1] : 'нет файла', $size.'x'.$size);
	}

	// --- ICO: три кадра нужных размеров --------------------------------------
	$ico_path = Service_Nj663_Favicon::file_path($made['code'], 'favicon.ico');
	$ico      = $ico_path ? (string) file_get_contents($ico_path) : '';

	check('ico: заголовок файла', bin2hex(substr($ico, 0, 4)), '00000100');
	check('ico: три кадра', implode(',', array_values(unpack('v', substr($ico, 4, 2)))), '3');

	$ico_sizes = [];
	$offset    = 6;

	for ($i = 0; $i < 3; $i++)
	{
		$ico_sizes[] = ord($ico[$offset]);
		$offset     += 16;
	}

	check('ico: размеры кадров', implode(',', $ico_sizes), '16,32,48');

	// --- zip ------------------------------------------------------------------
	if (class_exists('ZipArchive'))
	{
		$zip_path = Service_Nj663_Favicon::file_path($made['code'], 'favicon-pack.zip');
		$zip      = new ZipArchive;
		$opened   = $zip_path ? $zip->open($zip_path) : FALSE;

		check('zip: открылся', $opened === TRUE ? 'true' : 'false', 'true');

		if ($opened === TRUE)
		{
			check('zip: png внутри', $zip->numFiles >= count(Service_Nj663_Favicon::targets()) ? 'true' : 'false', 'true');
			check('zip: демо-страница внутри', $zip->getFromName('index.html') !== FALSE ? 'true' : 'false', 'true');
			check('zip: в демо-странице есть HTML-код',
				strpos((string) $zip->getFromName('index.html'), 'rel="apple-touch-icon"') !== FALSE ? 'true' : 'false', 'true');
			$zip->close();
		}
	}

	// --- журнал ---------------------------------------------------------------
	$saved = FALSE;

	foreach (Database::$queries as $query)
	{
		if (strpos($query[1], 'INSERT INTO nj_663_packs') !== FALSE AND strpos($query[1], $made['code']) !== FALSE)
		{
			$saved = TRUE;
		}
	}

	check('журнал: запись о наборе', $saved ? 'true' : 'false', 'true');

	// --- composed: браузер уже всё собрал -------------------------------------
	// Исходник — сплошной квадрат. Без composed сервер скруглит углы (угловой
	// пиксель станет прозрачным), с composed — оставит как есть.
	function nj663_corner($code)
	{
		$path = Service_Nj663_Favicon::file_path($code, 'favicon-512x512.png');

		if ($path === NULL)
		{
			return NULL;
		}

		$im = @imagecreatefromstring((string) file_get_contents($path));

		if ( ! $im)
		{
			return NULL;
		}

		$c = imagecolorat($im, 0, 0);
		imagedestroy($im);

		return (($c >> 24) & 0x7F);   // 127 = полностью прозрачный
	}

	$plain = Service_Nj663_Favicon::generate(nj663_square(), ['radius' => 40, 'pad' => 0]);
	check('composed: без флага углы скруглены', nj663_corner($plain['code']), 127);

	$canvas = Service_Nj663_Favicon::generate(nj663_square(), ['radius' => 40, 'pad' => 0, 'composed' => TRUE]);
	check('composed: с флагом углы НЕ трогаем', nj663_corner($canvas['code']), 0);

	// --- отказы ----------------------------------------------------------------
	$empty = Service_Nj663_Favicon::generate('', []);
	check('отказ: пустой исходник', $empty['ok'] ? 'true' : 'false', 'false');
	check('отказ: текст ошибки есть', $empty['error'] !== '' ? 'true' : 'false', 'true');

	$junk = Service_Nj663_Favicon::generate('это не картинка', []);
	check('отказ: мусор вместо картинки', $junk['ok'] ? 'true' : 'false', 'false');

	// --- уборка ----------------------------------------------------------------
	$dir = dirname((string) Service_Nj663_Favicon::file_path($made['code'], 'favicon.ico'));
	Service_Nj663_Favicon::delete_dir($dir);
	check('уборка: каталог набора удалён', is_dir($dir) ? 'true' : 'false', 'false');
	check('уборка: файл больше не выдаётся',
		Service_Nj663_Favicon::file_path($made['code'], 'favicon.ico') === NULL ? 'true' : 'false', 'true');
}

// --- итог -------------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
