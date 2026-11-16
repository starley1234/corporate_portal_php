<?php
/**
 * Стенд нормализации ответа ИИ (prl392). Без БД и модели.
 *
 *   PRL_ROOT=$PWD node /tmp/phpwasm/phpwasm_stand.mjs .tools/prl392_normalize_stand.php
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', dirname(__DIR__).'/system/');
}

class Kohana_Config_Group
{
    private $_values;
    public function __construct(array $values) { $this->_values = $values; }
    public function as_array() { return $this->_values; }
    public function get($key, $default = NULL) { return $this->_values[$key] ?? $default; }
}

class Kohana_Config
{
    public function load($group) { return new Kohana_Config_Group(require dirname(__DIR__).'/application/config/'.$group.'.php'); }
}

class Kohana
{
    public static $config;
    public static $environment = 'testing';
    public static $charset = 'utf-8';
    const DEVELOPMENT = 10;
}

Kohana::$config = new Kohana_Config;

class Log
{
    const ERROR = 3;
    const WARNING = 4;
    public static function instance() { return new self; }
    public function add($level, $message, array $args = []) {}
}

class Service_Prl391_Documents
{
    public static function types()
    {
        return [
            ['code' => 'claim', 'title' => 'Претензия'],
            ['code' => 'court', 'title' => 'Судебное извещение'],
            ['code' => 'tax', 'title' => 'Требование налоговой'],
            ['code' => 'other', 'title' => 'Прочее'],
        ];
    }
}

require dirname(__DIR__).'/application/modules/prl392_analysis/classes/Service/Prl392/Analysis.php';

$ok = 0;
$fail = 0;
function check($cond, $label)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ок  $label\n"; }
    else { $fail++; echo "  ФЕЙЛ $label\n"; }
}

$raw = [
    'type' => 'claim',
    'type_confidence' => 1.7,
    'fields' => [
        'correspondent_name' => ['value' => 'ООО «Ромашка»', 'quote' => 'ООО «Ромашка»', 'confidence' => 0.98],
        'sender_inn' => ['value' => '77-45-123456', 'quote' => 'ИНН 7745123456', 'confidence' => 0.9],
        'outbound_no' => ['value' => '118', 'quote' => 'Исх. № 118', 'confidence' => 0.9],
        'outbound_date' => ['value' => '2026-11-12', 'quote' => '12.11.2026', 'confidence' => 0.9],
        'amount' => ['value' => '1 245 000,00', 'quote' => '1 245 000,00 руб.', 'confidence' => 0.9],
        'deadline_hint_days' => ['value' => '10', 'quote' => '10 календарных дней', 'confidence' => 0.9],
        'deadline_hint_unit' => ['value' => 'calendar', 'quote' => 'календарных дней', 'confidence' => 0.9],
    ],
    'summary' => '<b>Претензия</b> на 1 245 000,00 руб.',
    'risk' => ['level' => 'high', 'reasons' => ['Досудебная претензия']],
    'findings' => [['kind' => 'amount', 'text' => '1 245 000,00']],
];

$n = Service_Prl392_Analysis::normalize_result($raw);
check($n['type'] === 'claim', 'тип из справочника');
check($n['type_confidence'] === 1.0, 'уверенность ограничена 1.0');
check($n['fields']['sender_inn']['value'] === '7745123456', 'ИНН очищен от дефисов');
check($n['fields']['outbound_date']['value'] === '2026-11-12', 'дата ISO');
check($n['fields']['amount']['value'] === '1245000.00', 'сумма нормализована');
check($n['fields']['deadline_hint_days']['value'] === '10', 'срок в днях');
check($n['summary'] === 'Претензия на 1 245 000,00 руб.', 'HTML из summary убран');
check($n['risk']['level'] === 'high', 'риск high');

$bad = $raw;
$bad['type'] = 'unknown';
$bad['fields'] = [];
$bad['summary'] = '';
try
{
    Service_Prl392_Analysis::normalize_result($bad);
    check(FALSE, 'пустой ответ должен падать');
}
catch (InvalidArgumentException $e)
{
    check(TRUE, 'пустой ответ падает');
}

$other = $raw;
$other['type'] = 'unknown';
$other['type_confidence'] = 0.99;
$o = Service_Prl392_Analysis::normalize_result($other);
check($o['type'] === 'other', 'неизвестный тип → other');
check($o['type_confidence'] <= 0.5, 'other не бывает сверхуверенным');

echo "\nитог: $ok ок, $fail фейл\n";
exit($fail ? 1 : 0);
