<?php
/** ACL и актуальная оргизоляция prx300 без PostgreSQL. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
class EmployeeConfig {
    private $values;
    public function __construct(array $values) { $this->values = $values; }
    public function get($key) { return $this->values[$key] ?? NULL; }
}
class EmployeeConfigLoader {
    public function load($name) {
        global $root;
        return new EmployeeConfig(require $root.'/application/config/acl.php');
    }
}
class Kohana { public static $config; }
Kohana::$config = new EmployeeConfigLoader;
require $root.'/application/classes/Prx/Principal.php';
require $root.'/application/classes/Prx/Acl.php';
require $root.'/application/classes/Prx/Actor.php';
// Подменена только граница чтения активной связи; политика и ACL настоящие.
class Service_Prx300_Directory {
    public static $profile = NULL;
    public static function profile_for_user($id) { return self::$profile; }
}
require $root.'/application/modules/prx300_employee/classes/Policy/Prx300/Employee.php';
require $root.'/application/modules/prx300_employee/classes/Service/Prx300/Store.php';
require $root.'/application/modules/prx300_employee/classes/Service/Prx300/Admin.php';
require $root.'/application/modules/prx300_employee/classes/Service/Prx300/Identity.php';
require $root.'/application/modules/prx300_employee/classes/Service/Prx300/Import.php';
$checks = 0;
function employee_check($condition, $label) {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function employee_denied(callable $operation, $class = DomainException::class) {
    try { $operation(); } catch (Throwable $e) {
        employee_check($e instanceof $class, 'Unexpected exception: '.get_class($e));
        return;
    }
    throw new RuntimeException('Expected denial: '.$class);
}
$guest = new Prx_Actor(0, ['admin']);
$login = new Prx_Actor(10, ['login'], ['organization_id' => 999]);
$viewer = new Prx_Actor(10, ['login', 'hr_viewer']);
$hr = new Prx_Actor(10, ['login', 'hr_admin'], ['organization_id' => 999]);
$admin = new Prx_Actor(11, ['admin']);
foreach ([$guest, $login] as $actor) {
    employee_denied(fn() => Service_Prx300_Admin::dashboard($actor));
    employee_denied(fn() => Service_Prx300_Identity::link_user($actor, 1, 'other'));
    employee_denied(fn() => Service_Prx300_Import::stage_rows($actor, 'hris', []));
    employee_denied(fn() => Service_Prx300_Import::batches($actor));
}
employee_denied(fn() => Policy_Prx300_Employee::assertOrganization($guest));
employee_denied(fn() => Policy_Prx300_Employee::directoryOrganization($guest));
employee_denied(fn() => Policy_Prx300_Employee::directoryOrganization($login));
employee_denied(fn() => Policy_Prx300_Employee::assertOrganization($hr));
employee_check(Policy_Prx300_Employee::canReadAdministration($viewer), 'viewer reads administration');
employee_denied(fn() => Policy_Prx300_Employee::requireAbility($viewer, 'prx300.manage'));
employee_check(Policy_Prx300_Employee::directoryOrganization($admin) === NULL, 'admin global');
employee_check(Policy_Prx300_Employee::directoryOrganization($admin, ['organization_id' => 42]) === 42, 'admin selects org');
Service_Prx300_Directory::$profile = ['organization_id' => 42, 'organization_code' => 'a', 'employment_status' => 'active'];
employee_check(Policy_Prx300_Employee::assertOrganization($hr)['organization_id'] === 42, 'fresh org, not actor snapshot');
foreach ([$login, $viewer, $hr] as $actor) {
    employee_check(Policy_Prx300_Employee::directoryOrganization($actor, ['organization_id' => 999]) === 42, 'request cannot change org');
    employee_denied(fn() => Policy_Prx300_Employee::assertOrganization($actor, 999));
}
foreach (['[]', '["b"]', '["a","b"]', 'null', 'invalid'] as $codes) {
    employee_check(!Policy_Prx300_Employee::batchInScope($hr, ['organization_codes' => $codes]), 'foreign/malformed batch');
}
employee_check(Policy_Prx300_Employee::batchInScope($hr, ['organization_codes' => '["a"]']), 'own batch');
Service_Prx300_Directory::$profile['employment_status'] = 'terminated';
employee_denied(fn() => Policy_Prx300_Employee::directoryOrganization($login));
Service_Prx300_Directory::$profile['organization_id'] = 43;
Service_Prx300_Directory::$profile['organization_code'] = 'b';
employee_denied(fn() => Policy_Prx300_Employee::assertOrganization($hr, 42));
employee_check(!Policy_Prx300_Employee::batchInScope($hr, ['organization_codes' => '["a"]']), 'moved link invalidates old batch');
Service_Prx300_Directory::$profile = NULL;
employee_denied(fn() => Policy_Prx300_Employee::assertOrganization($hr));
employee_check(!Policy_Prx300_Employee::batchInScope($hr, ['organization_codes' => '["b"]']), 'revoked link invalidates batch');
$forged = ['user_id' => 10, 'can_manage' => TRUE, 'can_import' => TRUE, 'roles' => ['login']];
employee_denied(fn() => Service_Prx300_Admin::dashboard($forged), TypeError::class);
employee_denied(fn() => Service_Prx300_Import::stage_rows($forged, 'hris', []), TypeError::class);
employee_denied(fn() => Service_Prx300_Identity::link_user($forged, 1, 'other'), TypeError::class);
employee_denied(fn() => Policy_Prx300_Employee::requireAbility(Prx_Actor::fromArray($forged), 'prx300.manage'));
echo "Employee access: $checks checks OK\n";
