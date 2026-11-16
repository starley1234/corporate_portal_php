<?php
/**
 * Стенд выбора правила маршрута prl393 — без БД.
 *
 *   PRL_ROOT=$PWD node /tmp/phpwasm/phpwasm_stand.mjs .tools/prl393_routing_stand.php
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
    public static function document($id, array $actor = []) { return NULL; }
    public static function set_sla_snapshot($id, $due, $actor) {}
}

require dirname(__DIR__).'/application/modules/prl393_workflow/classes/Service/Prl393/Workflow.php';

function rule($code, $priority, $types, $domains, $inns, $keywords)
{
    return [
        'code' => $code, 'title' => $code, 'priority' => $priority,
        'match_types' => $types, 'match_domains' => $domains,
        'match_inns' => $inns, 'match_keywords' => $keywords,
        'stop' => FALSE,
    ];
}

$rules = [
    rule('court', 10, '{court}', '{sudrf.ru}', '{}', '{суд,извещение,дело}'),
    rule('tax', 30, '{tax}', '{nalog.ru}', '{}', '{требование,налог}'),
    rule('claim', 40, '{claim}', '{}', '{}', '{претензи}'),
];

$ok = 0;
$fail = 0;
function check($cond, $label)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ок  $label\n"; }
    else { $fail++; echo "  ФЕЙЛ $label\n"; }
}

$m = Service_Prl393_Workflow::match_rule('claim', '', '', 'претензия: обратимся в суд', $rules);
check($m && $m['code'] === 'claim', 'претензия со словом «суд» остаётся претензией');

$m = Service_Prl393_Workflow::match_rule('other', '', '', 'требование налоговой', $rules);
check($m && $m['code'] === 'tax', 'неизвестный тип + ключевое слово → налоговая');

$m = Service_Prl393_Workflow::match_rule('claim', 'sudrf.ru', '', 'претензия', $rules);
check($m && $m['code'] === 'court', 'домен суда важнее типа');

$m = Service_Prl393_Workflow::match_rule('claim', '', '7745123456', 'претензия', [
    rule('inn', 5, '{tax}', '{}', '{7745123456}', '{}'),
    rule('claim', 40, '{claim}', '{}', '{}', '{}'),
]);
check($m && $m['code'] === 'inn', 'ИНН важнее типа');

$m = Service_Prl393_Workflow::match_rule('claim', '', '', 'претензия', [
    rule('first', 10, '{claim}', '{}', '{}', '{}'),
    rule('second', 20, '{claim}', '{}', '{}', '{}'),
]);
check($m && $m['code'] === 'first', 'побеждает первое правило по приоритету');

check(Service_Prl393_Workflow::match_rule('other', '', '', 'просто письмо', $rules) === NULL, 'нет совпадения → NULL');

echo "\nитог: $ok ок, $fail фейл\n";
exit($fail ? 1 : 0);
