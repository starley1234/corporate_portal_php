<?php
/** Реальная CLI-задача и ACL, внешние ORM/CSV/DB-границы подменены. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
class CliConfig {
    public function load($name) { return $this; }
    public function get($key) { global $root; $acl = require $root.'/application/config/acl.php'; return $acl[$key] ?? NULL; }
}
class Kohana { public static $config; }
Kohana::$config = new CliConfig;
require $root.'/application/classes/Prx/Principal.php';
require $root.'/application/classes/Prx/Acl.php';
require $root.'/application/classes/Prx/Actor.php';
class Minion_Task {}
class Minion_CLI { public static function write($text) {} }
class CliRoles {
    public function find_all() { return array_map(fn($name) => (object) ['name' => $name], ORM::$roleNames); }
}
class ORM {
    public static $roleNames = ['login'];
    public $id = 17;
    public $roles;
    public function __construct() { $this->roles = new CliRoles; }
    public static function factory($name, $id) { return new self; }
    public function loaded() { return TRUE; }
}
class Service_Prx300_Identity {
    public static function resolve_user($identifier) { return $identifier === 'missing' ? NULL : ['id' => 17]; }
}
class Service_Prx300_Directory {
    public static $profile = NULL;
    public static function profile_for_user($id) { return self::$profile; }
}
class Service_Prx300_Import {
    public static $actor;
    public static $applied = 0;
    public static function parse_csv_file($file) { return []; }
    public static function stage_rows(Prx_Actor $actor, $source, $rows, $file, $hash) { self::$actor = $actor; return 1; }
    public static function batch(Prx_Actor $actor, $id, $include) {
        return ['status' => 'validated', 'total_rows' => 1, 'error_rows' => 0, 'skipped_count' => 0];
    }
    public static function apply_batch(Prx_Actor $actor, $id) {
        self::$applied++;
        return ['created_count' => 1, 'updated_count' => 0, 'skipped_count' => 0];
    }
}
require $root.'/application/modules/prx300_employee/classes/Policy/Prx300/Employee.php';
require $root.'/application/modules/prx300_employee/classes/Task/Prx300/Import.php';
class CliTaskHarness extends Task_Prx300_Import { public function run(array $params) { $this->_execute($params); } }
$task = new CliTaskHarness;
$checks = 0;
function cli_check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function cli_denied($params, $type) {
    global $task;
    try { $task->run($params); } catch (Throwable $e) { cli_check($e instanceof $type, get_class($e)); return; }
    throw new RuntimeException('CLI must reject');
}
$params = ['file' => __FILE__, 'user' => 'operator'];
cli_denied([], InvalidArgumentException::class);
cli_denied(['file' => __FILE__], InvalidArgumentException::class);
cli_denied(['file' => __FILE__, 'user' => 'missing'], InvalidArgumentException::class);
cli_denied($params, DomainException::class);
ORM::$roleNames = ['hr_viewer'];
cli_denied($params, DomainException::class);
ORM::$roleNames = ['hr_admin'];
cli_denied($params, DomainException::class);
cli_check(Service_Prx300_Import::$actor === NULL, 'reject before parsing/staging');
Service_Prx300_Directory::$profile = ['organization_id' => 42, 'organization_code' => 'a'];
$task->run($params);
cli_check(Service_Prx300_Import::$actor->id() === 17, 'real audit user');
cli_check(!Service_Prx300_Import::$actor->isAdmin(), 'HR is not elevated');
cli_check(Service_Prx300_Import::$applied === 0, 'staging by default');
$task->run($params + ['apply' => '1']);
cli_check(Service_Prx300_Import::$applied === 1, 'explicit apply');
ORM::$roleNames = ['admin'];
Service_Prx300_Directory::$profile = NULL;
$task->run($params);
cli_check(Service_Prx300_Import::$actor->isAdmin(), 'portal admin remains global');
echo "Employee CLI: $checks checks OK\n";
