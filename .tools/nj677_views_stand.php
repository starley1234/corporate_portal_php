<?php
/**
 * Рендер-стенд nj677_talk: страница разговора, скрипт и карточка каталога.
 *
 *   N2J_ROOT=$(pwd) node .tools/phpwasm_stand.mjs .tools/nj677_views_stand.php
 *
 * Смысл: проверить, что вьюхи падают не на «красивой» разметке, а на данных.
 * Разговор живёт в двух состояниях — сервис настроен и не настроен, — и оба
 * обязаны рендериться без предупреждений: второе состояние видит каждый, кто
 * пришёл до настройки OmniVoice.
 *
 * Partial'ы (page_header, prefs) подменены заглушкой: их проверяют свои стенды,
 * а здесь важен только текст самой страницы.
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

class View
{
    public static function factory($name, $data = [])
    {
        return new View_Stub((string) $name);
    }
}

class View_Stub
{
    public $name;

    public function __construct($name) { $this->name = $name; }

    public function __toString() { return ''; }
}

class HTML
{
    public static function chars($value, $double_encode = TRUE)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8', $double_encode);
    }
}

class Arr
{
    public static function get($array, $key, $default = NULL)
    {
        if ( ! is_array($array))
        {
            return $default;
        }

        return array_key_exists($key, $array) ? $array[$key] : $default;
    }
}

// Контроллеры обязаны хотя бы разобраться парсером: опечатка в имени класса
// или незакрытая скобка находятся здесь, а не на первом живом запросе.
class Controller {}
class Controller_Erp100_Base extends Controller {}

require '/app/application/modules/nj677_talk/classes/Controller/Nj677/Talk.php';
require '/app/application/modules/nj677_talk/classes/Controller/Nj677/Api.php';

$bad = 0;

function ok($label, $condition)
{
    global $bad;

    if ($condition)
    {
        echo "ok   $label\n";
    }
    else
    {
        $bad++;
        echo "ФЕЙЛ $label\n";
    }
}

/**
 * Рендер вьюхи. include идёт на верхнем уровне: внутри функции переменные
 * страницы ($enabled, $messages …) были бы не видны и стенд «проверял» пустую
 * страницу с предупреждениями — ложный успех.
 */
function render($path, array $vars = [])
{
    extract($vars, EXTR_SKIP);

    ob_start();
    include $path;

    return ob_get_clean();
}

$index = '/app/application/modules/nj677_talk/views/677/index.php';

// --- состояние 1: сервис не настроен -------------------------------------

$enabled   = FALSE;
$missing   = 'Не настроен OmniVoice: задайте NJ665_API_URL и NJ665_API_KEY в .env.';
$voices    = [];
$voice     = '';
$rate_text = '20 в минуту и 240 в час';
$recent    = [];
$limits    = ['tts_chars' => 320, 'audio_sec' => 60];
$csrf      = 'csrf-token';
$talk      = NULL;
$messages  = [];

$out = render($index, get_defined_vars());

ok('без настройки страница честно объясняет причину', strpos($out, 'Разговор пока не включён') !== FALSE);
ok('без настройки названа нужная переменная', strpos($out, 'NJ665_API_URL') !== FALSE);
ok('без настройки нет кнопки микрофона', strpos($out, 'Начать разговор') === FALSE);

// --- состояние 2: разговор идёт, история есть -----------------------------

$enabled   = TRUE;
$missing   = '';
$voices    = ['ru-female-1', 'ru-male-2'];
$voice     = 'ru-male-2';
$rate_text = '20 в минуту и 240 в час';
$talk      = ['id' => 3, 'code' => 'abc2345xyz', 'title' => 'Настройка VPN', 'voice' => 'ru-male-2', 'turns' => 2];
$messages  = [
    ['role' => 'user', 'text' => 'Как настроить VPN?', 'ms' => 4700],
    ['role' => 'assistant', 'text' => 'Откройте настройки сети и добавьте профиль.', 'ms' => 2100],
];
$recent = [
    ['code' => 'abc2345xyz', 'title' => 'Настройка VPN', 'turns' => 2],
];

$out = render($index, get_defined_vars());

ok('кнопка включения режима одна', substr_count($out, 'id="talk-start"') === 1);
ok('есть кнопка «Закончить»', strpos($out, 'Закончить') !== FALSE);
ok('есть выход текстом, если микрофона нет', strpos($out, 'Или напишите сообщение') !== FALSE);
ok('перебивание выключено по умолчанию', strpos($out, 'Перебивать голосом') !== FALSE
    AND preg_match('~id="talk-barge"[^>]*checked~', $out) !== 1);
ok('рядом сказано, что микрофон берётся только на реплику', strpos($out, 'только на время вашей реплики') !== FALSE);
ok('сигнал перед слушанием можно выключить', strpos($out, 'id="talk-cue"') !== FALSE);
ok('в пояснении сказано, что во время ответа микрофон выключен', strpos($out, 'микрофон выключен') !== FALSE);
ok('выбранный голос отмечен в списке', strpos($out, 'value="ru-male-2" selected') !== FALSE);
ok('история разговора отрисована', strpos($out, 'Как настроить VPN?') !== FALSE);
ok('время шага показано человеку', strpos($out, 'распознано · 4,7 с') !== FALSE);
ok('список последних тем на месте', strpos($out, 'Настройка VPN') !== FALSE);
ok('конфиг для скрипта отдан JSON', strpos($out, 'id="talk-config"') !== FALSE);
ok('CSRF уехал в конфиг', strpos($out, 'csrf-token') !== FALSE);
ok('шаблоны реплик кладутся в разметку', strpos($out, 'id="talk-tpl-user"') !== FALSE);
ok('нет устаревших «полос прогресса»', strpos($out, 'n2j-progress') === FALSE);

// --- скрипт --------------------------------------------------------------

$out = render('/app/application/modules/nj677_talk/views/677/script.php');

ok('скрипт просит микрофон', strpos($out, 'getUserMedia') !== FALSE);
ok('скрипт пишет через MediaRecorder', strpos($out, 'MediaRecorder') !== FALSE);
ok('конец фразы определяет тишина', strpos($out, 'silence_ms') !== FALSE);
ok('анализ громкости через Web Audio', strpos($out, 'getFloatTimeDomainData') !== FALSE);
ok('есть подстройка порога под шум', strpos($out, 'noiseFloor') !== FALSE);
ok('озвучка идёт по частям с предзагрузкой', strpos($out, 'pending[queue[0]]') !== FALSE);
ok('заговорил — ответ замолкает', strpos($out, 'playbackStop') !== FALSE AND strpos($out, 'bargeCheck') !== FALSE);
ok('ошибки браузера доходят до человека', strpos($out, 'notify') !== FALSE);
ok('три точки API названы', strpos($out, 'cfg.url_hear') !== FALSE AND strpos($out, 'cfg.url_reply') !== FALSE AND strpos($out, 'cfg.url_say') !== FALSE);
ok('без кнопок старт/стоп на реплику', strpos($out, 'stopSegment') !== FALSE AND strpos($out, 'talk-start') !== FALSE);
ok('перед слушанием звучит сигнал', strpos($out, "listenSoon('listen'") !== FALSE AND strpos($out, 'function cue') !== FALSE);
ok('сигнал звонкий, а не глухой', strpos($out, '1046.5') !== FALSE AND strpos($out, '1568') !== FALSE);
ok('спящий AudioContext будится перед сигналом', strpos($out, "ctx.state === 'suspended'") !== FALSE);
ok('микрофон освобождается, пока страница говорит', strpos($out, 'function release') !== FALSE AND strpos($out, 'release();') !== FALSE);
ok('микрофон берётся на время слушания', strpos($out, 'function acquire') !== FALSE);
ok('без перебивания эхоподавление не включаем', strpos($out, 'echoCancellation: echo') !== FALSE);
ok('после ответа есть пауза на затухание колонок', strpos($out, 'finishSpeaking') !== FALSE);
ok('перебивание требует громкого и долгого голоса', strpos($out, 'echoBase') !== FALSE AND strpos($out, 'bargeTicks') !== FALSE);
ok('короткая запись отменяется до отправки', strpos($out, 'min_voiced_ms') !== FALSE);
ok('длительность голоса уходит на сервер', strpos($out, "form.append('voiced_ms'") !== FALSE);
ok('свой ответ не принимается за реплику', strpos($out, 'looksLikeEcho') !== FALSE);
ok('запись не идёт в состоянии thinking', strpos($out, "S.state === 'thinking'") !== FALSE);

// --- карточка каталога ---------------------------------------------------

$out = render('/app/application/modules/nj677_talk/views/nj677/card.php');

ok('карточка ведёт на /talk', strpos($out, 'href="/talk"') !== FALSE);
ok('карточка объясняет режим без кнопок', strpos($out, 'конец фразы определяется сам') !== FALSE);

// --- контроллеры ---------------------------------------------------------

ok('страница объявляет три точки API-контракта',
    method_exists('Controller_Nj677_Api', 'action_hear')
    AND method_exists('Controller_Nj677_Api', 'action_reply')
    AND method_exists('Controller_Nj677_Api', 'action_say'));

ok('страница разговора — одно действие', method_exists('Controller_Nj677_Talk', 'action_index'));

echo "\n";
if ($bad > 0)
{
    echo "ПРОВАЛЕНО: $bad\n";
    exit(1);
}

echo "nj677_talk views: ВСЁ ОК\n";
