<?php
/**
 * Стенд nj666_multivoice («Многоголосая озвучка»): чистые функции сервиса.
 *
 *   N2J_ROOT=$(pwd) node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj666_multivoice_stand.php
 *
 * Проверяется то, что нельзя увидеть глазами и что легче всего сломать:
 *   - склейка персонажей: «Пётр Иванович» и «пётр иванович!» — один человек;
 *   - прикидки длины: символы ↔ минуты ↔ границы блока;
 *   - нарезка блока на запросы: реплики целиком, длинная реплика — по фразам,
 *     склейка звука (wav с пересборкой шапки, mp3 — конкатенацией);
 *   - разбор реплик блока и подстановка голосов: рассказчик, удалённый
 *     персонаж, пустой голос книги;
 *   - имена файлов и глав: русские названия, запрещённые символы, длина.
 *
 * Сети и БД в стенде нет: методы, которые до них дотягиваются, обязаны
 * деградировать, а не падать.
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
	/** Ищем конфиг в своём модуле, потом в nj665 (nj666 пользуется им напрямую). */
	public function load($name)
	{
		foreach ([
			'/app/application/modules/nj666_multivoice/config/'.$name.'.php',
			'/app/application/modules/nj665_voice/config/'.$name.'.php',
			'/app/application/config/'.$name.'.php',
		] as $path)
		{
			if (is_file($path))
			{
				$data = require $path;

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
	public static $charset = 'UTF-8';

	/** Ищем файл в своём модуле, потом в nj665 и в приложении. */
	public static function find_file($dir, $file, $ext = 'php')
	{
		foreach ([
			'/app/application/modules/nj666_multivoice/'.trim($dir, '/').'/'.$file.'.'.$ext,
			'/app/application/modules/nj665_voice/'.trim($dir, '/').'/'.$file.'.'.$ext,
			'/app/application/'.trim($dir, '/').'/'.$file.'.'.$ext,
		] as $path)
		{
			if (is_file($path)) { return $path; }
		}

		return FALSE;
	}
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

class UTF8
{
	public static function substr($str, $offset, $length = NULL)
	{
		return $length === NULL ? mb_substr($str, $offset) : mb_substr($str, $offset, $length);
	}

	public static function strlen($str)
	{
		return mb_strlen($str);
	}

	public static function strpos($haystack, $needle, $offset = 0)
	{
		return mb_strpos($haystack, $needle, $offset);
	}

	public static function strrpos($haystack, $needle, $offset = 0)
	{
		return mb_strrpos($haystack, $needle, $offset);
	}

	/** Заглушка: в стенде системный файл utf8 не подключаем. */
	public static function transliterate_to_ascii($str, $case = 0)
	{
		$map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
			'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
			'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
			'с' => 's', 'т' => 'т', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
			'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
			'э' => 'e', 'ю' => 'yu', 'я' => 'ya', ' ' => '-'];

		return strtr(mb_strtolower($str), $map);
	}
}

class Database
{
	const SELECT = 1;
	const INSERT = 2;

	public static function instance()
	{
		return new Database;
	}

	public function quote($value)
	{
		return "'".str_replace("'", "''", (string) $value)."'";
	}

	/** В стенде БД нет: всё, что до неё дотягивается, должно это пережить. */
	public function query($type, $sql, $as_object = NULL)
	{
		throw new Exception('БД недоступна');
	}
}

class HTML
{
	public static function chars($value, $double_encode = TRUE)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
	}
}

class Visitor
{
	public static function uid()
	{
		return 'stand-visitor';
	}
}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

class Security
{
	public static function token($new = FALSE) { return 'stand-csrf-token'; }

	public static function check($token) { return $token === 'stand-csrf-token'; }
}

class Date
{
	public static function formatted_time($time, $format = 'd.m.Y H:i')
	{
		$stamp = is_numeric($time) ? (int) $time : strtotime((string) $time);

		return $stamp ? date($format, $stamp) : (string) $time;
	}
}

require '/app/system/classes/Kohana/View.php';

class View extends Kohana_View {}

// nj665 нужен целиком: nj666 зовёт его чистые функции (текст, форматы, голоса).
require '/app/application/modules/nj665_voice/classes/Service/Nj665/Voice.php';
require '/app/application/modules/nj666_multivoice/classes/Service/Nj666/Multivoice.php';

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

/** Книга для стенда: только те поля, что читают чистые функции. */
function nj666_book(array $opt = [])
{
	return $opt + [
		'id'            => 7,
		'code'          => 'ab34cd56ef78',
		'title'         => 'Мастер и Маргарита',
		'author'        => 'Михаил Булгаков',
		'mode'          => 'auto',
		'model'         => '',
		'format'        => 'mp3',
		'speed'         => 1.0,
		'default_voice' => 'morfius',
		'language'      => 'ru',
		'block_min_min' => 7.0,
		'block_max_min' => 15.0,
		'single_block'  => 0,
		'chars'         => 12000,
	];
}

// --- персонажи: защита от двойников ---------------------------------------------
check('name_key: регистр не важен',
	Service_Nj666_Multivoice::name_key('Пётр Иванович'),
	Service_Nj666_Multivoice::name_key('пётр иванович'));
check('name_key: ё = е',
	Service_Nj666_Multivoice::name_key('Пётр'), Service_Nj666_Multivoice::name_key('Петр'));
check('name_key: знаки и лишние пробелы убраны',
	Service_Nj666_Multivoice::name_key('  Маргарита!..  '), 'маргарита');
check('name_key: кавычки не мешают',
	Service_Nj666_Multivoice::name_key('«Кот Бегемот»'),
	Service_Nj666_Multivoice::name_key('Кот Бегемот'));
check('name_key: пустое имя', Service_Nj666_Multivoice::name_key('   '), '');
check('name_key: принц (голос) = принц',
	Service_Nj666_Multivoice::name_key('Принц (голос)'),
	Service_Nj666_Multivoice::name_key('Принц'));
check('name_key: меньшая дочь (голос 2)',
	Service_Nj666_Multivoice::name_key('Меньшая дочь (голос 2)'),
	'меньшая дочь');
check('name_key: (голос 2, новая)',
	Service_Nj666_Multivoice::name_key('Меньшая дочь (голос 2, новая)'),
	'меньшая дочь');
check('clean_name: хвост срезан',
	Service_Nj666_Multivoice::clean_character_name('Принц (голос 2, новая)'), 'Принц');
check('clean_name: обычное имя',
	Service_Nj666_Multivoice::clean_character_name('Настенька'), 'Настенька');
check('prefer: короткий без хвоста',
	Service_Nj666_Multivoice::_prefer_character(
		['id' => 2, 'name' => 'Принц (голос)', 'locked' => 0, 'mentions' => 1],
		['id' => 1, 'name' => 'Принц', 'locked' => 0, 'mentions' => 1]
	) > 0, TRUE);
check('рассказчик: канон', Service_Nj666_Multivoice::is_narrator_name('Рассказчик'), TRUE);
check('рассказчик: narrator', Service_Nj666_Multivoice::is_narrator_name('narrator'), TRUE);
check('рассказчик: (голос 1)', Service_Nj666_Multivoice::is_narrator_name('Рассказчик (голос 1)'), TRUE);
check('рассказчик: (Рассказчик)', Service_Nj666_Multivoice::is_narrator_name('Рассказчик (Рассказчик)'), TRUE);
check('рассказчик: купец не он', Service_Nj666_Multivoice::is_narrator_name('Купец'), FALSE);
check('рассказчик: пустое имя не он', Service_Nj666_Multivoice::is_narrator_name('  '), FALSE);

$row = ['kind' => 'character', 'name' => 'Настенька', 'voice' => 'anna', 'locked' => 1];
$g = Service_Nj666_Multivoice::character_updates($row, ['gender' => 'female', 'csrf' => 'x', 'code' => 'ab', 'id' => 9]);
check('правка пола: ok', Arr::get($g, 'ok'), TRUE);
check('правка пола: только gender', array_keys(Arr::get($g, 'set', [])), ['gender']);
check('правка пола: female', Arr::get(Arr::get($g, 'set', []), 'gender'), 'female');

$v = Service_Nj666_Multivoice::character_updates($row, ['voice' => 'morfius']);
check('правка голоса не трогает замок', array_keys(Arr::get($v, 'set', [])), ['voice']);
check('правка голоса не fold', empty($v['fold']), TRUE);

$n = Service_Nj666_Multivoice::character_updates($row, ['name' => 'Настя']);
check('правка имени не затирает голос', array_key_exists('voice', Arr::get($n, 'set', [])), FALSE);
check('правка имени меняет key', Arr::get(Arr::get($n, 'set', []), 'key'), 'настя');

$nar = Service_Nj666_Multivoice::character_updates(
	['kind' => 'narrator', 'name' => 'Рассказчик'], ['name' => 'Другое', 'voice' => 'morfius']);
check('имя рассказчика не меняется', array_key_exists('name', Arr::get($nar, 'set', [])), FALSE);
check('голос рассказчика меняется', Arr::get(Arr::get($nar, 'set', []), 'voice'), 'morfius');

$fold = Service_Nj666_Multivoice::character_updates($row, ['name' => 'Рассказчик (голос 1)']);
check('переименовать в рассказчика — fold', ! empty($fold['fold']), TRUE);

// --- прикидки длины --------------------------------------------------------------
check('estimate_seconds: минута речи',
	Service_Nj666_Multivoice::estimate_seconds(950, 1.0), 60);
check('estimate_seconds: вдвое быстрее — вдвое короче',
	Service_Nj666_Multivoice::estimate_seconds(950, 2.0), 30);
check('estimate_seconds: нулевая скорость не роняет',
	Service_Nj666_Multivoice::estimate_seconds(950, 0), 60);
check('estimate_seconds: пустой текст', Service_Nj666_Multivoice::estimate_seconds(0), 0);

check('human_time: минуты и секунды', Service_Nj666_Multivoice::human_time(125), '2 мин 5 с');
check('human_time: только секунды', Service_Nj666_Multivoice::human_time(45), '45 с');
check('human_time: отрицательное не бывает', Service_Nj666_Multivoice::human_time(-10), '0 с');

check('target_chars: 7 минут', Service_Nj666_Multivoice::target_chars(7.0, 15.0),
	['min' => 6650, 'max' => 14250]);
check('target_chars: минимум не больше максимума',
	Service_Nj666_Multivoice::target_chars(20.0, 5.0)['min'] <= Service_Nj666_Multivoice::target_chars(20.0, 5.0)['max'], TRUE);
check('target_chars: ноль минут не роняет',
	Service_Nj666_Multivoice::target_chars(0, 0)['max'] > 0, TRUE);

// --- код книги --------------------------------------------------------------------
$code = Service_Nj666_Multivoice::code_new();
check('code_new: длина 12', UTF8::strlen($code), 12);
check('code_new: только безопасный алфавит',
	preg_match('~^[23456789abcdefghijkmnpqrstuvwxyz]{12}$~', (string) $code), 1);
check('code_new: коды разные', Service_Nj666_Multivoice::code_new() !== Service_Nj666_Multivoice::code_new(), TRUE);

// --- имена файлов и глав -----------------------------------------------------------
check('safe_file_name: слэши и двоеточие',
	Service_Nj666_Multivoice::safe_file_name('Глава 01: конец/начало'), 'Глава 01- конец-начало');
check('safe_file_name: точки по краям убраны',
	Service_Nj666_Multivoice::safe_file_name('  ..Название.  '), 'Название');
check('safe_file_name: пустое имя → «блок»', Service_Nj666_Multivoice::safe_file_name('///'), 'блок');
check('safe_file_name: длина 120', UTF8::strlen(Service_Nj666_Multivoice::safe_file_name(str_repeat('а', 300))), 120);

check('production_name: номер и название',
	Service_Nj666_Multivoice::production_name(nj666_book(), ['no' => 3, 'title' => 'Погоня']),
	'Глава 03 — Погоня');
check('production_name: без названия',
	Service_Nj666_Multivoice::production_name(nj666_book(), ['no' => 12, 'title' => '']),
	'Глава 12');
check('production_name: запрещённые символы вычищены',
	Service_Nj666_Multivoice::production_name(nj666_book(), ['no' => 1, 'title' => 'Кот? "*бегемот"']),
	'Глава 01 — Кот- --бегемот');

// --- реплики блока ------------------------------------------------------------------
check('clean_block_turns: строка → реплика рассказчика',
	Service_Nj666_Multivoice::clean_block_turns(['Авторский текст.']),
	[['speaker' => 'narrator', 'text' => 'Авторский текст.']]);
check('clean_block_turns: пустые реплики отброшены',
	Service_Nj666_Multivoice::clean_block_turns([
		['speaker' => '5', 'text' => 'Привет.'], ['speaker' => '5', 'text' => '   '], 'нет',
	]), [['speaker' => '5', 'text' => 'Привет.'], ['speaker' => 'narrator', 'text' => 'нет']]);
check('clean_block_turns: мусор не проходит',
	Service_Nj666_Multivoice::clean_block_turns([NULL, 42, ['text' => '']]), []);
check('clean_block_turns: говорящий запомнен',
	Arr::get(Service_Nj666_Multivoice::clean_block_turns([['speaker' => 12, 'text' => 'Да.']])[0], 'speaker'), 12);

$mixed = Service_Nj666_Multivoice::split_quoted_turns([[
	'speaker' => '8',
	'text' => 'Рассказала она ему сон; и возговорит зверь: "И зачем тебе мое позволенье?"',
]]);
check('split_quoted: автор отдельно', Arr::get($mixed[0], 'speaker'), 'narrator');
check('split_quoted: речь персонажу', Arr::get($mixed[1], 'speaker'), '8');
check_true('split_quoted: кавычки сняты', strpos((string) Arr::get($mixed[1], 'text'), 'И зачем тебе') === 0);

$spans = Service_Nj666_Multivoice::split_speech_spans('Он сказал: «Здравствуй». И ушёл.');
check('split_speech: три куска', count($spans), 3);
check('split_speech: середина — речь', Arr::get($spans[1], 'kind'), 'speech');
check('split_speech: ёлочки сняты', Arr::get($spans[1], 'text'), 'Здравствуй');

$people = [
	['id' => 5, 'name' => 'Маргарита', 'kind' => 'character'],
	['id' => 6, 'name' => 'Рассказчик', 'kind' => 'narrator'],
	['id' => 7, 'name' => 'Мастер', 'kind' => 'character'],
];
$edited = Service_Nj666_Multivoice::edit_block_turns([
	['speaker' => '5', 'text' => '  Привет.  '],
	['speaker' => 'unknown', 'text' => 'Автор.'],
	['speaker' => '5', 'text' => ''],
	['speaker' => '6', 'text' => 'Я рассказчик.'],
	['speaker' => '5', 'text' => '«Привет», — сказала она.', 'p' => 2],
], $people);
check('edit_turns: пустые отброшены', count($edited), 4);
check('edit_turns: голос персонажа', Arr::get($edited[0], 'speaker'), '5');
check('edit_turns: неизвестный → narrator', Arr::get($edited[1], 'speaker'), 'narrator');
check('edit_turns: id рассказчика → narrator', Arr::get($edited[2], 'speaker'), 'narrator');
check('edit_turns: кавычки не режем', count(Service_Nj666_Multivoice::edit_block_turns([
	['speaker' => '5', 'text' => '«Привет», — сказала она.'],
], $people)), 1);
check('edit_turns: p сохранён', Arr::get($edited[3], 'p'), 2);
check('fingerprint: p не влияет',
	Service_Nj666_Multivoice::turns_fingerprint($edited),
	Service_Nj666_Multivoice::turns_fingerprint([
		['speaker' => '5', 'text' => 'Привет.'],
		['speaker' => 'narrator', 'text' => 'Автор.'],
		['speaker' => 'narrator', 'text' => 'Я рассказчик.'],
		['speaker' => '5', 'text' => '«Привет», — сказала она.'],
	]));
check('block_turns: из json',
	Service_Nj666_Multivoice::block_turns(['turns_json' => '[{"speaker":"5","text":"Да."}]']),
	[['speaker' => '5', 'text' => 'Да.']]);
check('block_turns: уже массив',
	Service_Nj666_Multivoice::block_turns(['turns' => [['speaker' => 'narrator', 'text' => 'А.']]]),
	[['speaker' => 'narrator', 'text' => 'А.']]);

$remap = Service_Nj666_Multivoice::delete_remap_to(5, 7, $people);
check('remap: ок на мастера', Arr::get($remap, 'ok'), TRUE);
check('remap: to=7', Arr::get($remap, 'to'), '7');
check('remap: пустой to → рассказчик', Arr::get(Service_Nj666_Multivoice::delete_remap_to(5, 0, $people), 'to'), '6');
check('remap: на себя нельзя', Arr::get(Service_Nj666_Multivoice::delete_remap_to(5, 5, $people), 'ok'), FALSE);
check('remap: рассказчика нельзя', Arr::get(Service_Nj666_Multivoice::delete_remap_to(6, 5, $people), 'ok'), FALSE);
check('remap: неизвестный получатель', Arr::get(Service_Nj666_Multivoice::delete_remap_to(5, 99, $people), 'ok'), FALSE);

check('preview_hash: длина sha1', strlen(Service_Nj666_Multivoice::preview_hash('m', 'anna')), 40);
check('preview_hash: стабильный',
	Service_Nj666_Multivoice::preview_hash('m', 'anna'),
	Service_Nj666_Multivoice::preview_hash('m', 'anna'));
check_true('preview_hash: голос влияет',
	Service_Nj666_Multivoice::preview_hash('m', 'anna') !== Service_Nj666_Multivoice::preview_hash('m', 'bob'));
check('preview_file: мусор', Service_Nj666_Multivoice::preview_file('nope'), NULL);
check('preview_file: короткий хеш', Service_Nj666_Multivoice::preview_file('abc'), NULL);

$plain = Service_Nj666_Multivoice::split_speech_spans('Просто абзац.');
check('split_speech: без кавычек — plain', Arr::get($plain[0], 'kind'), 'plain');

$dash = Service_Nj666_Multivoice::split_speech_spans('— Здравствуй, — сказал он. — Как дела?');
check('split_speech: тире — первый кусок речь', Arr::get($dash[0], 'kind'), 'speech');
check_true('split_speech: тире — три куска', count($dash) >= 3);

$cue = Service_Nj666_Multivoice::split_speech_spans(
	'и говорит он своим любезныим дочерям: Милые мои доченьки, я отправляюсь за море.');
check('split_speech: ремарка с двоеточием — автор', Arr::get($cue[0], 'kind'), 'narrator');
check('split_speech: ремарка с двоеточием — речь', Arr::get($cue[1], 'kind'), 'speech');
check_true('split_speech: речь без кавычек сохранена', strpos((string) Arr::get($cue[1], 'text'), 'Милые') === 0);

// Подстановка голосов: у персонажа свой голос, у рассказчика — свой, у
// unknown-говорящего — голос книги.
$chars = [
	['id' => 5, 'name' => 'Маргарита', 'kind' => 'character', 'voice' => 'anna', 'instructions' => ''],
	['id' => 6, 'name' => 'Рассказчик', 'kind' => 'narrator', 'voice' => 'morfius', 'instructions' => ''],
	['id' => 7, 'name' => 'Кот', 'kind' => 'character', 'voice' => '', 'instructions' => 'мяукает'],
];

$block = ['no' => 1, 'turns_json' => json_encode([
	['speaker' => '5', 'text' => 'Здравствуйте.'],
	['speaker' => 'narrator', 'text' => 'Сказала она.'],
	['speaker' => '7', 'text' => 'Мяу.'],
	['speaker' => '99', 'text' => 'Голос из толпы.'],
], JSON_UNESCAPED_UNICODE)];

$turns = Service_Nj666_Multivoice::block_turns_with_voices(nj666_book(), $block, $chars);

check('голоса реплик: персонаж', Arr::get($turns[0], 'voice'), 'anna');
check('голоса реплик: рассказчик', Arr::get($turns[1], 'voice'), 'morfius');
check('голоса реплик: пустой голос → голос книги', Arr::get($turns[2], 'voice'), 'morfius');
check('голоса реплик: неизвестный говорящий → голос книги', Arr::get($turns[3], 'voice'), 'morfius');
check('голоса реплик: инструкция персонажа уехала', Arr::get($turns[2], 'instructions'), 'мяукает');
check('голоса реплик: текст не потерян', Arr::get($turns[0], 'text'), 'Здравствуйте.');

// Книга без своего голоса: берём голос рассказчика.
$turns2 = Service_Nj666_Multivoice::block_turns_with_voices(
	nj666_book(['default_voice' => '']), $block, $chars);
check('голоса реплик: нет голоса книги → голос рассказчика', Arr::get($turns2[3], 'voice'), 'morfius');

$inferred = Service_Nj666_Multivoice::infer_turn_speakers([
	['speaker' => 'narrator', 'text' => 'В некиим царстве жил-был богатый купец, и говорит он своим дочерям:'],
	['speaker' => 'narrator', 'text' => 'Милые мои доченьки, я отправляюсь за море.'],
], [
	['id' => 4, 'name' => 'Купец', 'kind' => 'character'],
	['id' => 7, 'name' => 'Меньшая дочь', 'kind' => 'character'],
]);
check('infer: ремарка остаётся рассказчику', Arr::get($inferred[0], 'speaker'), 'narrator');
check('infer: речь после «говорит» — купцу', Arr::get($inferred[1], 'speaker'), '4');

// --- нарезка блока на запросы ---------------------------------------------------------
$long = [
	['text' => str_repeat('а', 300), 'voice' => 'anna'],
	['text' => str_repeat('б', 300), 'voice' => 'morfius'],
	['text' => str_repeat('в', 300), 'voice' => 'anna'],
];

$chunks = Service_Nj666_Multivoice::segment_turns($long, 500);
check('segment_turns: реплики не дробятся, если влезают', count($chunks[0]), 1);
check('segment_turns: число порций', count($chunks), 3);
check('segment_turns: голос сохранён', Arr::get($chunks[1][0], 'voice'), 'morfius');

// 650 знаков вмещают две реплики по 300, третья уедет в следующую порцию.
$chunks = Service_Nj666_Multivoice::segment_turns($long, 650);
check('segment_turns: две реплики в одну порцию', count($chunks), 2);
check('segment_turns: вместимость порции', count($chunks[0]), 2);
check('segment_turns: хвост не потерян', count($chunks[1]), 1);

check('segment_turns: пустые реплики отброшены',
	Service_Nj666_Multivoice::segment_turns([['text' => '  '], ['text' => 'Да.']], 4000),
	[[['text' => 'Да.', 'voice' => '', 'instructions' => '']]]);
check('segment_turns: строка вместо массива',
	count(Service_Nj666_Multivoice::segment_turns(['Привет.'], 4000)), 1);

// Длинная реплика режется по фразам, а не по символам: склейка должна
// приходиться на паузу между фразами, иначе на стыке слышен щелчок.
// Лимит ниже 50 знаков split_text не принимает (пол не задан всерьёз —
// короче резать бессмысленно), поэтому берём 120.
$mono  = [['text' => str_repeat('Первая фраза длинная. ', 20), 'voice' => '']];
$mono[0]['text'] = rtrim($mono[0]['text']);
$parts = Service_Nj666_Multivoice::split_text($mono[0]['text'], 120);

check('split_text: режем по фразам', count($parts) > 1, TRUE);
check('split_text: кусок не длиннее лимита',
	max(array_map(['UTF8', 'strlen'], $parts)) <= 120, TRUE);
check('split_text: каждая часть кончается точкой',
	count(array_filter($parts, function ($p) { return UTF8::substr($p, -1) !== '.'; })), 0);
check('split_text: текст вернулся целиком', implode(' ', $parts), $mono[0]['text']);
check('split_text: короткий текст одним куском', Service_Nj666_Multivoice::split_text('Коротко.', 100), ['Коротко.']);
check('split_text: пустой текст', Service_Nj666_Multivoice::split_text('   ', 100), []);
check('split_text: сплошное слово без пробелов не вешает цикл',
	count(Service_Nj666_Multivoice::split_text(str_repeat('а', 500), 100)) >= 5, TRUE);

$chunks = Service_Nj666_Multivoice::segment_turns($mono, 120);
check('segment_turns: длинная реплика порезана на фразы', count($chunks) > 1, TRUE);

// --- склейка звука -------------------------------------------------------------------
check('concat_audio: один кусок как есть', Service_Nj666_Multivoice::concat_audio(['AAA'], 'mp3'), 'AAA');
check('concat_audio: mp3 склеивается байтами', Service_Nj666_Multivoice::concat_audio(['AA', 'BB'], 'mp3'), 'AABB');
check('concat_audio: пустые куски отброшены', Service_Nj666_Multivoice::concat_audio(['AA', '', 'BB'], 'mp3'), 'AABB');
check('concat_audio: ничего не пришло', Service_Nj666_Multivoice::concat_audio([], 'mp3'), '');

/** WAV-заглушка: шапка RIFF + fmt + data с четырьмя байтами звука. */
function nj666_wav($bytes = '1234')
{
	$fmt = pack('vvVVvv', 1, 1, 8000, 16000, 2, 16); // PCM, 1 канал, 8 кГц, 16 бит

	return 'RIFF'.pack('V', 36 + strlen($bytes)).'WAVE'
		.'fmt '.pack('V', strlen($fmt)).$fmt
		.'data'.pack('V', strlen($bytes)).$bytes;
}

$wav = Service_Nj666_Multivoice::concat_wav([nj666_wav('AAAA'), nj666_wav('BBBB')]);
check('concat_wav: RIFF на месте', UTF8::substr($wav, 0, 4), 'RIFF');
check('concat_wav: WAVE на месте', UTF8::substr($wav, 8, 4), 'WAVE');
check('concat_wav: fmt взят один раз', substr_count($wav, 'fmt '), 1);
check('concat_wav: data склеились', strpos($wav, 'data') === strrpos($wav, 'data'), TRUE);
check('concat_wav: звук склеился', strpos($wav, 'AAAABBBB') !== FALSE, TRUE);

$size = unpack('V', substr($wav, 4, 4));
check('concat_wav: длина в шапке = файл минус 8', (int) $size[1], strlen($wav) - 8);

$dpos = strpos($wav, 'data');
$dsize = unpack('V', substr($wav, $dpos + 4, 4));
check('concat_wav: длина data = 8 байт звука', (int) $dsize[1], 8);

check('concat_wav: не-wav склеивается как есть', Service_Nj666_Multivoice::concat_wav(['AA', 'BB']), 'AABB');

// Кусок синтеза — лимит OmniVoice, не лимит книги: иначе глава уезжает одним
// запросом и либо обрезается в clean_turns, либо виснет на nginx (11.10.2026).
$book_max  = (int) Service_Nj666_Multivoice::limits('max_chars', 0);
$synth_max = Service_Nj666_Multivoice::synth_chunk_chars();
check('synth_chunk_chars: меньше лимита книги', $synth_max > 0 AND $synth_max < $book_max, TRUE);
check('synth_chunk_chars: не больше 8000', $synth_max <= 8000, TRUE);
check('synth_model: пустая книга → fallback', Service_Nj666_Multivoice::synth_model([]), 'k2-fsa/omnivoice');
check('synth_model: модель книги важнее', Service_Nj666_Multivoice::synth_model(['model' => 'foo/bar']), 'foo/bar');

check('decode_audio_b64: пусто', Service_Nj666_Multivoice::decode_audio_b64(''), '');
check('decode_audio_b64: hello', Service_Nj666_Multivoice::decode_audio_b64(base64_encode('hello')), 'hello');
check('decode_audio_b64: data-префикс',
	Service_Nj666_Multivoice::decode_audio_b64('data:audio/mpeg;base64,'.base64_encode('xyz')), 'xyz');
check('decode_audio_b64: мусор', Service_Nj666_Multivoice::decode_audio_b64('!!!'), '');

// --- конфиг и лимиты -----------------------------------------------------------------
check('modes: есть auto и search',
	array_keys(Service_Nj666_Multivoice::modes()), ['auto', 'search']);
check('genders: значения OmniVoice',
	array_keys(Service_Nj666_Multivoice::genders()), ['', 'male', 'female']);
check('ages: значения OmniVoice', array_keys(Service_Nj666_Multivoice::ages()),
	['', 'child', 'young', 'adult', 'elderly']);
check('limits: максимум текста задан', Service_Nj666_Multivoice::limits('max_chars') > 0, TRUE);
check('limits: минимум текста задан', Service_Nj666_Multivoice::limits('min_chars') > 0, TRUE);
check('defaults: блок 7–15 минут', [
	Service_Nj666_Multivoice::defaults('block_min_min'),
	Service_Nj666_Multivoice::defaults('block_max_min'),
], [7.0, 15.0]);
check('chars_per_minute: разумное значение >= 200', Service_Nj666_Multivoice::chars_per_minute() >= 200, TRUE);
// rate_text живёт на лимитере nj100: без БД он честно молчит, а не падает —
// страница просто не покажет строку лимита.
check('rate_text: без БД не падает', is_string(Service_Nj666_Multivoice::rate_text()), TRUE);

// --- деградация без БД ---------------------------------------------------------------
// Чтение книги без БД падает ЯВНО (страница отдаст 500, а не «книга не
// найдена»): молча вернуть NULL здесь значит показать владельцу ложь.
// Проверяем именно это поведение, а не «тихо вернули пустоту».
function nj666_db_call($fn)
{
	try
	{
		$fn();

		return 'нет исключения';
	}
	catch (Exception $e)
	{
		return 'исключение';
	}
}

check('book: БД недоступна — падает явно',
	nj666_db_call(function () { Service_Nj666_Multivoice::book('ab34cd56ef78'); }), 'исключение');
check('characters: БД недоступна — падает явно',
	nj666_db_call(function () { Service_Nj666_Multivoice::characters(7); }), 'исключение');
check('blocks: БД недоступна — падает явно',
	nj666_db_call(function () { Service_Nj666_Multivoice::blocks(7); }), 'исключение');
check('owns: гость не владелец', Service_Nj666_Multivoice::owns(nj666_book(['user_id' => 5, 'visitor_uid' => 'x']), 0, 'other'), FALSE);
check('owns: владелец по аккаунту', Service_Nj666_Multivoice::owns(nj666_book(['user_id' => 5, 'visitor_uid' => 'x']), 5, 'other'), TRUE);
check('owns: гость по своей метке', Service_Nj666_Multivoice::owns(nj666_book(['user_id' => 0, 'visitor_uid' => 'x']), 0, 'x'), TRUE);
check('owns: пустая метка не даёт прав', Service_Nj666_Multivoice::owns(nj666_book(['user_id' => 0, 'visitor_uid' => '']), 0, ''), FALSE);

// --- рендер вьюх ---------------------------------------------------------------
// Варнинги php-wasm уходят в stderr и в HTML не попадают, поэтому превращаем их
// в исключения: так «неопределённая переменная» станет ФЕЙЛом, а не тишиной.
set_error_handler(function ($no, $str, $file, $line) {
	throw new ErrorException($str, 0, $no, $file, $line);
});

function render($file, array $data)
{
	global $ok, $bad;

	try
	{
		$view = new View($file);
		$view->set($data);
		$out  = $view->render();

		check('рендер '.$file.' ('.strlen($out).' байт)', strlen($out) > 500 ? 'true' : 'false', 'true');

		return $out;
	}
	catch (Throwable $e)
	{
		$bad++;
		echo "ФЕЙЛ рендер $file: ".$e->getMessage()." @ ".$e->getLine()."\n";

		return '';
	}
}

function contains($label, $haystack, $needle)
{
	check($label, strpos($haystack, $needle) !== FALSE ? 'true' : 'false', 'true');
}

$books = [
	['code' => 'ab34cd56ef78', 'title' => 'Мастер и Маргарита', 'chars' => 240000,
		'speed' => 1.0, 'status' => 'done', 'stage' => 'Книга озвучена',
		'updated_at' => '2026-10-10 12:00:00'],
	['code' => 'cd56ef78ab34', 'title' => 'Сказка про утку', 'chars' => 3000,
		'speed' => 1.0, 'status' => 'voicing', 'stage' => 'Озвучиваю блок 2 из 5',
		'updated_at' => '2026-10-10 13:00:00'],
];

$index = [
	'logged'    => TRUE,
	'can_run'   => TRUE,
	'enabled'   => TRUE,
	'books'     => $books,
	'modes'     => Service_Nj666_Multivoice::modes(),
	'languages' => Service_Nj665_Voice::languages(),
	'defaults'  => Service_Nj666_Multivoice::defaults(),
	'limits'    => Service_Nj666_Multivoice::limits(),
	'rate_text' => '5 в минуту',
	'error'     => '',
	'csrf'      => Security::token(),
	'post'      => [],
];

$html = render('666/index', $index);

contains('страница: заголовок', $html, 'Многоголосая озвучка');
contains('страница: поле текста', $html, 'id="nj666-text"');
contains('страница: режим «утка го»', $html, 'value="search"');
contains('страница: длина блока', $html, 'id="nj666-min"');
contains('страница: один блок', $html, 'id="nj666-single"');
contains('страница: книги в списке', $html, '/multivoice/b/ab34cd56ef78');
contains('страница: статус книги', $html, 'Озвучивается');
contains('страница: скрипт подключён', $html, 'nj666-form');
contains('страница: иконка удаления книги', $html, 'nj666-del-book');
contains('страница: aria удаления книги', $html, 'aria-label="Удалить книгу"');

// Книга без права запуска: кнопка выключена, причина видна.
$no_run = $index;
$no_run['can_run'] = FALSE;
$html = render('666/index', $no_run);

contains('без права: кнопка выключена', $html, 'id="nj666-submit" type="submit" disabled');
contains('без права: причина на странице', $html, 'роль <b>vip</b>');

$characters = [
	['id' => 5, 'name' => 'Маргарита', 'kind' => 'character', 'gender' => 'female',
		'age' => 'adult', 'voice' => 'anna', 'description' => 'Тихая и решительная.',
		'locked' => 1, 'mentions' => 42],
	['id' => 6, 'name' => 'Рассказчик', 'kind' => 'narrator', 'gender' => '', 'age' => '',
		'voice' => 'morfius', 'description' => '', 'locked' => 1, 'mentions' => 300],
];

$blocks = [
	['id' => 11, 'no' => 1, 'title' => 'Никогда не разговаривайте с неизвестными',
		'chars' => 9500, 'status' => 'voiced', 'error' => ''],
	['id' => 12, 'no' => 2, 'title' => 'Погоня', 'chars' => 8200, 'status' => 'new', 'error' => ''],
	['id' => 13, 'no' => 3, 'title' => 'Гроза', 'chars' => 7000, 'status' => 'error',
		'error' => 'Синтез не удался (429).'],
];

$book_page = [
	'logged'     => TRUE,
	'can_run'    => TRUE,
	'enabled'    => TRUE,
	'book'       => nj666_book(['status' => 'voicing', 'stage' => 'Озвучиваю блок 2 из 3',
		'progress' => 66, 'stage_note' => 'готово: 1', 'error' => '']),
	'characters' => $characters,
	'blocks'     => $blocks,
	'progress'   => ['total' => 3, 'ready' => 1, 'failed' => 1, 'percent' => 33],
	'excerpt'    => 'В час жаркого весеннего заката на Патриарших прудах появились два гражданина.',
	'voices'     => [['id' => 'morfius', 'name' => 'morfius', 'gender' => 'male',
		'age' => 'adult', 'description' => 'Глубокий, спокойный.'],
		['id' => 'anna', 'name' => 'anna', 'gender' => 'female', 'age' => 'adult', 'description' => '']],
	'genders'    => Service_Nj666_Multivoice::genders(),
	'ages'       => Service_Nj666_Multivoice::ages(),
	'modes'      => Service_Nj666_Multivoice::modes(),
	'languages'  => Service_Nj665_Voice::languages(),
	'defaults'   => Service_Nj666_Multivoice::defaults(),
	'limits'     => Service_Nj666_Multivoice::limits(),
	'rate_text'  => '5 в минуту',
	'csrf'       => Security::token(),
];

$html = render('666/book', $book_page);

contains('книга: шапка', $html, 'Мастер и Маргарита');
contains('книга: доля числом', $html, 'id="nj666-percent"');
check('книга: полосы хода нет', strpos($html, 'id="nj666-bar"') === FALSE, TRUE);
contains('книга: этап', $html, 'Озвучиваю блок 2 из 3');
contains('книга: персонаж', $html, 'Маргарита');
contains('книга: замочек', $html, 'nj666-lock');
contains('книга: рассказчик помечен', $html, 'рассказчик');
contains('книга: голос выбран', $html, 'value="anna" selected');
contains('книга: уникальный name голоса Маргариты', $html, 'name="nj666-c-voice-5"');
contains('книга: уникальный name голоса рассказчика', $html, 'name="nj666-c-voice-6"');
contains('книга: уникальный name пола', $html, 'name="nj666-c-gender-5"');
contains('книга: плеер готового блока', $html, '/multivoice/f/ab34cd56ef78/1');
contains('книга: ошибка блока видна', $html, 'Синтез не удался (429).');
contains('книга: кнопка удаления', $html, 'id="nj666-del-book"');
contains('книга: удаление иконкой', $html, 'aria-label="Удалить книгу"');
contains('книга: скрипт подключён', $html, 'nj666-book');
contains('книга: начало текста', $html, 'Патриарших прудах');
contains('книга: play голоса', $html, 'nj666-voice-play');
contains('книга: удаление с переназначением', $html, 'nj666-del-remap');
contains('книга: подпись переназначения', $html, 'Удаление с переназначением');
contains('книга: ссылка на блок', $html, '/multivoice/b/ab34cd56ef78/k/1');

// Ошибка книги: плашка с причиной.
$bad_book = $book_page;
$bad_book['book'] = nj666_book(['status' => 'error', 'error' => 'Модель не ответила.']);
$html = render('666/book', $bad_book);

contains('книга: плашка ошибки', $html, 'Модель не ответила.');

// Карточка для главной (card_view манифеста).
$html = render('nj666/card', []);

contains('карточка: ссылка на сервис', $html, 'href="/multivoice"');
contains('карточка: заголовок', $html, 'Многоголосая озвучка');

$block_page = [
	'logged'     => TRUE,
	'can_run'    => TRUE,
	'enabled'    => TRUE,
	'book'       => nj666_book(),
	'block'      => ['id' => 11, 'no' => 1, 'title' => 'Никогда не разговаривайте с неизвестными',
		'chars' => 9500, 'status' => 'voiced', 'error' => ''],
	'characters' => $characters,
	'turns'      => [
		['speaker' => 'narrator', 'text' => 'В час жаркого заката.', 'p' => 1],
		['speaker' => '5', 'text' => 'Я Маргарита.'],
	],
	'error'      => '',
	'csrf'       => Security::token(),
];

$html = render('666/block', $block_page);

contains('блок: заголовок поля', $html, 'id="nj666-block-title"');
contains('блок: реплика Маргариты', $html, 'Я Маргарита.');
contains('блок: абзац', $html, 'абз. 1');
contains('блок: к книге', $html, '/multivoice/b/ab34cd56ef78');
contains('блок: плеер', $html, '/multivoice/f/ab34cd56ef78/1');
contains('блок: форма post', $html, 'method="post"');
contains('блок: добавить реплику', $html, 'id="nj666-add-turn"');

// ------------------------------------------------------------------ итог
echo "\n";
echo $bad === 0
	? "Все проверки пройдены: $ok ок, 0 фейл\n"
	: "Есть фейлы: $ok ок, $bad фейл\n";

exit($bad === 0 ? 0 : 1);
