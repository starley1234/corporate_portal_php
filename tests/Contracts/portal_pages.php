<?php
/** SSR catalog and plan with no database, bootstrap or application session. */
define('SYSPATH', dirname(__DIR__, 2).'/system/');
define('APPPATH', dirname(__DIR__, 2).'/application/');

require APPPATH.'modules/prx110_catalog/classes/Service/Prx110/Catalog.php';
require APPPATH.'modules/prx112_portal_plan/classes/Service/Prx112/Plan.php';

class HTML
{
    public static function chars($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    public static function attributes(array $attributes): string
    {
        $result = '';
        foreach ($attributes as $key => $value) {
            $result .= ' '.self::chars($key).'="'.self::chars($value).'"';
        }
        return $result;
    }
}
class Security { public static function token(): string { return 'fixture-token'; } }
/** Рендер вне HTTP-цикла: текущего запроса нет — page_header обязан это пережить. */
class Request { public static function current() { return NULL; } }
class View
{
    public static function factory(string $name, array $props): string
    {
        $base = match (explode('/', $name)[0]) {
            'prx110' => 'modules/prx110_catalog/views/',
            'prx112' => 'modules/prx112_portal_plan/views/',
            default => 'views/',
        };
        extract($props, EXTR_SKIP);
        ob_start();
        require APPPATH.$base.$name.'.php';
        return ob_get_clean();
    }
}

$service = ['title' => 'Отчёты', 'href' => '/reports', 'icon' => 'fas fa-file'];
$platform = ['title' => 'Реестр модулей', 'href' => '/prx218', 'icon' => 'fas fa-cubes'];
$favorites = ['items' => [$service], 'status' => 'ready'];
$catalog = View::factory('prx110/index', [
    'total' => 2, 'nav_favorites' => $favorites,
    'groups' => [
        ['title' => 'Платформа', 'slug' => 'platforma', 'icon' => 'fas fa-cubes', 'count' => 1, 'items' => [$platform]],
        ['title' => 'Данные', 'slug' => 'dannye', 'icon' => 'fas fa-file', 'count' => 1, 'items' => [$service]],
    ],
]);
if (!preg_match('/Рабочие сервисы.*href="\/reports".*Инструменты платформы.*href="\/prx218"/s', $catalog)
    || str_contains($catalog, 'data-catalog-favorites')
    || !str_contains($catalog, 'data-nav-pin-card="/reports"')
    || !str_contains($catalog, 'id="catalog-search"')) {
    throw new RuntimeException('Catalog must separate the platform and keep stars without a second favorites list');
}

$planned = $service + ['state' => 'approved', 'publication_module' => 'prx700_reports', 'decided_at' => '2026-11-24',
    'decided_by_name' => 'admin', 'note' => 'Готов к работе', 'manifest_state' => 'wip', 'description' => 'Отчётность'];
$props = ['domains' => ['Данные' => [$planned]], 'state' => 'approved', 'nav_favorites' => $favorites];
$plan = View::factory('prx112/index', $props + ['counts' => Service_Prx112_Plan::counts($props['domains'])]);
[$visible, $disclosure] = explode('<details>', $plan, 2);
if (!str_contains($visible, 'id="portal-plan-search"')
    || !str_contains($visible, 'name="state" value="wip"')
    || !str_contains($visible, 'data-prx-confirm=')
    || str_contains($visible, 'name="state" value="archived"')
    || str_contains($visible, 'Готов к работе')
    || !str_contains($disclosure, 'name="state" value="archived"')
    || !str_contains($disclosure, 'data-prx-ok="В архив"')
    || !str_contains($disclosure, 'value="fixture-token"')
    || !str_contains($disclosure, 'name="return_state" value="approved"')
    || !str_contains($disclosure, '24.11.2026, admin')
    || !str_contains($disclosure, 'Готов к работе')
    || !str_contains($disclosure, 'По манифесту: В работе')) {
    throw new RuntimeException('Plan must keep the main action visible and move archive and metadata into the disclosure');
}
echo "Portal pages: compact plan actions, separated catalog and shared search OK\n";
