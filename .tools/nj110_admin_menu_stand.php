<?php
/**
 * Стенд меню nj110 для АДМИН-контекста (06.10.2026, решение владельца):
 * короткое меню одинаково для гостя и администратора;
 * инструменты платформы по-прежнему доступны на /admin
 * (Service_Nj110_Catalog::admin_tools()).
 *
 * Отдельный файл от nj110_menu_stand.php: user_context() кэшируется
 * статикой, смешать гостя и админа в одном процессе нельзя.
 *
 *   N2J_ROOT=/path/to/n2j.ru node phpwasm_stand.mjs .tools/nj110_admin_menu_stand.php
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
    public static function current()
    {
        return new class {
            public function uri() { return 'dashboard'; }
        };
    }
}

// Админ-контекст: Auth всегда «залогинен админом».
class Stand_Role
{
    public $name;

    public function __construct($name)
    {
        $this->name = $name;
    }
}

class Stand_Roles implements IteratorAggregate
{
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->find_all());
    }

    public function find_all()
    {
        return [new Stand_Role('login'), new Stand_Role('admin')];
    }
}

class Stand_User
{
    public $roles;

    public function __construct()
    {
        $this->roles = new Stand_Roles();
    }
}

class Auth
{
    public static function instance()
    {
        return new Auth();
    }

    public function logged_in($role = NULL)
    {
        return TRUE;
    }

    public function get_user()
    {
        return new Stand_User();
    }
}

Kohana::$config = new Config;
Kohana::$log    = new class { public function add($l, $m, $v = []) { } };

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

echo "1. Контекст — админ\n";
$ctx = Service_Nj110_Catalog::user_context();
check('admin в контексте', $ctx['admin'], TRUE);

echo "\n2. Короткое меню администратора\n";
$items = Service_Nj110_Catalog::menu_items();
$platform = [];

foreach ($items as $i)
{
    if ((string) ($i['group'] ?? '') === 'Платформа')
    {
        $platform[(int) $i['sort']] = (string) $i['href'];
    }
}

ksort($platform);
check('группы Платформа нет', $platform, []);
check('состав меню', array_column($items, 'href'), ['/services', '/trends', '/jobs', '/graph3d']);

$menu_hrefs = array_map(function ($i) { return (string) ($i['href'] ?? ''); }, $items);
foreach (['/mcp', '/nj516/admin', '/nj600/admin', '/nj601/admin', '/content', '/nj603/admin', '/inbox', '/s', '/digest'] as $gone)
{
    check('«'.$gone.'» в панели отсутствует', in_array($gone, $menu_hrefs, TRUE), FALSE);
}

echo "\n3. «Все сервисы» — в группе «Сервисы»\n";
$services_group = [];
foreach ($items as $i)
{
    if ((string) ($i['group'] ?? '') === 'Сервисы')
    {
        $services_group[] = (string) $i['href'];
    }
}
check('«Все сервисы» на месте', in_array('/services', $services_group, TRUE), TRUE);

echo "\n4. admin_tools(): инструменты для /admin\n";
$tools = Service_Nj110_Catalog::admin_tools();

check('инструментов не меньше дюжины', count($tools) >= 12, TRUE);

$tool_hrefs = array_map(function ($t) { return (string) $t['href']; }, $tools);
check('href уникальны', count($tool_hrefs), count(array_unique($tool_hrefs)));

$sorts = array_map(function ($t) { return (int) $t['sort']; }, $tools);
$sorted = $sorts;
asort($sorted);
check('отсортированы по sort', implode(',', $sorts), implode(',', $sorted));

foreach (['/migrate_pg', '/erp218', '/mcp', '/nj600/admin', '/nj603/admin'] as $href)
{
    check('инструмент '.$href.' есть', in_array($href, $tool_hrefs, TRUE), TRUE);
}

$modules = Kohana::modules();
$tools_ok = TRUE;
foreach ($tools as $t)
{
    if ( ! array_key_exists($t['module'], $modules))
    {
        $bad++;
        $tools_ok = FALSE;
        echo "  ФЕЙЛ инструмент ссылается на неподключённый модуль: {$t['module']}\n";
    }
}
if ($tools_ok)
{
    $ok++;
    echo "  ок   все инструменты ссылаются на подключённые модули\n";
}

echo "\n".($bad === 0 ? "ВСЁ ОК: проверок $ok" : "ПРОВАЛОВ: $bad из ".($ok + $bad))."\n";

exit($bad ? 1 : 0);
