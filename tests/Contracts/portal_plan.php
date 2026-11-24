<?php
/** Pure state filters: no bootstrap, session or database. */
define('SYSPATH', dirname(__DIR__, 2).'/system/');
require dirname(__DIR__, 2).'/application/modules/prx112_portal_plan/classes/Service/Prx112/Plan.php';

$approved1 = ['title' => 'Каталог', 'state' => 'approved'];
$approved2 = ['title' => 'Справочник', 'state' => 'approved'];
$approved3 = ['title' => 'Документы', 'state' => 'approved'];
$wip = ['title' => 'Импорт', 'state' => 'wip'];
$archived = ['title' => 'Отчёты', 'state' => 'archived'];
$domains = [
    'Система' => [$approved1, ['state' => 'unpublished'], $approved2],
    'Архив' => [$archived],
    'Документы' => [$wip, $approved3, ['state' => 'planned']],
    'Пустой домен' => [],
];
$counts = ['all' => 7, 'approved' => 3, 'wip' => 1, 'unpublished' => 1, 'archived' => 1, 'planned' => 1];
if (Service_Prx112_Plan::counts($domains) !== $counts) {
    throw new RuntimeException('Counts must include every state and the total');
}
foreach (['all', 'unknown'] as $state) {
    if (Service_Prx112_Plan::filter($domains, $state) !== $domains) {
        throw new RuntimeException('All or unknown state must preserve the complete fixture');
    }
}
$approved = Service_Prx112_Plan::filter($domains, 'approved');
if (array_keys($approved) !== ['Система', 'Документы']
    || array_values($approved['Система']) !== [$approved1, $approved2]
    || array_values($approved['Документы']) !== [$approved3]) {
    throw new RuntimeException('Filtering must retain matching entries and order, omitting empty domains');
}
if (Service_Prx112_Plan::filter($domains, 'archived') !== ['Архив' => [$archived]]
    || Service_Prx112_Plan::filter(['Архив' => [$archived]], 'wip') !== []) {
    throw new RuntimeException('Archive and empty filter result do not match');
}
if (Service_Prx112_Plan::counts($domains) !== $counts
    || Service_Prx112_Plan::counts([]) !== array_fill_keys(array_keys($counts), 0)) {
    throw new RuntimeException('Filtering must not change full counts; empty counts must remain available');
}
echo "Portal plan: state counts and stable filtering OK\n";
