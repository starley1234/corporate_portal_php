<?php
/** Настоящий respond() prx150: контекст доступен только внутри проверенного действия. */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
require $root.'/application/classes/Prx/Principal.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Principal.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Error.php';
class Request { public function method() { return 'GET'; } public function uri() { return 'api/test'; } }
class MachineResponse {
    public array $headers = [];
    private int $status = 200;
    private string $body = '';
    public function headers($name, $value) { $this->headers[$name] = $value; return $this; }
    public function status($value = NULL) { if ($value === NULL) return $this->status; $this->status = $value; return $this; }
    public function body($value = NULL) { if ($value === NULL) return $this->body; $this->body = $value; return $this; }
}
class Controller_Base {
    public $request;
    public $response;
    protected function _json_response($body, $status = 200) { $this->response->status($status)->body(json_encode($body)); }
}
class MachineLog { public function add($level, $message, $context) {} }
class Log { const ERROR = 3; }
class Kohana { public static $log; }
Kohana::$log = new MachineLog;
class Service_Prx150_Keys {
    public static string $failure = '';
    public static function authenticate($request) {
        if (self::$failure === 'auth') throw new Prx150_Error('unauthorized', 401);
        return ['id' => 7];
    }
    public static function require_scope($row, $scope) {
        if (self::$failure === 'scope') throw new Prx150_Error('forbidden', 403);
    }
    public static function rate_limit($row, $scope) {
        if (self::$failure === 'rate') throw new Prx150_Error('rate_limited', 429);
    }
    public static function principal($row) { return new Prx150_Principal($row['id'], 'test', NULL, ['test']); }
}
class Service_Prx150_Usage {
    public static array $statuses = [];
    public static function log($client, $scope, $request, $status, $duration, $bytes) { self::$statuses[] = $status; return 23; }
}
require $root.'/application/modules/prx150_api_hub/classes/Controller/Prx150/Machine.php';
class MachineHarness extends Controller_Prx150_Machine {
    protected const SCOPE = 'test';
    public function run($action) { $this->respond($action); }
}
$checks = 0;
function machine_check($actual, $expected) { global $checks; if ($actual !== $expected) throw new RuntimeException('Unexpected machine result'); $checks++; }
function machine_no_principal($controller) {
    try { $controller->principal(); throw new RuntimeException('Principal escaped respond'); }
    catch (LogicException $e) { machine_check(TRUE, TRUE); }
}
$controller = new MachineHarness;
$controller->request = new Request;
machine_no_principal($controller);
$called = 0;
foreach (['' => 200, 'auth' => 401, 'scope' => 403, 'rate' => 429, 'action' => 503] as $failure => $status) {
    $controller->response = new MachineResponse;
    Service_Prx150_Keys::$failure = $failure;
    Service_Prx150_Usage::$statuses = [];
    $before = $called;
    $controller->run(function () use ($controller, &$called, $failure) {
        $called++;
        machine_check($controller->principal()->id(), 7);
        if ($failure === 'action') throw new RuntimeException('private error');
    });
    machine_check($called - $before, in_array($failure, ['', 'action'], TRUE) ? 1 : 0);
    machine_check($controller->response->status(), $status);
    machine_check($controller->response->headers['Cache-Control'], 'no-store');
    machine_check(Service_Prx150_Usage::$statuses, $failure === 'auth' ? [] : [$status]);
    if ($failure === 'auth') machine_check($controller->response->headers['WWW-Authenticate'], 'Bearer realm="PRX API"');
    else machine_check($controller->response->headers['X-Request-Id'], '23');
    machine_no_principal($controller);
    if ($failure === 'action') machine_check(str_contains($controller->response->body(), 'private error'), FALSE);
}
echo "Machine access: $checks checks OK\n";
