<?php
/**
 * Стенд nj665_voice («Озвучивание текста»): чистые функции сервиса.
 *
 *   N2J_ROOT=$(pwd) node /tmp/phpwasm/run_ui.mjs .tools/nj665_voice_stand.php
 *
 * Проверяется то, что нельзя увидеть глазами и что легче всего сломать:
 *   - разбор ответа синтеза: поток байт, JSON с base64, JSON со ссылкой,
 *     JSON с описанием ошибки (v1 не фиксирует формат ответа — это главный
 *     риск сервиса);
 *   - определение формата по содержимому: сервер может прислать mp3 в ответ
 *     на запрос pcm, и наоборот — от этого зависит, проиграется файл или нет;
 *   - разбор каталога моделей (голоса бывают строками и объектами);
 *   - границы скорости, форматов, длины текста и образцов голоса;
 *   - имя файла в Content-Disposition (русское имя — латиницей + RFC 5987).
 *
 * Сети и БД в стенде нет: методы, которые до них дотягиваются, обязаны
 * деградировать (пустой каталог, пустая история), а не падать.
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
		$path = '/app/application/modules/nj665_voice/config/'.$name.'.php';

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
	public static $charset = 'UTF-8';

	/** Ищем вьюху в модуле, потом в приложении (partials — общие). */
	public static function find_file($dir, $file, $ext = 'php')
	{
		foreach ([
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

	/** Заглушка: в стенде системный файл utf8 не подключаем. */
	public static function transliterate_to_ascii($str, $case = 0)
	{
		$map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
			'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
			'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
			'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c',
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

class Gate
{
	/** Плашка «по аккаунту»: в стенде важно лишь, что вьюха её зовёт. */
	public static function wall($return_url = '', array $options = [])
	{
		return '<div class="gate-wall" data-return="'.HTML::chars((string) $return_url).'">'
			.HTML::chars((string) ($options['title'] ?? '')).'</div>';
	}
}

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';
require '/app/system/classes/Kohana/View.php';

class View extends Kohana_View {}

require '/app/application/modules/nj665_voice/classes/Service/Nj665/Voice.php';

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

/** mp3-заглушка: «ID3» + 60 байт нулей. */
function nj665_mp3()
{
	return 'ID3'.str_repeat("\0", 60);
}

/** wav-заглушка: RIFF….WAVE. */
function nj665_wav()
{
	return 'RIFF____WAVE'.str_repeat("\0", 40);
}

// --- текст ----------------------------------------------------------------------
check('clean_text: CRLF → LF', Service_Nj665_Voice::clean_text("а\r\nб"), "а\nб");
check('clean_text: лишние пробелы', Service_Nj665_Voice::clean_text("  а   б  "), 'а б');
check('clean_text: пустые строки схлопываются', Service_Nj665_Voice::clean_text("а\n\n\n\n\nб"), "а\n\nб");
check('clean_text: управляющие символы убраны', Service_Nj665_Voice::clean_text("а\x00\x07б"), 'аб');
check('clean_text: пустой вход', Service_Nj665_Voice::clean_text('   '), '');

check('excerpt: переносы в одну строку',
	Service_Nj665_Voice::excerpt("первая строка\nвторая строка"), 'первая строка вторая строка');
check('excerpt: длина ограничена',
	UTF8::strlen(Service_Nj665_Voice::excerpt(str_repeat('а', 500))), 180);
check('title_from_text: разметка убрана',
	Service_Nj665_Voice::title_from_text('# Заголовок *текста*'), 'Заголовок текста');
check('title_from_text: длина ограничена',
	UTF8::strlen(Service_Nj665_Voice::title_from_text(str_repeat('б', 200))), 60);

// --- скорость, форматы, границы -------------------------------------------------
check('clamp_speed: ниже минимума', Service_Nj665_Voice::clamp_speed(0.1), 0.5);
check('clamp_speed: выше максимума', Service_Nj665_Voice::clamp_speed(9), 2.0);
check('clamp_speed: внутри диапазона', Service_Nj665_Voice::clamp_speed(1.25), 1.25);
check('clamp_speed: мусор → минимум', Service_Nj665_Voice::clamp_speed('быстро'), 0.5);

check('pick_format: разрешённый формат', Service_Nj665_Voice::pick_format('wav', ['wav', 'mp3']), 'wav');
check('pick_format: чужой формат → первый из разрешённых', Service_Nj665_Voice::pick_format('mp4', ['wav']), 'wav');
check('pick_format: пустой выбор → mp3', Service_Nj665_Voice::pick_format('', []), 'mp3');
check('pick_format: только flac', Service_Nj665_Voice::pick_format('mp3', ['flac', 'aac']), 'flac');

check('media_type: mp3', Service_Nj665_Voice::media_type('mp3'), 'audio/mpeg');
check('media_type: wav', Service_Nj665_Voice::media_type('wav'), 'audio/wav');
check('media_type: opus', Service_Nj665_Voice::media_type('opus'), 'audio/ogg');
check('media_type: pcm', Service_Nj665_Voice::media_type('pcm'), 'application/octet-stream');
check('media_type: неизвестный', Service_Nj665_Voice::media_type('mp4'), 'application/octet-stream');

check('is_playable: mp3', Service_Nj665_Voice::is_playable('mp3'), TRUE);
check('is_playable: pcm', Service_Nj665_Voice::is_playable('pcm'), FALSE);
check('format_ext: aac → m4a', Service_Nj665_Voice::format_ext('aac'), 'm4a');

// --- формат по содержимому ------------------------------------------------------
check('sniff_format: mp3 по ID3', Service_Nj665_Voice::sniff_format(nj665_mp3()), 'mp3');
check('sniff_format: mp3 по синхрослову', Service_Nj665_Voice::sniff_format("\xFF\xFB".str_repeat("\0", 40)), 'mp3');
check('sniff_format: wav', Service_Nj665_Voice::sniff_format(nj665_wav()), 'wav');
check('sniff_format: flac', Service_Nj665_Voice::sniff_format('fLaC'.str_repeat("\0", 40)), 'flac');
check('sniff_format: ogg/opus', Service_Nj665_Voice::sniff_format('OggS'.str_repeat("\0", 20).'OpusHead'), 'opus');
check('sniff_format: ogg без Opus', Service_Nj665_Voice::sniff_format('OggS'.str_repeat("\0", 40)), 'ogg');
check('sniff_format: aac по ftyp', Service_Nj665_Voice::sniff_format('____ftyp'.str_repeat("\0", 40)), 'aac');
check('sniff_format: текст не аудио', Service_Nj665_Voice::sniff_format('{"detail":"нет ключа"}'), NULL);
check('sniff_format: пусто', Service_Nj665_Voice::sniff_format(''), NULL);
check('sniff_format: короткий вход', Service_Nj665_Voice::sniff_format('abc'), NULL);

// --- base64 ---------------------------------------------------------------------
$b64 = base64_encode(nj665_mp3());

check('looks_like_base64: длинная строка', Service_Nj665_Voice::looks_like_base64($b64), TRUE);
check('looks_like_base64: короткая строка', Service_Nj665_Voice::looks_like_base64('abcd'), FALSE);
check('looks_like_base64: текст с пробелом', Service_Nj665_Voice::looks_like_base64(str_repeat('а б ', 40)), FALSE);
check('base64_decode: обычный', Service_Nj665_Voice::base64_decode($b64), nj665_mp3());
check('base64_decode: с data:-префиксом',
	Service_Nj665_Voice::base64_decode('data:audio/mpeg;base64,'.$b64), nj665_mp3());
check('base64_decode: с переносами',
	Service_Nj665_Voice::base64_decode(substr($b64, 0, 10)."\n".substr($b64, 10)), nj665_mp3());
check('base64_decode: мусор → false', Service_Nj665_Voice::base64_decode('!!!не base64!!!'), FALSE);

// --- разбор JSON-ответа ---------------------------------------------------------
$from_json = Service_Nj665_Voice::audio_from_json(['audio' => $b64]);
check('audio_from_json: ключ audio', $from_json['bytes'], nj665_mp3());

$from_json = Service_Nj665_Voice::audio_from_json(['data' => ['audio_base64' => $b64]]);
check('audio_from_json: вложенный data.audio_base64', $from_json['bytes'], nj665_mp3());

$from_json = Service_Nj665_Voice::audio_from_json(['result' => ['data' => ['audio' => $b64]]]);
check('audio_from_json: три уровня вложенности', $from_json['bytes'], nj665_mp3());

$from_json = Service_Nj665_Voice::audio_from_json(['url' => 'https://voice.example.ru/audio/abcd.mp3']);
check('audio_from_json: ссылка', $from_json['url'], 'https://voice.example.ru/audio/abcd.mp3');
check('audio_from_json: ссылка не считана как base64', $from_json['bytes'], '');

$from_json = Service_Nj665_Voice::audio_from_json(['detail' => 'ключ не принят']);
check('audio_from_json: описание ошибки — не аудио', $from_json['bytes'], '');
check('audio_from_json: описание ошибки — не ссылка', $from_json['url'], '');

// --- разбор ответа синтеза целиком ----------------------------------------------
$res = Service_Nj665_Voice::audio_from_response(nj665_mp3(), 'audio/mpeg', 'mp3');
check('audio_from_response: сырой поток', $res['bytes'], nj665_mp3());
check('audio_from_response: сырой поток — формат подтверждён', $res['format'], 'mp3');

$res = Service_Nj665_Voice::audio_from_response(nj665_wav(), 'application/octet-stream', 'pcm');
check('audio_from_response: wav вместо запрошенного pcm', $res['format'], 'wav');
check('audio_from_response: байты не потеряны', $res['bytes'], nj665_wav());

$res = Service_Nj665_Voice::audio_from_response(json_encode(['audio' => $b64]), 'application/json', 'mp3');
check('audio_from_response: JSON с base64', $res['bytes'], nj665_mp3());
check('audio_from_response: JSON с base64 — ошибки нет', $res['error'], '');

$res = Service_Nj665_Voice::audio_from_response(json_encode(['url' => 'https://voice.example.ru/audio/abcd.mp3']), 'application/json', 'mp3');
check('audio_from_response: JSON со ссылкой', $res['url'], 'https://voice.example.ru/audio/abcd.mp3');
check('audio_from_response: формат из ссылки', $res['format'], 'mp3');

$res = Service_Nj665_Voice::audio_from_response(json_encode(['detail' => 'нет ключа']), 'application/json', 'mp3');
check('audio_from_response: JSON с ошибкой — байт нет', $res['bytes'], '');
check('audio_from_response: JSON с ошибкой — текст есть', strpos($res['error'], 'не похож') !== FALSE ? 'true' : 'false', 'true');

$res = Service_Nj665_Voice::audio_from_response('', 'audio/mpeg', 'mp3');
check('audio_from_response: пустой ответ', strpos($res['error'], 'пустой') !== FALSE ? 'true' : 'false', 'true');

$html = '<html><body>502 Bad Gateway</body></html>';
$res  = Service_Nj665_Voice::audio_from_response($html, 'text/html', 'mp3');
check('audio_from_response: HTML-заглушка отдаётся как есть', $res['bytes'], $html);

// --- каталог моделей ------------------------------------------------------------
$payload = ['data' => [[
	'id'                => 'k2-fsa/omnivoice',
	'supported_voices'  => ['alloy', 'echo'],
	'supported_formats' => ['mp3', 'wav', 'pcm'],
	'output_modalities' => ['speech'],
]]];

$models = Service_Nj665_Voice::parse_models($payload);
check('parse_models: одна модель', count($models), 1);
check('parse_models: id', $models[0]['id'], 'k2-fsa/omnivoice');
check('parse_models: голоса', implode(',', $models[0]['voices']), 'alloy,echo');
check('parse_models: форматы', implode(',', $models[0]['formats']), 'mp3,wav,pcm');
check('parse_models: речевая модель', $models[0]['speech'], TRUE);

$models = Service_Nj665_Voice::parse_models(['data' => [['id' => 'text-only', 'supported_voices' => []]]]);
check('parse_models: модель без голосов — не речевая', $models[0]['speech'], FALSE);

$models = Service_Nj665_Voice::parse_models([['id' => 'a/b'], ['id' => 'c/d', 'supported_voices' => ['v']]]);
check('parse_models: плоский список', count($models), 2);

$models = Service_Nj665_Voice::parse_models(['id' => 'solo', 'supported_voices' => ['v1']]);
check('parse_models: одна модель без обёртки', $models[0]['id'], 'solo');

$models = Service_Nj665_Voice::parse_models(['data' => [['id' => 'x', 'supported_voices' => [['id' => 'v1'], ['name' => 'v2']]]]]);
check('parse_models: голоса-объекты', implode(',', $models[0]['voices']), 'v1,v2');

check('parse_models: пустой ответ (документированный {})', count(Service_Nj665_Voice::parse_models([])), 0);
check('parse_models: мусор', count(Service_Nj665_Voice::parse_models(['unexpected' => 1])), 0);
check('parse_models: не массив', count(Service_Nj665_Voice::parse_models('нет')), 0);

// --- деградация без сети и БД ---------------------------------------------------
check('enabled: без ключа сервис выключен', Service_Nj665_Voice::enabled(), FALSE);
check('models: без сети и БД — пусто, не падение', Service_Nj665_Voice::models(), []);
check('speech_models: пусто', Service_Nj665_Voice::speech_models(), []);
check('voices: пусто', Service_Nj665_Voice::voices(), []);
check('default_model: берётся из конфига', Service_Nj665_Voice::default_model(), 'k2-fsa/omnivoice');
check('default_format: mp3, а не pcm из спек', Service_Nj665_Voice::default_format(), 'mp3');
check('formats: без каталога — все известные', count(Service_Nj665_Voice::formats()), 6);
check('key_info: без ключа — отказ с текстом',
	Service_Nj665_Voice::key_info()['ok'], FALSE);

// --- образцы голоса ---------------------------------------------------------------
$refs = Service_Nj665_Voice::clean_references([
	'https://example.ru/voice.mp3',
	['text' => 'говори тише'],
	'мусор' => TRUE,
	['url' => 'ftp://example.ru/x.mp3'],
	['text' => 'второй'],
	['text' => 'третий'],
	['text' => 'четвёртый'],
]);

check('clean_references: лимит 3', count($refs), 3);
check('clean_references: ссылка → input_audio', $refs[0]['type'], 'input_audio');
check('clean_references: текст → text', $refs[1]['type'], 'text');
check('clean_references: ftp отброшен', $refs[2]['text'], 'второй');

// --- каталог голосов v1 (/api/v1/voices) -------------------------------------------
$voice_payload = ['data' => ['data' => [[
	'id'          => 'morfius',
	'name'        => 'morfius',
	'gender'      => 'male',
	'age'         => 'adult',
	'description' => 'Morpheus (The Matrix, Russian dub). Deep, calm, measured.',
	'model'       => 'k2-fsa/omnivoice',
]]]];

$voices = Service_Nj665_Voice::parse_voices(['data' => [[
	'id'          => 'morfius',
	'name'        => 'morfius',
	'gender'      => 'male',
	'age'         => 'adult',
	'description' => 'Morpheus (The Matrix, Russian dub). Deep, calm, measured.',
	'model'       => 'k2-fsa/omnivoice',
]]]);

check('parse_voices: один голос', count($voices), 1);
check('parse_voices: id', $voices[0]['id'], 'morfius');
check('parse_voices: gender', $voices[0]['gender'], 'male');
check('parse_voices: age', $voices[0]['age'], 'adult');
check('parse_voices: описание', strpos($voices[0]['description'], 'Morpheus') === 0 ? 'true' : 'false', 'true');
check('parse_voices: model', $voices[0]['model'], 'k2-fsa/omnivoice');

$voices = Service_Nj665_Voice::parse_voices(['id' => 'solo-voice', 'gender' => 'female']);
check('parse_voices: голос без обёртки', $voices[0]['id'], 'solo-voice');
check('parse_voices: имя подставляется', $voices[0]['name'], 'solo-voice');

$voices = Service_Nj665_Voice::parse_voices(['data' => 'alloy']);
check('parse_voices: data — строка', $voices[0]['id'], 'alloy');

$voices = Service_Nj665_Voice::parse_voices(['data' => ['alloy', 'echo']]);
check('parse_voices: список строк', $voices[0]['id'].','.$voices[1]['id'], 'alloy,echo');
check('parse_voices: у строки нет описания', $voices[0]['description'], '');

check('parse_voices: пустой ответ (документированный {})', count(Service_Nj665_Voice::parse_voices([])), 0);
check('parse_voices: мусор', count(Service_Nj665_Voice::parse_voices('нет')), 0);

// --- реплики многоголосого блока -----------------------------------------------------
$turns = Service_Nj665_Voice::clean_turns([
	'Первая реплика.',
	['text' => 'Вторая реплика.', 'voice' => 'morfius'],
	['text' => '   ', 'voice' => 'echo'],
	['text' => 'Третья реплика.', 'voice' => 'чужой-голос', 'instructions' => 'шепотом'],
], ['morfius', 'echo'], 'morfius');

check('clean_turns: пустые реплики отброшены', count($turns['turns']), 3);
check('clean_turns: строка стала репликой', $turns['turns'][0]['text'], 'Первая реплика.');
check('clean_turns: голос сохранён', $turns['turns'][1]['voice'], 'morfius');
check('clean_turns: чужой голос подменён', $turns['turns'][2]['voice'], 'morfius');
check('clean_turns: инструкция сохранена', $turns['turns'][2]['instructions'], 'шепотом');
check('clean_turns: символы посчитаны', $turns['chars'], UTF8::strlen('Первая реплика.')
	+ UTF8::strlen('Вторая реплика.') + UTF8::strlen('Третья реплика.'));
check('clean_turns: пустой вход — NULL', Service_Nj665_Voice::clean_turns([], [], ''), NULL);
check('clean_turns: только пробел — NULL', Service_Nj665_Voice::clean_turns(['  '], [], ''), NULL);

check('match_voice: пустой → fallback', Service_Nj665_Voice::match_voice('', ['morfius', 'anna'], 'morfius'), 'morfius');
check('match_voice: .wav срезается',
	Service_Nj665_Voice::match_voice('heroic_leader_1.wav', ['heroic_leader_1', 'anna'], 'anna'),
	'heroic_leader_1');
check('match_voice: свой id не трогаем', Service_Nj665_Voice::match_voice('anna', ['morfius', 'anna'], 'morfius'), 'anna');

$empty_voice = Service_Nj665_Voice::clean_turns(
	[['text' => 'Жил-был купец.']], ['morfius', 'anna'], 'morfius');
check('clean_turns: пустой голос реплики → fallback', Arr::get($empty_voice['turns'][0], 'voice'), 'morfius');

// --- принадлежность и удаление ------------------------------------------------------
$row = ['id' => 7, 'code' => 'abcd2345xyzw', 'user_id' => 5, 'visitor_uid' => 'visitor-1'];

check('owns: свой по user_id', Service_Nj665_Voice::owns($row, 5, ''), TRUE);
check('owns: свой по visitor_uid', Service_Nj665_Voice::owns($row, 0, 'visitor-1'), TRUE);
check('owns: чужой по user_id', Service_Nj665_Voice::owns($row, 9, ''), FALSE);
check('owns: чужой visitor_uid', Service_Nj665_Voice::owns($row, 0, 'visitor-2'), FALSE);
check('owns: гость без uid', Service_Nj665_Voice::owns($row, 0, ''), FALSE);
check('owns: гостевая запись — своему браузеру',
	Service_Nj665_Voice::owns(['user_id' => 0, 'visitor_uid' => 'v-9'], 0, 'v-9'), TRUE);
check('owns: гостевая запись — чужому браузеру',
	Service_Nj665_Voice::owns(['user_id' => 0, 'visitor_uid' => 'v-9'], 0, 'v-10'), FALSE);
check('owns: вошедший не получает чужую гостевую запись',
	Service_Nj665_Voice::owns(['user_id' => 0, 'visitor_uid' => 'v-9'], 5, 'v-9'), TRUE);

// Удаление без БД обязано отказывать, а не падать: строки в журнале нет.
$del = Service_Nj665_Voice::delete('abcd2345xyzw', 5, 'visitor-1');
check('delete: без БД — отказ, не исключение', $del['ok'], FALSE);
check('delete: текст ошибки есть', $del['error'] !== '' ? 'true' : 'false', 'true');

// --- имя файла при отдаче ----------------------------------------------------------
$row  = ['code' => 'abcd2345xyzw', 'format' => 'mp3', 'title' => 'Привет мир'];
$head = Service_Nj665_Voice::content_disposition($row, TRUE);

check('content_disposition: вложение', strpos($head, 'attachment') === 0 ? 'true' : 'false', 'true');
check('content_disposition: латиницей', strpos($head, 'filename="privet-mir.mp3"') !== FALSE ? 'true' : 'false', 'true');
check('content_disposition: RFC 5987', strpos($head, "filename*=UTF-8''") !== FALSE ? 'true' : 'false', 'true');

$head = Service_Nj665_Voice::content_disposition(['code' => 'abcd2345xyzw', 'format' => 'pcm', 'title' => ''], FALSE);
check('content_disposition: без заголовка — код в имени',
	strpos($head, 'voice-abcd2345xyzw.pcm') !== FALSE ? 'true' : 'false', 'true');
check('content_disposition: плеер, не вложение', strpos($head, 'inline') === 0 ? 'true' : 'false', 'true');

// --- коды ---------------------------------------------------------------------------
$code = Service_Nj665_Voice::code_new();
check('code_new: длина', strlen($code), 12);
check('code_new: алфавит без 0/1/o/l', preg_match('~^[23456789a-z]{12}$~', $code), 1);
check('code_new: следующий код другой', Service_Nj665_Voice::code_new() !== Service_Nj665_Voice::code_new(), TRUE);
check('speech: код с точкой не проходит', Service_Nj665_Voice::speech('../../etc'), NULL);
check('speech: пустой код', Service_Nj665_Voice::speech(''), NULL);

// --- рендер вьюх -------------------------------------------------------------------
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

$history = [
	['code' => 'abcd2345xyzw', 'model' => 'k2-fsa/omnivoice', 'voice' => 'alloy',
		'format' => 'mp3', 'speed' => 1.0, 'language' => 'ru', 'instructions' => '',
		'chars' => 42, 'bytes' => 51200, 'title' => 'Привет, мир', 'excerpt' => 'Привет',
		'status' => 'done', 'error' => '', 'plays' => 1, 'downloads' => 0,
		'created_at' => '2026-10-09 12:00:00'],
];

$base = [
	'logged'    => TRUE,
	'enabled'   => TRUE,
	'models'    => [['id' => 'k2-fsa/omnivoice', 'voices' => ['alloy'], 'formats' => ['mp3'], 'speech' => TRUE]],
	'model'     => 'k2-fsa/omnivoice',
	'voices'    => ['alloy', 'echo'],
	'formats'   => ['mp3', 'wav'],
	'voice'     => 'alloy',
	'format'    => 'mp3',
	'speed'     => 1.0,
	'languages' => Service_Nj665_Voice::languages(),
	'language'  => 'ru',
	'limits'    => Service_Nj665_Voice::limits(),
	'rate_text' => Service_Nj665_Voice::rate_text(),
	'history'   => $history,
	'result'    => NULL,
	'error'     => '',
	'post'      => [],
	'csrf'      => 'test-token',
	'is_admin'  => FALSE,
	'key_info'  => ['ok' => FALSE, 'error' => ''],
];

$html = render('665/index', $base);

contains('страница: заголовок', $html, 'Озвучивание текста');
contains('страница: поле текста', $html, 'id="nj665-text"');
contains('страница: голос выбран', $html, 'value="alloy" selected');
contains('страница: скорость', $html, 'id="nj665-speed"');
contains('страница: история — файл', $html, '/voice/f/abcd2345xyzw');
contains('страница: скрипт подключён', $html, 'nj665-form');
contains('страница: JSON API в подсказке', $html, '/voice/api/voices');
contains('страница: кнопка удаления в истории', $html, 'data-nj665-del="abcd2345xyzw"');
contains('страница: удаление стирает файл сразу', $html, 'удаление стирает файл сразу');

// Подтверждение удаления — вторым нажатием, а не окном браузера: окно
// останавливает страницу и выглядит как авария (владелец, 09.10.2026).
$js = (string) @file_get_contents('/app/application/modules/nj665_voice/views/665/script.php');

check('удаление: confirm() не используется', strpos($js, 'window.confirm') === FALSE ? 'true' : 'false', 'true');
check('удаление: взвод — общий data-n2j-arm', strpos($js, 'data-n2j-arm="Точно удалить?"') !== FALSE ? 'true' : 'false', 'true');
check('удаление: своего взвода и авторазряда нет', strpos($js, 'function arm(') === FALSE ? 'true' : 'false', 'true');
check('удаление: вторая подпись', strpos($js, 'Точно удалить?') !== FALSE ? 'true' : 'false', 'true');
contains('страница: текст не уезжает в атрибут без экранирования',
	$html, 'name="csrf" id="nj665-csrf" value="test-token"');

// Гость: плашка входа вместо результата; форма всё равно на месте.
$guest = $base;
$guest['logged']  = FALSE;
$guest['error']   = 'Озвучивание доступно по аккаунту — войдите на сайт.';
$html = render('665/index', $guest);

contains('гость: плашка входа', $html, 'gate-wall');
contains('гость: текст ошибки', $html, 'войдите на сайт');

// Успешный синтез: плеер, ссылка скачивания, метаданные.
$done = $base;
$done['result'] = ['ok' => TRUE, 'code' => 'abcd2345xyzw', 'url' => '/voice/f/abcd2345xyzw',
	'format' => 'mp3', 'mime' => 'audio/mpeg', 'bytes' => 51200, 'chars' => 42,
	'model' => 'k2-fsa/omnivoice', 'voice' => 'alloy', 'title' => 'Привет, мир'];
$html = render('665/index', $done);

contains('результат: плеер со ссылкой', $html, 'src="/voice/f/abcd2345xyzw"');
contains('результат: скачивание', $html, '/voice/f/abcd2345xyzw?dl=1');
contains('результат: блок показан', $html, 'id="nj665-result" class="rounded-xl');

// Выгрузка для глаз: N2J_DUMP=имя.html пишет страницу в корень виртуальной ФС
// (забирается ключом --out у run_ui.mjs). Нужен именно вариант с результатом:
// на нём видно плеер, кнопки и историю.
if (getenv('N2J_DUMP'))
{
	// 'stdout' — печать в вывод стенда между маркерами (вытаскивается awk без
	// доступа к виртуальной ФС), иначе — файл в кеше приложения.
	if (getenv('N2J_DUMP') === 'stdout')
	{
		echo "---HTML---\n".$html."\n---ENDHTML---\n";
	}
	elseif ( ! is_dir('/app/application/cache'))
	{
		@mkdir('/app/application/cache', 0755, TRUE);
		file_put_contents('/app/application/cache/'.basename((string) getenv('N2J_DUMP')), $html);
	}
	else
	{
		file_put_contents('/app/application/cache/'.basename((string) getenv('N2J_DUMP')), $html);
	}
}

// Ошибка синтеза: плашка с текстом и скрытый блок результата.
$fail = $base;
$fail['error'] = 'Сервис озвучивания: синтез не удался (429).';
$html = render('665/index', $fail);

contains('ошибка: текст на странице', $html, 'синтез не удался');
contains('ошибка: блок результата скрыт', $html, 'id="nj665-result" class="hidden');

// Сервис не настроен: честная плашка про переменные .env.
$off = $base;
$off['enabled'] = FALSE;
$html = render('665/index', $off);

contains('без ключа: плашка про NJ665_API_URL', $html, 'NJ665_API_URL');

// Карточка для главной (card_view манифеста).
$html = render('nj665/card', []);

contains('карточка: ссылка на сервис', $html, 'href="/voice"');
contains('карточка: ссылка на API', $html, '/voice/api/voices');

restore_error_handler();

// --- итог -------------------------------------------------------------------------
echo "\nитог: $ok ок, $bad фейл\n";
exit($bad > 0 ? 1 : 0);
