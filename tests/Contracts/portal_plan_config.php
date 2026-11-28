<?php
/** План портала (/portal-plan): целостность config/portal.php и enterprise_plan.php без БД. */
define('SYSPATH', dirname(__DIR__, 2).'/system/');
define('APPPATH', dirname(__DIR__, 2).'/application/');
define('MODPATH', dirname(__DIR__, 2).'/modules/');

class Kohana { public static function modules(): array { return require APPPATH.'config/modules.php'; } }

$portal = require APPPATH.'modules/prx112_portal_plan/config/portal.php';
$enterprise = require APPPATH.'modules/prx112_portal_plan/config/enterprise_plan.php';

// Сняты из плана слиянием 28.11.2026 — ID не переиспользуются (их держит история решений).
const RETIRED = [326 => 'prx321_calendar', 385 => 'prx380_safety'];

$modules = [];
$ids = [];
$titles = [];
foreach ($portal['domains'] as $key => $domain) {
    if (empty($domain['title']) || empty($domain['services'])) throw new RuntimeException('Domain without title/services: '.$key);
    foreach ($domain['services'] as $service) {
        $name = (string) $service['module'];
        if (!preg_match('/\Aprx(\d{3})_[a-z0-9_]+\z/', $name, $m)) throw new RuntimeException('Bad module name: '.$name);
        if (isset($modules[$name])) throw new RuntimeException('Module listed twice: '.$name);
        if (isset($ids[$m[1]])) throw new RuntimeException('ID '.$m[1].' used by '.$ids[$m[1]].' and '.$name);
        if (isset(RETIRED[(int) $m[1]])) throw new RuntimeException('Retired ID reused: '.$name.' (merged into '.RETIRED[(int) $m[1]].')');
        if (!in_array($service['status'], ['active', 'planned', 'archived'], TRUE)) throw new RuntimeException('Bad status: '.$name);
        $title = mb_strtolower(trim((string) $service['title']));
        if ($title === '' || isset($titles[$title])) throw new RuntimeException('Empty or duplicate service title: '.$service['title']);
        $modules[$name] = $key;
        $ids[$m[1]] = $name;
        $titles[$title] = $name;
    }
}

$pipelines = [];
foreach ($enterprise['phases'] as $phase) {
    foreach ($phase['pipelines'] as $pipeline) {
        foreach (['title', 'flow', 'result'] as $field) {
            if (trim((string) ($pipeline[$field] ?? '')) === '') throw new RuntimeException('Pipeline without '.$field.': '.($pipeline['title'] ?? '?'));
        }
        if (isset($titles[mb_strtolower($pipeline['title'])])) throw new RuntimeException('Pipeline and service share a title: '.$pipeline['title']);
        if (empty($pipeline['services'])) throw new RuntimeException('Pipeline without services: '.$pipeline['title']);
        foreach ($pipeline['services'] as $name) {
            if (!isset($modules[$name])) throw new RuntimeException('Pipeline '.$pipeline['title'].' links unknown service '.$name);
        }
        $pipelines[] = $pipeline['title'];
    }
}

// Заголовки, на которые опирается E2E-смоук (/portal-plan).
foreach (['Лента новостей', 'Ответы на письма', 'Мой профиль сотрудника'] as $title) {
    if (!isset($titles[mb_strtolower($title)])) throw new RuntimeException('Smoke-asserted service missing: '.$title);
}
foreach (['Ведение проектов', 'Разработка конструкций', 'Разработка РЭА', 'Производство и сборка', 'Отработка рекламаций'] as $title) {
    if (!in_array($title, $pipelines, TRUE)) throw new RuntimeException('Smoke-asserted pipeline missing: '.$title);
}
if (($modules['prx400_specflow'] ?? NULL) !== 'engineering') throw new RuntimeException('prx400_specflow must anchor the engineering domain');

echo 'Portal plan: '.count($modules).' services in '.count($portal['domains']).' domains, '.count($pipelines)." pipelines linked to services\n";
