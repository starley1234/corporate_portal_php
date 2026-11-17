<?php
/**
 * Стенд prl398: парсинг JSON и нормализация ответа VLM. Без БД и модели.
 *
 *   PRL_ROOT=$PWD node /tmp/phpwasm/phpwasm_stand.mjs .tools/prl398_normalize_stand.php
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
    public function load($group)
    {
        $path = dirname(__DIR__).'/application/config/'.$group.'.php';
        if ($group === 'prl398_vlm')
        {
            $path = dirname(__DIR__).'/application/modules/prl398_vlm_demo/config/prl398_vlm.php';
        }

        return new Kohana_Config_Group(require $path);
    }
}

class Kohana
{
    public static $config;
    public static $environment = 'testing';
    public static $charset = 'utf-8';
    const DEVELOPMENT = 10;
}

Kohana::$config = new Kohana_Config;

require dirname(__DIR__).'/application/modules/prl398_vlm_demo/classes/Service/Prl398/Vlm.php';

$ok = 0;
$fail = 0;
function check($condition, $label)
{
    global $ok, $fail;
    if ($condition) { $ok++; echo "  ок  $label\n"; }
    else { $fail++; echo "  ФЕЙЛ $label\n"; }
}

$parsed = Service_Prl398_Vlm::parse_json_reply("```json\n{\"type\":\"claim\"}\n```");
check($parsed['type'] === 'claim', 'JSON в markdown-обёртке');

$raw = [
    'type' => 'claim',
    'type_confidence' => 95,
    'fields' => [
        'correspondent_name' => ['value' => 'ООО «Ромашка»', 'quote' => 'ООО «Ромашка»', 'confidence' => 0.9, 'page' => 1],
        'sender_inn' => ['value' => '77-45-123456', 'quote' => 'ИНН 7745123456', 'confidence' => 0.9, 'page' => 1],
        'outbound_no' => ['value' => '118', 'quote' => 'Исх. № 118', 'confidence' => 0.9, 'page' => 1],
        'outbound_date' => ['value' => '2026-11-12', 'quote' => '12.11.2026', 'confidence' => 0.9, 'page' => 1],
        'amount' => ['value' => '1 245 000,00', 'quote' => '1 245 000,00 руб.', 'confidence' => 0.9, 'page' => 2],
        'deadline_hint_days' => ['value' => '10', 'quote' => '10 календарных дней', 'confidence' => 0.9, 'page' => 1],
        'deadline_hint_unit' => ['value' => 'calendar', 'quote' => 'календарных дней', 'confidence' => 0.9, 'page' => 1],
    ],
    'summary' => 'Претензия на 1 245 000,00 руб.',
    'risk' => ['level' => 'high', 'reasons' => ['Досудебная претензия']],
    'findings' => [['kind' => 'amount', 'text' => '1 245 000,00']],
    'visual_marks' => ['печать', 'подпись'],
    'confidence' => 1.7,
];

$n = Service_Prl398_Vlm::normalize_result($raw);
check($n['type'] === 'claim', 'тип из справочника');
check(abs($n['type_confidence'] - 0.95) < 0.001, 'уверенность 95 → 0.95');
check($n['fields']['sender_inn']['value'] === '7745123456', 'ИНН очищен от дефисов');
check($n['fields']['sender_inn']['page'] === 1, 'страница сохранена');
check($n['fields']['amount']['value'] === '1 245 000,00', 'сумма не переписана');
check($n['fields']['deadline_hint_unit']['value'] === 'calendar', 'единица срока calendar');
check($n['risk']['level'] === 'high', 'риск high');
check($n['visual_marks'] === ['печать', 'подпись'], 'визуальные признаки');
check($n['confidence'] === 1.0, 'общая уверенность ограничена 1.0');

$unknown = $raw;
$unknown['type'] = 'unknown';
$unknown['type_confidence'] = 0.9;
$u = Service_Prl398_Vlm::normalize_result($unknown);
check($u['type'] === 'other', 'неизвестный тип → other');
check($u['type_title'] === 'Прочее', 'подпись типа other');

try
{
    Service_Prl398_Vlm::parse_json_reply('не JSON');
    check(FALSE, 'не-JSON должен падать');
}
catch (RuntimeException $e)
{
    check(TRUE, 'не-JSON падает');
}

echo "\nитог: $ok ок, $fail фейл\n";
exit($fail ? 1 : 0);
