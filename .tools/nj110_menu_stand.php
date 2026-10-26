<?php
/**
 * Стенд короткого меню nj110_catalog: состав, порядок, активность и каталог.
 * Запуск: N2J_ROOT=<repo> node phpwasm_stand.mjs .tools/nj110_menu_stand.php
 *
 * Проверки панели читают css: в phpwasm раннер копирует только .php, поэтому
 * эти файлы надо смонтировать явно —
 *   N2J_STAND_FILES=".tools/tailwind/input.css:public_html/assets/css/app.css"
 * (обычный php их просто читает с диска).
 */

if ( ! defined('SYSPATH'))
{
    define('SYSPATH', '/app/');
}

class Kohana_Config_Group extends ArrayObject
{
    public function as_array()
    {
        return (array) $this;
    }

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
        return new Kohana_Config_Group([]);
    }
}

class Log
{
    const WARNING = 3;
    const ERROR   = 4;
}

class Kohana
{
    const DEVELOPMENT = 1;
    public static $config;
    public static $log;
    public static $environment = 1;
    public static $caching = FALSE;

    /** Модули платформы: имена папок application/modules/*, как в bootstrap. */
    public static function modules()
    {
        $out  = [];
        $base = '/app/application/modules';

        foreach (scandir($base) as $name)
        {
            if ($name[0] === '.' OR ! is_dir($base.'/'.$name))
            {
                continue;
            }

            $out[$name] = $base.'/'.$name.'/';
        }

        return $out;
    }
}

class Request
{
    public static $path = '';

    public static function current()
    {
        return new self;
    }

    public function uri()
    {
        return ltrim((string) self::$path, '/');
    }
}

class Auth
{
    public static function instance() { return new self; }
    public function logged_in($role = NULL) { return FALSE; }
}

Kohana::$config = new Config;
Kohana::$log    = new class { public function add($l, $m, $v = []) { } };

// Настоящий Arr из ядра (без зависимостей) и настоящий сборщик манифестов:
// заглушки больше не подходят — Catalog::modules() читает Manifest::all().
require '/app/system/classes/Kohana/Arr.php';
require '/app/system/classes/Arr.php';
require '/app/application/classes/Manifest.php';

require '/app/application/modules/nj110_catalog/classes/Service/Nj110/Catalog.php';

$ok = 0;
$bad = 0;

function check($label, $got, $want)
{
    global $ok, $bad;
    $g = is_bool($got) ? ($got ? 'true' : 'false') : (is_array($got) ? json_encode($got, JSON_UNESCAPED_UNICODE) : (string) $got);
    $w = is_bool($want) ? ($want ? 'true' : 'false') : (is_array($want) ? json_encode($want, JSON_UNESCAPED_UNICODE) : (string) $want);

    if ($g === $w) { $ok++; echo "  ок   {$label} = {$g}\n"; }
    else          { $bad++; echo "  ФЕЙЛ {$label}: получено «{$g}», ждали «{$w}»\n"; }
}

$expected = ['/services', '/trends', '/jobs', '/graph3d'];
$guest = ['guest' => TRUE, 'logged' => FALSE, 'roles' => [], 'admin' => FALSE, 'agent' => FALSE];
foreach (['/', '/services', '/services/dev', '/trends', '/jobs', '/graph3d/projects', '/poll'] as $path)
{
    Request::$path = $path;
    $items = Service_Nj110_Catalog::menu_items();
    check('состав и порядок на '.$path, array_column($items, 'href'), $expected);
    $active = array_values(array_filter($items, function ($i) { return $i['active']; }));
    $want = ($path === '/' OR $path === '/poll') ? [] : ['/'.explode('/', trim($path, '/'))[0]];
    check('активный пункт на '.$path, array_column($active, 'href'), $want);
}
check('названия пунктов', array_column($items, 'title'),
    ['Все сервисы', 'Тренды GitHub', 'Поиск вакансий', 'Графовый 3D-конструктор']);

// …но остаются в каталоге /services и палитре (visible_modules).
// Гостю виден только публичный MCP; для залогиненного — все сервисы.
$logged = ['guest' => FALSE, 'logged' => TRUE, 'roles' => ['login'], 'admin' => FALSE, 'agent' => FALSE];

$visible_hrefs = [];
foreach (Service_Nj110_Catalog::visible_modules($logged) as $m)
{
    $visible_hrefs[] = (string) $m['href'];
}
foreach (['/mcp', '/nj600/admin', '/nj603/admin', '/digest'] as $kept)
{
    check('«'.$kept.'» в каталоге остаётся', in_array($kept, $visible_hrefs, TRUE), TRUE);
}

echo "\n5. Регистрация-гейт 06.10.2026: кто виден кому\n";
// Личные данные (nj656) — только залогиненным; публичные сервисы с гейтом
// создания (nj520 опросы, nj530 ссылки, nj650/651/652 фото-разборы) в
// каталоге остаются видимы всем — потребление публично, гейт — на создании.
$guest_hrefs = [];
foreach (Service_Nj110_Catalog::visible_modules($guest) as $m)
{
    $guest_hrefs[] = (string) $m['href'];
}

check('/meters скрыт от гостя', in_array('/meters', $guest_hrefs, TRUE), FALSE);
check('/meters виден залогиненному', in_array('/meters', $visible_hrefs, TRUE), TRUE);
check('/poll остаётся публичным (голосование)', in_array('/poll', $guest_hrefs, TRUE), TRUE);
check('/jump остаётся публичным (переходы)', in_array('/jump', $guest_hrefs, TRUE), TRUE);
check('/receipt остаётся публичным (страница и разборы)', in_array('/receipt', $guest_hrefs, TRUE), TRUE);
check('/meal остаётся публичным (норма без входа)', in_array('/meal', $guest_hrefs, TRUE), TRUE);
check('/plant остаётся публичным (страница)', in_array('/plant', $guest_hrefs, TRUE), TRUE);

$recent_badges = [];
foreach (Service_Nj110_Catalog::visible_modules($guest) as $m)
{
    if ((string) ($m['badge'] ?? '') === 'Новое')
    {
        $recent_badges[] = (string) $m['href'];
    }
}
check('бейдж «Новое» только у семи последних публичных сервисов', $recent_badges,
    ['/voice', '/multivoice', '/graph3d', '/draw2d', '/solid3d', '/process', '/mcphub']);
$old_service_badge = NULL;
foreach (Service_Nj110_Catalog::visible_modules($guest) as $m)
{
    if ((string) $m['href'] === '/arch') { $old_service_badge = (string) ($m['badge'] ?? ''); break; }
}
check('устаревший бейдж «Новое» удалён со старого сервиса', $old_service_badge, '');

$jsondecode = Service_Nj110_Catalog::get('jsondecode');
check('иконка JSON-декодера зарегистрирована и поддерживается', $jsondecode['icon'] ?? '', 'fas fa-code');
$processhub = Manifest::get('nj670_processhub');
check('манифест ProcessHub содержит даты создания и изменения',
    [$processhub['created_at'] ?? '', $processhub['updated_at'] ?? ''], ['2026-10-19', '2026-10-21']);
$aiscan = Manifest::get('nj672_aiscan');
check('манифест aiscan содержит даты создания и изменения',
    [$aiscan['created_at'] ?? '', $aiscan['updated_at'] ?? ''], ['2026-10-22', '2026-10-22']);

$m656 = Manifest::get('nj656_meters');
check('манифест nj656: audience auth', Service_Nj110_Catalog::resolve_audience($m656), 'auth');

echo "\n6. Алиас опроса: /p/<code> сохраняет навигацию раздела\n";
Request::$path = 'p/bk2ELh';
$poll_nav = Service_Nj110_Catalog::header_nav();
$poll_chip = $poll_nav['items'][0] ?? [];
check('на /p/<code> в шапке есть чип /poll', $poll_chip['href'] ?? '', '/poll');
check('чип /poll отмечен активным', $poll_chip['active'] ?? FALSE, TRUE);

$poll_menu = NULL;
foreach (Service_Nj110_Catalog::menu_items() as $item)
{
    if (($item['href'] ?? '') === '/poll') { $poll_menu = $item; break; }
}
check('опрос не добавляет пункт в короткое меню', $poll_menu === NULL, TRUE);

$template = (string) file_get_contents(dirname(__DIR__).'/application/views/template_tailwindcss.php');
check('длинная навигация сохраняет и восстанавливает scrollTop',
    strpos($template, 'n2j.sidebar.scrollTop') !== FALSE
    AND strpos($template, 'sessionStorage.setItem(sidebarScrollKey') !== FALSE
    AND strpos($template, 'sessionStorage.getItem(sidebarScrollKey') !== FALSE
    AND strpos($template, 'nav.scrollTop = Math.max') !== FALSE, TRUE);
check('Ctrl/⌘+K раскрывает меню при необходимости и фокусирует его фильтр',
    strpos($template, 'drawer(true)') !== FALSE
    AND strpos($template, "body.classList.remove('sb-collapsed')") !== FALSE
    AND strpos($template, 'search.focus();') !== FALSE, TRUE);
// 26.10.2026: свёрнутый вид (одни иконки) протекал в выезжающую шторку —
// на планшете 540px меню открывалось без подписей, поиска и заголовков групп.
// Правило: всё, что прячет подписи, живёт ТОЛЬКО в @media (min-width: 768px),
// то есть в режиме постоянной колонки.
function sb_collapsed_scoped_to_desktop($css)
{
    $desktop = [];
    $pos = 0;

    while (($start = strpos($css, '@media', $pos)) !== FALSE)
    {
        $open = strpos($css, '{', $start);

        if ($open === FALSE)
        {
            break;
        }

        $head = substr($css, $start, $open - $start);
        $depth = 0;
        $end = $open;

        for ($i = $open, $len = strlen($css); $i < $len; $i++)
        {
            if ($css[$i] === '{') { $depth++; }
            elseif ($css[$i] === '}')
            {
                $depth--;

                if ($depth === 0) { $end = $i; break; }
            }
        }

        if (strpos(str_replace(' ', '', $head), 'min-width:768px') !== FALSE)
        {
            $desktop[] = [$open, $end];
        }

        $pos = $end + 1;
    }

    $pos = 0;

    while (($at = strpos($css, '.sb-collapsed', $pos)) !== FALSE)
    {
        $inside = FALSE;

        foreach ($desktop as $range)
        {
            if ($at > $range[0] AND $at < $range[1]) { $inside = TRUE; break; }
        }

        if ( ! $inside)
        {
            return FALSE;
        }

        $pos = $at + 1;
    }

    return TRUE;
}

$css_src = (string) @file_get_contents(dirname(__DIR__).'/.tools/tailwind/input.css');
$css_built = (string) @file_get_contents(dirname(__DIR__).'/public_html/assets/css/app.css');
check('свёрнутый вид панели объявлен только для md+ (input.css)',
    $css_src !== '' AND sb_collapsed_scoped_to_desktop($css_src), TRUE);
check('свёрнутый вид панели объявлен только для md+ (собранный app.css — пересоберите css)',
    $css_built !== '' AND sb_collapsed_scoped_to_desktop($css_built), TRUE);
check('шторка запирает прокрутку страницы и уступает место колонке при переходе на md+',
    strpos($template, "body.classList.toggle('sb-drawer-open', open)") !== FALSE
    AND strpos($template, 'window.innerWidth >= 768 && sidebar.classList.contains') !== FALSE, TRUE);

$sidebar_view = (string) file_get_contents(dirname(__DIR__).'/application/views/partials/sidebar.php');
check('кнопка «Все» остаётся видна в свёрнутой панели',
    strpos($sidebar_view, '<div class="shrink-0 px-3 pt-3">') !== FALSE
    AND strpos($sidebar_view, 'id="nav-palette-open"') !== FALSE, TRUE);

// Шаблон не должен подмешивать локальное меню старых контроллеров.
class HTML
{
    public static function chars($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
$menu_items = [['title' => 'Лишний пункт', 'href' => '/extra']];
ob_start();
require '/app/application/views/partials/sidebar.php';
$rendered = ob_get_clean();
check('локальные пункты не расширяют меню', strpos($rendered, '/extra') === FALSE, TRUE);
foreach ($expected as $href)
{
    check('ссылка '.$href.' отрендерена', strpos($rendered, 'href="'.$href.'"') !== FALSE, TRUE);
}

echo "\n".($bad === 0 ? "ВСЁ ОК: проверок $ok" : "ПРОВАЛОВ: $bad из ".($ok + $bad))."\n";

exit($bad ? 1 : 0);
