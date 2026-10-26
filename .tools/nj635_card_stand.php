<?php
/**
 * Стенд «карточки компании» (nj635_scout) на PHP-in-WASM.
 *
 * Проверяет чистые функции, от которых зависит вся страница /check:
 * разбор ввода (ИНН/ОГРН/домен/ссылка на вакансию/название), нормализацию
 * названий для поиска вакансий, сигналы по данным реестра и вывод.
 *
 *   N2J_ROOT=/home/user/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj635_card_stand.php
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

class Arr
{
    public static function path($array, $path, $default = NULL)
    {
        return $default;
    }
}

class Config
{
    public function load($name)
    {
        return [];
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

// реальные классы: валидация ИНН/ОГРН и сборка карточки
require '/app/application/modules/nj594_orgregistry/classes/Service/Nj594/Orgs.php';
require '/app/application/modules/nj635_scout/classes/Service/Nj635/Card.php';

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

echo "1. Разбор ввода: ИНН, ОГРН, домен, ссылка, название\n";
$p = Service_Nj635_Card::parse('7707083893');
check('ИНН распознан', $p['kind'], 'inn');
check('ИНН значение', $p['inn'], '7707083893');

$p = Service_Nj635_Card::parse('1 027 700 132 195');
check('ОГРН с пробелами распознан', $p['kind'], 'ogrn');
check('ОГРН очищен от пробелов', $p['ogrn'], '1027700132195');

$p = Service_Nj635_Card::parse('sberbank.ru');
check('домен без схемы', $p['kind'], 'domain');
check('домен нормализован', $p['domain'], 'sberbank.ru');

$p = Service_Nj635_Card::parse('https://hh.ru/vacancy/123456789');
check('ссылка на вакансию — свой вид', $p['kind'], 'vacancy');
check('ссылка сохранена', $p['url'], 'https://hh.ru/vacancy/123456789');

$p = Service_Nj635_Card::parse('www.romashka.ru/about');
check('домен с www и путём', $p['domain'], 'romashka.ru');

$p = Service_Nj635_Card::parse('  ООО   «Ромашка»  ');
check('название распознано', $p['kind'], 'name');
check('пробелы сжаты', $p['company'], 'ООО «Ромашка»');

check('пустой ввод отклонён', isset(Service_Nj635_Card::parse('   ')['error']), TRUE);
$p = Service_Nj635_Card::parse('ИНН 7707083893');
check('подпись «ИНН» срезана', $p['kind'], 'inn');
check('номер после подписи', $p['inn'], '7707083893');

$p = Service_Nj635_Card::parse('ОГРН: 1027700132195');
check('подпись «ОГРН:» срезана', $p['kind'], 'ogrn');

$p = Service_Nj635_Card::parse('Инновации 2000');
check('название с «Инн» не сломано', $p['kind'], 'name');
check('название целиком', $p['company'], 'Инновации 2000');

$p = Service_Nj635_Card::parse('1234567890');
check('неверный ИНН отклонён', isset($p['error']), TRUE);
$p = Service_Nj635_Card::parse('12345');
check('непонятный номер отклонён', isset($p['error']), TRUE);
$p = Service_Nj635_Card::parse('http://localhost/secret');
check('локальный адрес отклонён', isset($p['error']), TRUE);
$p = Service_Nj635_Card::parse('http://192.168.1.1/');
check('приватный IP отклонён', isset($p['error']), TRUE);
$p = Service_Nj635_Card::parse('ftp://files.example.com/x');
check('чужая схема отклонена', isset($p['error']), TRUE);
$p = Service_Nj635_Card::parse('ab');
check('короткий ввод отклонён', isset($p['error']), TRUE);

echo "\n2. Нормализация названий (для поиска вакансий)\n";
$v = Service_Nj635_Card::name_variants('ООО «Ромашка»');
check('вариант с формой', in_array('ооо ромашка', $v, TRUE), TRUE);
check('вариант без формы', in_array('ромашка', $v, TRUE), TRUE);

$v = Service_Nj635_Card::name_variants('ООО "Ёлка-Сервис"');
check('ё заменена на е', in_array('ооо елка-сервис', $v, TRUE), TRUE);
check('без формы и кавычек', in_array('елка-сервис', $v, TRUE), TRUE);

$v = Service_Nj635_Card::name_variants('ПАО Ромашка');
check('ПАО снято', in_array('ромашка', $v, TRUE), TRUE);

echo "\n3. Ключи для поиска вакансий\n";
$keys = Service_Nj635_Card::vacancy_keys(
    ['short_name' => 'ООО «Ромашка»', 'full_name' => 'Общество с ограниченной ответственностью «Ромашка»'],
    'romashka.ru'
);
check('домен среди ключей', in_array('romashka.ru', $keys, TRUE), TRUE);
check('название среди ключей', in_array('ромашка', $keys, TRUE), TRUE);
check('ключи без дублей', count($keys), count(array_unique($keys)));

check('без данных — нет ключей', Service_Nj635_Card::vacancy_keys(NULL, '', ''), []);

echo "\n4. Сигналы по данным реестра\n";
$org = [
    'status' => 'Действующее', 'form' => 'ЮЛ', 'reg_date' => '2010-05-01',
    'director' => 'Иванов Иван Иванович', 'address' => 'Москва, ул. Пример, 1',
    'short_name' => 'ООО «Ромашка»', 'full_name' => '',
];
$sig  = Service_Nj635_Card::signals($org, ['active' => 3]);
$lvls = array_column($sig, 'level');
check('нет стоп-сигналов', in_array('danger', $lvls, TRUE), FALSE);
check('есть сигнал о вакансиях', in_array('ok', $lvls, TRUE), TRUE);

$sig  = Service_Nj635_Card::signals(['status' => 'Ликвидация юридического лица'] + $org, ['active' => 0]);
$lvls = array_column($sig, 'level');
check('ликвидация — стоп-сигнал', in_array('danger', $lvls, TRUE), TRUE);

$sig  = Service_Nj635_Card::signals(['status' => 'Действующее', 'form' => 'ИП', 'reg_date' => date('Y-m-d', time() - 86400 * 30), 'director' => '', 'address' => '', 'short_name' => 'ИП Петров'], ['active' => 0]);
$lvls = array_column($sig, 'level');
check('молодая ИП — предупреждение', in_array('warn', $lvls, TRUE), TRUE);

$sig = Service_Nj635_Card::signals(NULL, ['active' => 0]);
check('без организации — подсказка', $sig[0]['level'], 'info');

echo "\n5. Вывод\n";
check('опасный сигнал → вывод «danger»', Service_Nj635_Card::verdict($org, [['level' => 'danger']])['level'], 'danger');
check('предупреждение → вывод «warn»', Service_Nj635_Card::verdict($org, [['level' => 'warn']])['level'], 'warn');
check('чистые данные → вывод «ok»', Service_Nj635_Card::verdict($org, [['level' => 'ok'], ['level' => 'info']])['level'], 'ok');
check('нет организации → «info»', Service_Nj635_Card::verdict(NULL, [])['level'], 'info');

echo "\n";
echo $bad === 0 ? "ВСЁ ПРОШЛО: {$ok} проверок\n" : "ПРОВАЛОВ: {$bad} из ".($ok + $bad)."\n";

exit($bad === 0 ? 0 : 1);
