<?php
/**
 * Стенд авто-режима «Вакансии компаний» (nj642): «только город + направление,
 * компании с сайтами сервис находит сам» (владелец, 27.09.2026).
 *
 * Запуск без PHP в системе — через PHP-in-WASM (см. .tools/phpwasm_stand.mjs):
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   N2J_ROOT=/home/user/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj642_auto_stand.php
 *
 * Проверяются РЕШЕНИЯ сервиса (без базы): кого считать авто-режимом, сколько
 * компаний искать, как чистится найденное воркером, какие домены запрещены как
 * агрегаторы, как из критерия получаются слова для онтологии.
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

// Мини-заглушки Kohana: сервису нужны только конфиг и логгер, базы в этом
// стенде нет — проверяются решения (кого считать авто-режимом, как чистится
// найденное, какие домены запрещены).
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

require '/app/application/modules/nj642_vacancies/classes/Service/Nj642/Vacancies.php';

class T extends Service_Nj642_Vacancies
{
    public static function cfg($k, $d = NULL)
    {
        return $d;   // конфиг модуля в стенде не нужен: берём дефолты
    }

    public static function cc($in, $keep = FALSE)
    {
        return parent::_clean_companies($in, $keep);
    }

    public static function words($w)
    {
        return parent::_search_words($w);
    }

    public static function blocked($h)
    {
        return parent::_blocked_host($h);
    }
}

$ok = 0;
$bad = 0;

function check($label, $got, $want)
{
    global $ok, $bad;
    $g = is_bool($got) ? ($got ? 'true' : 'false') : (is_array($got) ? implode(',', $got) : (string) $got);
    $w = is_bool($want) ? ($want ? 'true' : 'false') : (is_array($want) ? implode(',', $want) : (string) $want);

    if ($g === $w) { $ok++; echo "  ок   {$label} = {$g}\n"; }
    else          { $bad++; echo "  ФЕЙЛ {$label}: получено «{$g}», ждали «{$w}»\n"; }
}

echo "1. Авто-режим: город+направление\n";
check('направление есть', T::is_auto(['direction' => 'повар', 'keywords' => '']), TRUE);
check('только ключевые слова', T::is_auto(['direction' => '', 'keywords' => 'ночные смены']), TRUE);
check('пустой монитор', T::is_auto(['direction' => '', 'keywords' => '']), FALSE);

echo "\n2. Авто-режим — по составу компаний, а не по направлению\n";
check('пусто + город → авто', T::is_auto(['city' => 'Москва', 'direction' => '', 'companies' => '[]']), TRUE);
check('ручной список → НЕ авто (чужие компании не подмешиваются)',
    T::is_auto(['direction' => 'повар', 'companies' => json_encode([['name' => 'A', 'domain' => 'a.ru']])]), FALSE);
check('все компании найдены сервисом → авто',
    T::is_auto(['companies' => json_encode([
        ['name' => 'A', 'domain' => 'a.ru', 'auto' => TRUE],
        ['name' => 'B', 'domain' => 'b.ru', 'auto' => TRUE],
    ])]), TRUE);
check('ручная компания в авто-мониторе → НЕ авто',
    T::is_auto(['companies' => json_encode([
        ['name' => 'A', 'domain' => 'a.ru', 'auto' => TRUE],
        ['name' => 'B', 'domain' => 'b.ru'],
    ])]), FALSE);
check('совсем пустой монитор → не авто', T::is_auto(['city' => '', 'direction' => '', 'companies' => '[]']), FALSE);

echo "\n3. Сколько компаний искать\n";
check('пусто → цель 8', T::autofind_slots(['companies' => '[]']), 8);
check('одна есть → 7', T::autofind_slots(['companies' => json_encode([['name' => 'A', 'domain' => 'a.ru']])]), 7);
$many = [];

for ($i = 0; $i < 12; $i++) { $many[] = ['name' => 'К'.$i, 'domain' => 'k'.$i.'.ru']; }

check('потолок 12 → 0', T::autofind_slots(['companies' => json_encode($many)]), 0);

echo "\n4. Чистка найденного воркером (auto-компания)\n";
$got = T::cc([
    ['name' => 'Ромашка', 'domain' => 'https://www.Romashka.ru/vacancies', 'source' => 'поиск', 'why' => 'нашлась поиском'],
    ['name' => 'Ромашка-дубль', 'domain' => 'romashka.ru'],
    ['name' => '', 'domain' => ''],
], TRUE);

check('после чистки компаний', count($got), 1);
check('домен без схемы и www', $got[0]['domain'], 'romashka.ru');
check('пометка источника сохранена', $got[0]['source'], 'поиск');
check('пояснение сохранено', $got[0]['why'], 'нашлась поиском');
check('дубль по домену не продублирован', $got[0]['name'], 'Ромашка');

$plain = T::cc([['name' => 'Ромашка', 'domain' => 'romashka.ru', 'source' => 'поиск']], FALSE);
check('без $keep пометок нет', isset($plain[0]['source']) ? 'есть' : 'нет', 'нет');

echo "\n5. Агрегаторы — не сайты работодателей\n";
check('hh.ru', T::blocked('hh.ru'), TRUE);
check('www.superjob.ru', T::blocked('www.superjob.ru'), TRUE);
check('2gis.ru', T::blocked('2gis.ru'), TRUE);
check('romashka.ru', T::blocked('romashka.ru'), FALSE);
check('пустой хост', T::blocked(''), FALSE);

echo "\n6. Слова для карты отрасли (nj607)\n";
check('город+направление', T::words(['direction' => 'Повар', 'keywords' => '', 'city' => 'Москва']),
    ['повар', 'москва']);
check('стоп-слова отброшены', T::words(['direction' => 'вакансии работа', 'keywords' => 'сварщик', 'city' => 'Москва']),
    ['сварщик', 'москва']);
check('ключ компании по домену', T::company_key('Ромашка', 'https://www.romashka.ru/x'), 'romashka.ru');

echo "\n".($bad === 0 ? "ВСЁ ОК: проверок $ok" : "ПРОВАЛОВ: $bad из ".($ok + $bad))."\n";
