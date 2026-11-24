<?php
/** Пользовательские пути docflow не превращают пустой/старый actor в систему. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
class DocflowConfig {
    public function load($name) { return $this; }
    public function get($key) {
        global $root;
        $acl = require $root.'/application/config/acl.php';
        return $acl[$key] ?? NULL;
    }
}
class Kohana { public static $config; }
Kohana::$config = new DocflowConfig;
class HTTP_Exception extends RuntimeException {
    public static function factory($code, $message) { return new self($message, $code); }
}
foreach (['Prx/Principal', 'Prx/Actor', 'Prx/Acl', 'Prx/Policy'] as $class) require $root.'/application/classes/'.$class.'.php';
require $root.'/application/modules/prx391_documents/classes/Policy/Prx391/Document.php';
foreach (['390_intake' => ['Intake'], '391_documents' => ['Documents', 'Registry', 'Card'], '392_analysis' => ['Analysis'], '393_workflow' => ['Workflow']] as $module => $classes) {
    $id = substr($module, 0, 3);
    foreach ($classes as $class) require $root.'/application/modules/prx'.$module.'/classes/Service/Prx'.$id.'/'.$class.'.php';
}
$checks = 0;
function docflow_check($actual, $expected) { global $checks; if ($actual !== $expected) throw new RuntimeException('Docflow access mismatch'); $checks++; }
function docflow_denied(callable $operation) {
    global $checks;
    try { $operation(); } catch (HTTP_Exception $e) { docflow_check($e->getCode(), 403); return; }
    throw new RuntimeException('Expected 403 before IO');
}
foreach ([NULL, Prx_Actor::guest(), new Prx_Actor(0, ['admin']), new Prx_Actor(7, ['system']), new Prx_Actor(7, ['login'])] as $actor) {
    foreach (['public', 'internal', 'personal', 'commercial'] as $sensitivity)
        docflow_check(Service_Prx391_Documents::can_view(['sensitivity' => $sensitivity], $actor), FALSE);
    docflow_check(Service_Prx391_Documents::can_edit($actor), FALSE);
    docflow_check(Service_Prx391_Documents::document(1, $actor), NULL);
    docflow_check(Service_Prx391_Documents::visible_ids([1], $actor), []);
    docflow_check(Service_Prx391_Documents::recent_history(10, $actor), []);
    docflow_denied(fn() => Service_Prx391_Documents::list_documents([], $actor));
    docflow_denied(fn() => Service_Prx391_Documents::stats($actor));
    docflow_denied(fn() => Service_Prx390_Intake::scan_folder($actor));
    docflow_denied(fn() => Service_Prx390_Intake::receive_path('/missing', 'folder', $actor));
    docflow_denied(fn() => Service_Prx390_Intake::receive_upload([], $actor));
    docflow_denied(fn() => Service_Prx390_Intake::retry(1, $actor));
    docflow_denied(fn() => Service_Prx392_Analysis::queue_for_document(1, $actor));
    docflow_denied(fn() => Service_Prx392_Analysis::accept(1, [], $actor));
    docflow_denied(fn() => Service_Prx392_Analysis::reject(1, $actor));
    docflow_denied(fn() => Service_Prx393_Workflow::route(1, $actor));
}
$admin = new Prx_Actor(7, ['admin']);
docflow_check(Service_Prx391_Documents::can_view(['sensitivity' => 'personal'], $admin), TRUE);
docflow_check(Service_Prx391_Documents::can_edit($admin), TRUE);
foreach ([[], ['user_id' => 7, 'roles' => ['admin']], ['is_system' => TRUE], ['roles' => ['system']]] as $array) {
    try { Service_Prx391_Documents::document(1, $array); throw new RuntimeException('Legacy actor accepted'); }
    catch (TypeError $e) { $checks++; }
}
echo "Docflow access: $checks checks OK\n";
