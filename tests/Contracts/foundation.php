<?php
/** Изолированные runtime-контракты: php tests/Contracts/foundation.php (без БД). */
if (!defined('SYSPATH')) define('SYSPATH', dirname(__DIR__, 2).'/system/');
$root = dirname(__DIR__, 2);
$checks = 0;
function verify($actual, $expected, string $label): void {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function raises(callable $operation, string $type): void {
    try { $operation(); } catch (Throwable $e) {
        verify(get_class($e), $type, 'тип исключения');
        return;
    }
    throw new RuntimeException('FAIL: ожидалось '.$type);
}

class Request {
    const GET = 'GET';
    const POST = 'POST';
    public static $client_ip = '127.0.0.1';
    public string $verb = 'POST';
    public string $path = 'ui';
    public array $headers = [];
    public array $form = [];
    public function query($key) { return NULL; }
    public function method() { return $this->verb; }
    public function uri() { return $this->path; }
    public function post($key) { return $this->form[$key] ?? NULL; }
    public function headers($key = NULL) {
        return $key === NULL ? $this->headers : ($this->headers[strtolower($key)] ?? NULL);
    }
    public function controller() { return 'Example'; }
    public function action() { return 'save'; }
}
class Security { public static function check($token) { return $token === 'valid-csrf'; } }
class HTTP_Exception_403 extends RuntimeException {}
class HTTP_Exception {
    public static function factory($code, $message) { return new HTTP_Exception_403($message, $code); }
}
class ContractConfig {
    public function load($name) { return $this; }
    public function get($key, $default = NULL) { return $key === 'webhooks' ? ['hook'] : $default; }
}
class Kohana {
    const DEVELOPMENT = 10;
    public static $environment = 10;
    public static $log = NULL;
    public static $config;
    public static function modules() { return []; }
}
Kohana::$config = new ContractConfig;
require $root.'/application/classes/Prx/PostGuard.php';
$request = new Request;
raises(fn () => Prx_PostGuard::assert_safe($request), HTTP_Exception_403::class);
foreach (['authorization', 'x-worker-token'] as $header) {
    $request->headers = [$header => 'forged'];
    raises(fn () => Prx_PostGuard::assert_safe($request), HTTP_Exception_403::class);
}
$request->headers = ['x-csrf-token' => 'valid-csrf'];
Prx_PostGuard::assert_safe($request);
$request->headers = [];
$request->form = ['csrf' => 'valid-csrf'];
Prx_PostGuard::assert_safe($request);
$request->form = [];
Prx_PostGuard::assert_safe($request, TRUE); // Канал объявлен сервером, ключ проверяет endpoint.
$request->path = 'hook/telegram';
Prx_PostGuard::assert_safe($request);
$request->path = 'hook-evil';
raises(fn () => Prx_PostGuard::assert_safe($request), HTTP_Exception_403::class);
foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
    $request->verb = $method;
    Prx_PostGuard::assert_safe($request);
}

class Database {
    public array $calls = [];
    public bool $begin_ok = TRUE;
    public bool $commit_ok = TRUE;
    public bool $rollback_throws = FALSE;
    public static function instance() { throw new RuntimeException('Тест должен передать соединение'); }
    public function begin() { $this->calls[] = 'begin'; return $this->begin_ok; }
    public function commit() { $this->calls[] = 'commit'; return $this->commit_ok; }
    public function rollback() {
        $this->calls[] = 'rollback';
        if ($this->rollback_throws) throw new RuntimeException('rollback');
        return TRUE;
    }
}
require $root.'/application/classes/Prx/Db.php';
$db = new Database;
verify(Prx_Db::transaction(fn () => 42, $db), 42, 'результат операции');
verify($db->calls, ['begin', 'commit'], 'успешный commit');
$db->calls = [];
raises(fn () => Prx_Db::transaction(function () { throw new LogicException('operation'); }, $db), LogicException::class);
verify($db->calls, ['begin', 'rollback'], 'откат ошибки');
$db->calls = [];
$db->begin_ok = FALSE;
raises(fn () => Prx_Db::transaction(fn () => 42, $db), RuntimeException::class);
verify($db->calls, ['begin'], 'при ошибке begin операция не запускается');
$db->begin_ok = TRUE;
$db->calls = [];
$db->commit_ok = FALSE;
raises(fn () => Prx_Db::transaction(fn () => 42, $db), RuntimeException::class);
verify($db->calls, ['begin', 'commit', 'rollback'], 'ошибка commit не считается успехом');
$db->commit_ok = TRUE;
$db->calls = [];
raises(fn () => Prx_Db::transaction(fn () => Prx_Db::transaction(fn () => 1, $db), $db), LogicException::class);
verify($db->calls, ['begin', 'rollback'], 'нет раннего commit при вложенности');
verify(Prx_Db::transaction(fn () => 7, $db), 7, 'состояние очищено после ошибки');
$other = new Database;
verify(Prx_Db::transaction(fn () => Prx_Db::transaction(fn () => 8, $other), $db), 8, 'независимые соединения');
$db->rollback_throws = TRUE;
raises(fn () => Prx_Db::transaction(function () { throw new LogicException('original'); }, $db), LogicException::class);
$db->rollback_throws = FALSE;

// Проверяем настоящий worker-контроллер: загрузка класса, auth до действия, JSON.
class Controller {
    public $request;
    public $response;
}
class ContractResponse {
    public int $code = 200;
    public array $headers = [];
    public string $body = '';
    public function status($code) { $this->code = $code; return $this; }
    public function headers($key, $value) { $this->headers[$key] = $value; return $this; }
    public function body($body) { $this->body = $body; return $this; }
}
class Log { const ERROR = 1; }
class Log_Plain { public static function event(...$args) {} }
require $root.'/application/classes/Prx/Gate.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Error.php';
require $root.'/application/modules/prx395_document_extract/classes/Prx395/Error.php';
require $root.'/application/modules/prx395_document_extract/classes/Controller/Prx395/Worker.php';
$worker = new Controller_Prx395_Worker;
$worker->request = new Request;
$worker->response = new ContractResponse;
putenv('WORKER_API_TOKEN=contract-secret');
foreach (['action_health', 'action_file', 'action_result'] as $action) {
    $worker->$action();
    verify($worker->response->code, 401, $action.' требует токен');
    verify(json_decode($worker->response->body, TRUE), ['error' => ['code' => 'unauthorized']], 'JSON ошибки');
}
$worker->request->headers = ['x-worker-token' => 'wrong'];
$worker->action_result();
verify($worker->response->code, 401, 'неверный токен');
$worker->request->headers = ['x-worker-token' => 'contract-secret'];
$worker->action_health();
verify($worker->response->code, 405, 'проверка метода после auth');
$worker->request->verb = 'GET';
$worker->action_health();
verify($worker->response->code, 200, 'health с валидным токеном');
verify(json_decode($worker->response->body, TRUE)['docflow_enabled'], FALSE, 'docflow выключен');
$worker->request->verb = 'POST';
$worker->action_result();
verify($worker->response->code, 400, 'попытка обязательна');
putenv('WORKER_API_TOKEN=');
$worker->action_health();
verify($worker->response->code, 401, 'пустой секрет закрывает API');

// Реальные объявления API: ловим в том числе сужение видимости у наследника.
class Controller_Base extends Controller {
    public function _json_response($data, $code = 200) {}
    protected function machine_request(): bool { return FALSE; }
}
require $root.'/application/modules/prx150_api_hub/classes/Controller/Prx150/Machine.php';
class ContractMachine extends Controller_Prx150_Machine {
    public function channel(): bool { return $this->machine_request(); }
}
verify((new ContractMachine)->channel(), TRUE, 'машинный API явно объявляет канал');
require $root.'/application/modules/prx115_semantic/classes/Controller/Prx115/Api.php';
require $root.'/application/modules/prx120_jobs/classes/Controller/Prx120/Api.php';
class ContractSemantic extends Controller_Prx115_Api {
    public function authorize(): bool { return $this->_authorize_worker(); }
    protected function _auth_fail($reason) { return FALSE; }
}
class ContractQueue extends Controller_Prx120_Api {
    public function authorize(): bool { return $this->_authorize(); }
    protected function _auth_fail($reason) { return FALSE; }
}
foreach ([new ContractSemantic, new ContractQueue] as $api) {
    $api->request = new Request;
    $api->request->headers = ['x-worker-token' => 'contract-secret'];
    putenv('WORKER_API_TOKEN=');
    verify($api->authorize(), FALSE, 'development не разрешает пустой секрет');
    putenv('WORKER_API_TOKEN=contract-secret');
    verify($api->authorize(), TRUE, 'валидный worker-токен');
    $api->request->headers = ['x-worker-token' => 'wrong'];
    verify($api->authorize(), FALSE, 'чужой worker-токен');
}
putenv('WORKER_API_TOKEN=');

// Реестр исполняется с обоими значениями флага, без bootstrap и БД.
define('APPPATH', $root.'/application/');
define('MODPATH', $root.'/modules/');
putenv('PRX_LDAP_ENABLED=0');
putenv('PRX_DOCFLOW_ENABLED=0');
$modules = require APPPATH.'config/modules.php';
verify(isset($modules['prx390_intake']), FALSE, 'docflow opt-in');
putenv('PRX_DOCFLOW_ENABLED=1');
$modules = require APPPATH.'config/modules.php';
foreach (['prx390_intake', 'prx391_documents', 'prx392_analysis', 'prx393_workflow'] as $module) {
    verify(isset($modules[$module]), TRUE, 'стенд: '.$module);
}
putenv('PRX_DOCFLOW_ENABLED=0');
echo "Foundation contracts: $checks checks OK\n";
