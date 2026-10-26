<?php
/**
 * Стенд nj677_talk («Разговор с ИИ голосом»): чистые функции разговора.
 *
 *   N2J_ROOT=$(pwd) node .tools/phpwasm_stand.mjs .tools/nj677_talk_stand.php
 *
 * Голосовой разговор в песочнице не проверить: нет ни PHP, ни браузера, ни
 * микрофона, ни внешнего OmniVoice. Зато проверяемо то, что определяет
 * качество разговора и ломается тише всего:
 *   - ответ модели приводится к читаемому виду (без звёздочек и заголовков);
 *   - озвучка не произносит ссылки, разметку и смайлы;
 *   - ответ режется на части по границам фраз — от этого зависит, насколько
 *     рано зазвучит первая фраза;
 *   - контекст обрезается с конца и не теряет порядок ролей;
 *   - запись с микрофона принимается или отклоняется по размеру и типу;
 *   - разбор ответа распознавания терпим к разным форматам (v1 их не фиксирует).
 *
 * Сети и БД в стенде нет: методы, которым они нужны, обязаны деградировать,
 * а не падать.
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/system/');
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

/** Конфиг модуля по имени: ищем в модулях, потом в application/config. */
class Config
{
    public function load($name)
    {
        $name = preg_replace('~[^a-z0-9_]~', '', (string) $name);
        $candidates = [];

        foreach ((array) glob('/app/application/modules/*/config/'.$name.'.php') as $path)
        {
            $candidates[] = $path;
        }

        $candidates[] = '/app/application/config/'.$name.'.php';

        foreach ($candidates as $path)
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

require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';

require '/app/application/modules/nj665_voice/classes/Service/Nj665/Voice.php';
require '/app/application/modules/nj677_talk/classes/Service/Nj677/Talk.php';

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

// --- конфиг ---------------------------------------------------------------

check('язык разговора по умолчанию', Service_Nj677_Talk::language(), 'ru');
check('скорость речи из конфига', number_format(Service_Nj677_Talk::speed(), 2, '.', ''), '1.05');
check('часть озвучки — 200 знаков', (int) Service_Nj677_Talk::limits('tts_chars', 0), 200);
check('частей на ответ — не больше 10', (int) Service_Nj677_Talk::limits('tts_parts', 0), 10);
check('предел реплики — 2000 знаков', (int) Service_Nj677_Talk::limits('turn_chars', 0), 2000);
check('лимит ходов подписан по-русски', Service_Nj677_Talk::rate_text(), '20 в минуту и 240 в час');
check('формат озвучки по умолчанию — mp3', Service_Nj677_Talk::speech_format(), 'mp3');

// --- ответ модели ---------------------------------------------------------

check('заголовок, список и жирный шрифт сняты',
    Service_Nj677_Talk::format_reply("## Заголовок\n- пункт\n**важно**"),
    "Заголовок\nпункт\nважно");

check('три пустые строки схлопнуты в две',
    Service_Nj677_Talk::format_reply("а\n\n\n\nб"),
    "а\n\nб");

check('управляющие символы выброшены',
    Service_Nj677_Talk::format_reply("а\x01б"),
    'аб');

check('длинный ответ обрезан по пределу 4000 знаков',
    mb_strlen(Service_Nj677_Talk::format_reply(str_repeat('я', 5000))),
    4000);

// --- озвучка --------------------------------------------------------------

check('ссылка остаётся подписью',
    Service_Nj677_Talk::clean_speech('Смотри [документ](https://example.ru/a?b=1) рядом'),
    'Смотри документ рядом');

check('голый адрес не читается вслух',
    Service_Nj677_Talk::clean_speech('Открыл https://example.ru/page и всё'),
    'Открыл и всё');

check('смайлы не произносятся',
    Service_Nj677_Talk::clean_speech('Готово 🙂'),
    'Готово');

check('пустой ответ не даёт частей',
    Service_Nj677_Talk::split_sentences(''),
    []);

check('короткие реплики склеиваются в одну часть',
    Service_Nj677_Talk::split_sentences('Да. Понял. Это длинное предложение из нескольких слов, которое стоит разбить.'),
    ['Да. Понял. Это длинное предложение из нескольких слов, которое стоит разбить.']);

$parts = Service_Nj677_Talk::split_sentences(str_repeat('слово ', 60));

check_true('длинная фраза режется на части', count($parts) >= 2);
check_true('часть не длиннее 200 знаков', max(array_map('mb_strlen', $parts)) <= 200);

$many = Service_Nj677_Talk::split_sentences(
    'Первое предложение достаточно длинное, чтобы остаться отдельным. '
    .'Второе предложение тоже достаточно длинное для отдельной части. '
    .'Третье предложение такое же длинное, как и предыдущие два.',
    200, 2);

check('предел числа частей соблюдён', count($many), 2);

// --- контекст -------------------------------------------------------------

$rows = [
    ['role' => 'user', 'text' => 'первый'],
    ['role' => 'assistant', 'text' => 'второй'],
    ['role' => 'user', 'text' => 'третий'],
];

check('из истории берутся последние реплики по порядку',
    Service_Nj677_Talk::trim_history($rows, 2),
    [['role' => 'assistant', 'content' => 'второй'], ['role' => 'user', 'content' => 'третий']]);

check('даже одна длинная реплика остаётся при жёстком лимите знаков',
    Service_Nj677_Talk::trim_history($rows, 5, 5),
    [['role' => 'user', 'content' => 'третий']]);

check('незнакомая роль приводится к user',
    Service_Nj677_Talk::trim_history([['role' => 'system', 'text' => 'правила']], 5),
    [['role' => 'user', 'content' => 'правила']]);

check('системная подсказка идёт первой, мусор отброшен',
    Service_Nj677_Talk::build_messages([
        ['role' => 'system', 'content' => 'вредное'],
        ['role' => 'user', 'content' => 'привет'],
        ['role' => 'assistant', 'content' => 'здравствуйте'],
    ], 'отвечай коротко'),
    [
        ['role' => 'system', 'content' => 'отвечай коротко'],
        ['role' => 'user', 'content' => 'привет'],
        ['role' => 'assistant', 'content' => 'здравствуйте'],
    ]);

// --- запись с микрофона ---------------------------------------------------

$r = Service_Nj677_Talk::audio_ok(0);
check('пустая запись отклонена', $r['ok'], FALSE);
check_true('причина отказа сказана человеку', $r['error'] !== '');

check('обрывок короче килобайта отклонён', Service_Nj677_Talk::audio_ok(500)['ok'], FALSE);
check('запись больше 4 МБ отклонена', Service_Nj677_Talk::audio_ok(5 * 1024 * 1024)['ok'], FALSE);

$r = Service_Nj677_Talk::audio_ok(20000, 'audio/webm;codecs=opus');
check('webm с кодеком принят', $r['ok'], TRUE);
check('расширение для webm', $r['ext'], 'webm');

check('mp4 (Safari) даёт m4a', Service_Nj677_Talk::audio_ok(20000, 'audio/mp4')['ext'], 'm4a');
check('ogg даёт ogg', Service_Nj677_Talk::audio_ok(20000, 'audio/ogg')['ext'], 'ogg');
check('незнакомый тип не отказ', Service_Nj677_Talk::audio_ok(20000, 'application/x-unknown')['ok'], TRUE);

// --- разбор распознавания -------------------------------------------------

check('JSON с text', Service_Nj665_Voice::parse_transcription('{"text":" привет "}'), 'привет');
check('JSON с data.text', Service_Nj665_Voice::parse_transcription('{"data":{"text":"как дела"}}'), 'как дела');
check('JSON со списком results', Service_Nj665_Voice::parse_transcription('{"results":[{"text":"раз"},{"text":"два"}]}'), 'раз два');
check('простой текст тоже ответ', Service_Nj665_Voice::parse_transcription('просто текст'), 'просто текст');
check('пустое тело — пусто', Service_Nj665_Voice::parse_transcription(''), '');
check('битый JSON не ломает разбор', Service_Nj665_Voice::parse_transcription('{', 'application/json'), '');

// --- выдумки распознавателя на тишине -------------------------------------

// Живой случай владельца (26.10.2026): человек молчал, а в разговоре появились
// титры обучающего видео. Такая реплика не должна дойти до модели.
$junk = [
    'Редактор субтитров А.Семкин Корректор А.Егорова',
    'Редактор субтитров М.Лосева Корректор А.Егорова',
    'Продолжение следует...',
    'Спасибо за просмотр',
    'Субтитры сделал DimaTorzok',
    'Подпишись на канал',
    '[музыка]',
    '(шум)',
    'да да да да да да',
    '...',
];

foreach ($junk as $sample)
{
    check('шум отклонён: '.mb_substr($sample, 0, 44), Service_Nj677_Talk::plausible_speech($sample)['ok'], FALSE);
}

$real = [
    'Спасибо за просмотр презентации, теперь к бюджету',
    'Редактор субтитров сломался, покажи другой файл',
    'Продолжение следует из договора, пункт третий',
    'Да',
    'Привет, как дела?',
    'Что-то щёлкнуло и свет погас',
];

foreach ($real as $sample)
{
    check('живая речь принята: '.mb_substr($sample, 0, 44), Service_Nj677_Talk::plausible_speech($sample)['ok'], TRUE);
}

// Совсем короткий голос — это щелчок, а не слова.
check('230 мс голоса отклонены', Service_Nj677_Talk::accept_speech('привет', 230)['ok'], FALSE);
check('600 мс голоса приняты', Service_Nj677_Talk::accept_speech('привет', 600)['ok'], TRUE);
check('без сведений о длительности реплика не теряется', Service_Nj677_Talk::accept_speech('привет', 0)['ok'], TRUE);
check('выдумка отклонена и в accept_speech', Service_Nj677_Talk::accept_speech('Продолжение следует...', 900)['ok'], FALSE);

// --- тема разговора -------------------------------------------------------

check('короткая тема остаётся как есть', Service_Nj677_Talk::digest('Как настроить VPN'), 'Как настроить VPN');
check_true('длинная тема обрезана многоточием', mb_strlen(Service_Nj677_Talk::digest(str_repeat('слово ', 40))) <= 83);

echo "\n";
if ($bad > 0)
{
    echo "ПРОВАЛЕНО: $bad из ".($ok + $bad)." проверок\n";
    exit(1);
}

echo "nj677_talk: ВСЁ ОК — проверок $ok\n";
