<?php
/**
 * Стенд ранкера рекомендаций nj600 (Service_Nj600_Reco::rank) — без базы.
 *
 * Запуск без PHP в системе — через PHP-in-WASM (см. .tools/phpwasm_stand.mjs):
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   N2J_ROOT=/home/user/n2j.ru node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj600_reco_stand.php
 *
 * Проверяются РЕШЕНИЯ ранкера: правило администратора первое, block и отказ
 * владельца исключают кандидата, посещённое не советуется (кроме правил),
 * похожие посетители выше «того же раздела», адресное правило вне каталога
 * с собственным заголовком проходит, внешние адреса отбрасываются.
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

class Log { const WARNING = 3; }
class Kohana { public static $config; public static $log; }
Kohana::$log = new class { public function add($l, $m, $v = []) { } };

require '/app/application/modules/nj600_prefs/classes/Service/Nj600/Reco.php';

$fails = 0;
$ok = function ($cond, $name) use (&$fails) {
    echo ($cond ? '  ok   ' : '  FAIL ').$name."\n";

    if ( ! $cond)
    {
        $fails++;
    }
};

$catalog = [
    'vat'     => ['section' => 'vat',     'href' => '/vat',     'title' => 'Калькулятор НДС',   'short' => '', 'icon' => 'fas fa-percent', 'group' => 'Финансы'],
    'loan'    => ['section' => 'loan',    'href' => '/loan',    'title' => 'Кредитный калькулятор', 'short' => '', 'icon' => 'fas fa-coins', 'group' => 'Финансы'],
    'percent' => ['section' => 'percent', 'href' => '/percent', 'title' => 'Проценты',           'short' => '', 'icon' => 'fas fa-percent', 'group' => 'Финансы'],
    'jobs'    => ['section' => 'jobs',    'href' => '/jobs',    'title' => 'Поиск вакансий',     'short' => '', 'icon' => 'fas fa-briefcase', 'group' => 'Работа'],
    'docs'    => ['section' => 'docs',    'href' => '/docs/wizard', 'title' => 'Документы организации', 'short' => '', 'icon' => 'fas fa-file', 'group' => 'Бизнес'],
    'qr'      => ['section' => 'qr',      'href' => '/qr',      'title' => 'QR-код',             'short' => '', 'icon' => 'fas fa-qrcode', 'group' => 'Инструменты'],
];

echo "1. Пустой профиль, без правил — пусто\n";
$r = Service_Nj600_Reco::rank([], $catalog, [], [], [], 4);
$ok($r === [], 'нет сигналов → нет рекомендаций');

echo "2. Общее правило администратора работает и для новичка\n";
$rules = [['id' => 1, 'kind' => 'boost', 'when_section' => '', 'when_owner' => '', 'href' => '/qr', 'title' => '', 'note' => 'начните с QR', 'weight' => 10]];
$r = Service_Nj600_Reco::rank([], $catalog, $rules, [], [], 4);
$ok(count($r) === 1 AND $r[0]['href'] === '/qr' AND $r[0]['source'] === 'rule', 'правило → /qr из каталога');
$ok($r[0]['score'] === 1010 AND $r[0]['short'] === 'начните с QR', 'вес 1000+10, note стал подписью');

echo "3. Посетитель НДС: похожие → loan, группа → percent; vat не советуем\n";
$sections = ['vat' => 12, 'qr' => 1];
$covisit  = [['section' => 'loan', 'owners' => 7], ['section' => 'jobs', 'owners' => 2], ['section' => 'vat', 'owners' => 9]];
$r = Service_Nj600_Reco::rank($sections, $catalog, [], $covisit, [], 10);
$hrefs = array_column($r, 'href');
$ok( ! in_array('/vat', $hrefs, TRUE) AND ! in_array('/qr', $hrefs, TRUE), 'посещённые разделы исключены');
$ok($hrefs[0] === '/loan' AND $r[0]['source'] === 'covisit' AND $r[0]['score'] === 200, 'самый частый сосед первым (100+100)');
$ok(in_array('/jobs', $hrefs, TRUE) AND in_array('/percent', $hrefs, TRUE), 'второй сосед и сервис той же группы присутствуют');
$pos_jobs = array_search('/jobs', $hrefs, TRUE);
$pos_perc = array_search('/percent', $hrefs, TRUE);
$ok($pos_jobs < $pos_perc, 'covisit (129) выше group (50)');
$ok(strpos($r[0]['reason'], 'Калькулятор НДС') !== FALSE, 'причина называет топ-раздел по-человечески');

echo "4. Block и отказ владельца исключают\n";
$rules = [
    ['id' => 2, 'kind' => 'block', 'when_section' => '', 'when_owner' => '', 'href' => '/loan', 'title' => '', 'note' => '', 'weight' => 0],
];
$r = Service_Nj600_Reco::rank($sections, $catalog, $rules, $covisit, ['/jobs'], 10);
$hrefs = array_column($r, 'href');
$ok( ! in_array('/loan', $hrefs, TRUE), 'block убрал /loan');
$ok( ! in_array('/jobs', $hrefs, TRUE), 'отказ владельца убрал /jobs');
$ok($hrefs === ['/percent'], 'остался только сервис группы');

echo "5. Правило «интересующимся jobs» не срабатывает у того, кто jobs не смотрел\n";
$rules = [['id' => 3, 'kind' => 'boost', 'when_section' => 'jobs', 'when_owner' => '', 'href' => '/docs/wizard', 'title' => '', 'note' => '', 'weight' => 50]];
$r = Service_Nj600_Reco::rank($sections, $catalog, $rules, [], [], 10);
$ok( ! in_array('/docs/wizard', array_column($r, 'href'), TRUE), 'условие по разделу соблюдено');
$r = Service_Nj600_Reco::rank(['jobs' => 3], $catalog, $rules, [], [], 10);
$ok($r[0]['href'] === '/docs/wizard' AND $r[0]['score'] === 1050, 'а у интересующегося jobs — первым');

echo "6. Адресное правило сильнее «уже посещал» и может вести вне каталога\n";
$rules = [
    ['id' => 4, 'kind' => 'boost', 'when_section' => '', 'when_owner' => 'u:7', 'href' => '/vat', 'title' => '', 'note' => '', 'weight' => 90],
    ['id' => 5, 'kind' => 'boost', 'when_section' => '', 'when_owner' => 'u:7', 'href' => '/guide/vat', 'title' => 'Инструкция по НДС', 'note' => '', 'weight' => 80],
    ['id' => 6, 'kind' => 'boost', 'when_section' => '', 'when_owner' => 'u:7', 'href' => '/nowhere', 'title' => '', 'note' => '', 'weight' => 99],
    ['id' => 7, 'kind' => 'boost', 'when_section' => '', 'when_owner' => 'u:7', 'href' => 'https://evil.example/x', 'title' => 'Внешний', 'note' => '', 'weight' => 999],
];
$r = Service_Nj600_Reco::rank($sections, $catalog, $rules, [], [], 10);
$hrefs = array_column($r, 'href');
$ok($hrefs[0] === '/vat' AND strpos($r[0]['reason'], 'лично') !== FALSE, 'закреплённое админом показывается даже если раздел посещён');
$ok(in_array('/guide/vat', $hrefs, TRUE) AND $r[1]['title'] === 'Инструкция по НДС', 'адрес вне каталога проходит, если у правила есть заголовок');
$ok( ! in_array('/nowhere', $hrefs, TRUE), 'адрес вне каталога без заголовка — отброшен');
$ok( ! in_array('https://evil.example/x', $hrefs, TRUE), 'внешний адрес отброшен');

echo "7. Лимит и дедупликация\n";
$covisit = [['section' => 'loan', 'owners' => 5], ['section' => 'percent', 'owners' => 5], ['section' => 'jobs', 'owners' => 1]];
$r = Service_Nj600_Reco::rank(['vat' => 3], $catalog, [], $covisit, [], 2);
$ok(count($r) === 2, 'limit=2 соблюдён');
$ok(count(array_unique(array_column($r, 'href'))) === count($r), 'дублей href нет');

echo "8. Нормализация адресов\n";
$ok(Service_Nj600_Reco::clean_href(' /vat ') === '/vat', 'обрезка пробелов');
$ok(Service_Nj600_Reco::clean_href('//evil') === '', 'protocol-relative отброшен');
$ok(Service_Nj600_Reco::clean_href('vat') === '', 'относительный без слэша отброшен');
$ok(Service_Nj600_Reco::clean_href('/a"b') === '', 'кавычка отброшена');
$ok(Service_Nj600_Reco::section_of_href('/docs/wizard?x=1') === 'docs', 'раздел — первый сегмент');

echo "\n".($fails ? "ПРОВАЛОВ: $fails" : 'ВСЁ ЗЕЛЕНО')."\n";
exit($fails ? 1 : 0);
