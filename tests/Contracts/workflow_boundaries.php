<?php
/** Контракты владельцев данных, используемые workflow; без БД/сети. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
class Prx_Db {
    public static array $rows = [];
    public static array $calls = [];
    public static bool $fail = FALSE;
    public static function select($sql, $params) {
        self::$calls[] = [$sql, $params];
        if (self::$fail) throw new RuntimeException('Database unavailable');
        return self::$rows;
    }
}
class WorkflowConfig {
    public function load($name) { return $this; }
    public function get($key) { return $key === 'prx391.view' ? ['login'] : NULL; }
}
class Kohana { public static $config; }
Kohana::$config = new WorkflowConfig;
foreach (['Prx/Principal', 'Prx/Actor', 'Prx/Acl', 'Prx/Policy'] as $name) require $root.'/application/classes/'.$name.'.php';
require $root.'/application/modules/prx102_core/classes/Service/Prx102/Users.php';
require $root.'/application/modules/prx391_documents/classes/Policy/Prx391/Document.php';
foreach (['Registry', 'Documents'] as $name) require $root.'/application/modules/prx391_documents/classes/Service/Prx391/'.$name.'.php';
require $root.'/application/modules/prx393_workflow/classes/Service/Prx393/Workflow.php';
class WorkflowRecipientsProbe extends Service_Prx393_Workflow {
    public static function recipients($role, $document) { return self::_role_users($role, $document); }
}
$checks = 0;
function boundary_check($actual, $expected) {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException('Workflow boundary mismatch: '.var_export($actual, TRUE));
    $checks++;
}
boundary_check(Service_Prx102_Users::actors_with_role('  '), []);
boundary_check(Service_Prx391_Documents::open_ids_for_processing(0), []);
boundary_check(Service_Prx391_Documents::open_ids_for_processing(-1), []);
boundary_check(Prx_Db::$calls, []);
Prx_Db::$rows = [['id' => '9'], ['id' => '4']];
boundary_check(Service_Prx391_Documents::open_ids_for_processing(2), [9, 4]);
boundary_check(Prx_Db::$calls[0][1], [':limit' => 2]);
boundary_check(str_contains(Prx_Db::$calls[0][0], 'received_at DESC, id DESC'), TRUE);
Prx_Db::$rows = [
    ['user_id' => '4', 'name' => 'docflow_clerk'], ['user_id' => '4', 'name' => 'login'],
    ['user_id' => '9', 'name' => 'docflow_clerk'], ['user_id' => '9', 'name' => 'docflow_lawyer'], ['user_id' => '9', 'name' => 'login'],
];
$actors = Service_Prx102_Users::actors_with_role(' docflow_clerk ');
boundary_check(array_map(fn($actor) => $actor->id(), $actors), [4, 9]);
boundary_check($actors[1]->roles(), ['docflow_clerk', 'docflow_lawyer', 'login']);
boundary_check($actors[1]->organizationId(), NULL);
boundary_check(Prx_Db::$calls[1][1], [':role' => 'docflow_clerk']);
boundary_check(WorkflowRecipientsProbe::recipients('docflow_clerk', ['sensitivity' => 'personal']), [9]);
boundary_check(WorkflowRecipientsProbe::recipients('docflow_clerk', ['sensitivity' => 'internal']), [4, 9]);
Prx_Db::$rows = [];
boundary_check(WorkflowRecipientsProbe::recipients('missing', ['sensitivity' => 'internal']), []);
Prx_Db::$fail = TRUE;
try { WorkflowRecipientsProbe::recipients('docflow_clerk', ['sensitivity' => 'internal']); throw new LogicException('Failure must propagate'); }
catch (RuntimeException $e) { boundary_check($e->getMessage(), 'Database unavailable'); }
echo "Workflow boundaries: $checks checks OK\n";
