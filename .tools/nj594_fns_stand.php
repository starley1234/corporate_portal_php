<?php
/**
 * Стенд лимита ФНС (nj594_orgregistry) на PHP-in-WASM.
 *
 * Повод: /check и /orgs/lookup спрашивают egrul.nalog.ru — реестр не любит
 * частых обращений. Здесь проверяется, что пороги берутся из конфига, что env
 * их переопределяет, что защитные максимумы не дают выставить ноль/минус и что
 * текст лимита для интерфейса читается человеком.
 *
 *   N2J_ROOT=/home/user/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj594_fns_stand.php
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

/** Конфиг стенда: читаем НАСТОЯЩИЙ файл конфига модуля (с env-подстановкой). */
class Config
{
    public function load($name)
    {
        if ($name !== 'nj594_orgregistry.fns')
        {
            return NULL;
        }

        $cfg = include '/app/application/modules/nj594_orgregistry/config/nj594_orgregistry.php';

        return $cfg['fns'] ?? NULL;
    }
}

class Log
{
    const WARNING = 3;
    const ERROR   = 4;
}

class Kohana
{
    public static $config;
    public static $log;
}

Kohana::$config = new Config;
Kohana::$log    = new class { public function add($l, $m, $v = []) { } };

require '/app/application/modules/nj100_platform/classes/Service/Nj100/Ratelimit.php';
require '/app/application/modules/nj594_orgregistry/classes/Service/Nj594/Orgs.php';

$ok  = 0;
$bad = 0;

function check($label, $got, $want)
{
    global $ok, $bad;
    $g = is_bool($got) ? ($got ? 'true' : 'false') : (is_array($got) ? json_encode($got, JSON_UNESCAPED_UNICODE) : (string) $got);
    $w = is_bool($want) ? ($want ? 'true' : 'false') : (is_array($want) ? json_encode($want, JSON_UNESCAPED_UNICODE) : (string) $want);

    if ($g === $w) { $ok++; echo "  ок   {$label} = {$g}\n"; }
    else          { $bad++; echo "  ФЕЙЛ {$label}: получено «{$g}», ждали «{$w}»\n"; }
}

echo "1. Пороги по умолчанию (env не задан)\n";
$l = Service_Nj594_Orgs::fns_limits();
check('запросов в минуту', $l['per_minute'], 5);
check('запросов в час', $l['per_hour'], 30);
check('общий кап платформы за час', $l['total_per_hour'], 120);
check('перерыв на пустой ответ, сек', $l['cooldown_sec'], 120);
check('таймаут запроса, сек', $l['timeout'], 12);

echo "\n2. env переопределяет пороги\n";
putenv('NJ594_FNS_PER_MINUTE=2');
putenv('NJ594_FNS_PER_HOUR=7');
putenv('NJ594_FNS_TOTAL_PER_HOUR=50');
putenv('NJ594_FNS_COOLDOWN=0');
putenv('NJ594_FNS_TIMEOUT=20');
$l = Service_Nj594_Orgs::fns_limits();
check('минута из env', $l['per_minute'], 2);
check('час из env', $l['per_hour'], 7);
check('кап из env', $l['total_per_hour'], 50);
check('перерыв выключен', $l['cooldown_sec'], 0);
check('таймаут из env', $l['timeout'], 20);

echo "\n3. Защитные границы (ноль и минус недопустимы)\n";
putenv('NJ594_FNS_PER_MINUTE=0');
putenv('NJ594_FNS_PER_HOUR=-5');
putenv('NJ594_FNS_TOTAL_PER_HOUR=0');
putenv('NJ594_FNS_TIMEOUT=1');
$l = Service_Nj594_Orgs::fns_limits();
check('минута не обнуляется', $l['per_minute'], 1);
check('час не уходит в минус', $l['per_hour'], 1);
check('кап не обнуляется', $l['total_per_hour'], 1);
check('таймаут не меньше 3 с', $l['timeout'], 3);

echo "\n4. Текст лимита для интерфейса\n";
putenv('NJ594_FNS_PER_MINUTE=5');
putenv('NJ594_FNS_PER_HOUR=30');
check('человекочитаемый лимит', Service_Nj594_Orgs::fns_limit_text(), '5 запросов в минуту, 30 в час');

echo "\n";
echo $bad === 0 ? "ВСЁ ПРОШЛО: {$ok} проверок\n" : "ПРОВАЛОВ: {$bad} из ".($ok + $bad)."\n";

exit($bad === 0 ? 0 : 1);
