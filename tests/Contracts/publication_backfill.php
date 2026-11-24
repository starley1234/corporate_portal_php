<?php
/** Pure backfill planner; no bootstrap or database. */
define('SYSPATH', dirname(__DIR__, 2).'/system/');
require dirname(__DIR__, 2).'/application/modules/prx110_catalog/classes/Service/Prx110/Publication.php';

$module = static function (string $name, array $manifest = []): array {
    return $manifest + ['module_name' => $name, 'title' => $name, 'kind' => 'module'];
};
$fixture = [
    $module('stable', ['status' => 'stable']),
    $module('draft', ['status' => 'draft']),
    $module('beta', ['status' => 'beta']),
    $module('unspecified'),
    $module('disabled', ['status' => 'stable', 'catalog' => FALSE]),
    $module('deprecated', ['status' => 'deprecated', 'catalog' => FALSE]),
    $module('manual', ['status' => 'stable', 'decision' => 'wip']),
    $module('library', ['kind' => 'library']),
    $module('platform', ['kind' => 'platform']),
    $module('no_title', ['title' => '']),
    $module('', ['title' => 'No module name']),
];
foreach (Service_Prx110_Publication::PROTECTED_MODULES as $name) {
    $fixture[] = $module($name);
}
$plan = Service_Prx110_Publication::backfill_plan($fixture);
$expected = ['stable' => 'approved', 'draft' => 'wip', 'beta' => 'wip', 'unspecified' => 'wip',
    'disabled' => 'unpublished', 'deprecated' => 'archived']
    + array_fill_keys(Service_Prx110_Publication::PROTECTED_MODULES, 'approved');
if (array_combine(array_keys($plan['insert']), array_column($plan['insert'], 'state')) !== $expected
    || $plan['keep'] !== ['manual' => 'wip'] || $plan['skip'] !== ['library', 'platform', 'no_title', '']) {
    throw new RuntimeException('Unexpected backfill plan');
}
foreach ($plan['insert'] as $name => $row) {
    $protected = in_array($name, Service_Prx110_Publication::PROTECTED_MODULES, TRUE);
    if ($row['note'] !== ($protected ? 'backfill:protected' : 'backfill') || $row['decided_by'] !== NULL) {
        throw new RuntimeException('Unexpected backfill metadata');
    }
}
foreach ($fixture as &$entry) {
    $entry['decision'] = $entry['decision'] ?? $expected[$entry['module_name']] ?? NULL;
}
unset($entry);
$repeated = Service_Prx110_Publication::backfill_plan($fixture);
if ($repeated['insert'] || $repeated['keep'] != $expected + ['manual' => 'wip']) {
    throw new RuntimeException('Backfill overwrote an existing decision');
}
echo "Publication backfill: decisions, protected modules and idempotence OK\n";
