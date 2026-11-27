<?php
/** Проверяет реальные настройки, ранний ответ API и опции регистрации без PostgreSQL. */
define('SYSPATH', TRUE);
$root = dirname(__DIR__, 2);
$module = $root.'/application/modules/prx395_document_extract';
require_once $root.'/application/classes/Prx/DomainError.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Error.php';
require $module.'/classes/Prx395/Error.php';
require $module.'/classes/Service/Prx395/Limits.php';
require $module.'/classes/Service/Prx395/Extraction.php';
require $root.'/application/classes/Storage.php';

class Kohana { public static $config; }
class ExtractionConfig
{
    public function load($name) { return $name === 'prx120_jobs.db_group' ? 'test' : $this; }
    public function as_array() { return require dirname(__DIR__, 2).'/application/modules/prx395_document_extract/config/prx395_document_extract.php'; }
    public function get($key) { return $this->as_array()[$key]; }
}
class Request
{
    const POST = 'POST';
    public int $length = 0;
    public function param($key) { return NULL; }
    public function method() { return self::POST; }
    public function headers($key) { return $key === 'Content-Length' ? (string) $this->length : 'test-key'; }
}
interface Prx_Principal {}
class Prx_Actor implements Prx_Principal {}
class Policy_Prx395_Extraction { public static function ownerId($principal) { return 1; } }
class Controller_Prx150_Machine
{
    public Request $request;
    public array $error;
    protected function respond(callable $action) { try { $action(); } catch (Prx_DomainError $e) { $this->error = [$e->getCode(), $e->getMessage()]; } }
    protected function principal() { return new Prx_Actor; }
}
class Database
{
    const SELECT = 1;
    const INSERT = 2;
    const UPDATE = 3;
    public static string $sql;
    public static function instance($group = NULL) { return new self; }
    public function quote($value) { return "'".$value."'"; }
    public function query($type, $sql, $as_object) { self::$sql = $sql; return $type === self::SELECT ? [] : 1; }
}
require __DIR__.'/fixtures/db_bridge.php';
class Service_Prx120_JobQueue
{
    public static array $options;
    public static function instance() { return new self; }
    public static function push($channel, $payload, $options) { self::$options = $options; return 42; }
}
class Service_Prx212_Storage
{
    public static ?int $max;
    public static function instance() { return new self; }
    public function save($upload, $meta, $max) { self::$max = $max; return 12; }
}
class Prx_Idempotency
{
    const KEY_PATTERN = '\A[a-z-]+\z';
    const STATE_NEW = 'new';
    public static function begin(...$args) { return ['state' => self::STATE_NEW, 'id' => 5]; }
    public static function finish(...$args) {}
    public static function abort(...$args) {}
}
class Log { const ERROR = 3; const WARNING = 4; const INFO = 6; }
class Log_Plain { public static function event(...$args) {} }
require $module.'/classes/Controller/Prx395/Api.php';

function same_limit($actual, $expected, $context): void
{
    if ($actual !== $expected) throw new RuntimeException($context.': '.var_export($actual, TRUE).' != '.var_export($expected, TRUE));
}

Kohana::$config = new ExtractionConfig;
preg_match_all('/^# (PRX395_[A-Z_]+)=([0-9]+)$/m', file_get_contents($root.'/.env.example'), $matches, PREG_SET_ORDER);
same_limit(count($matches), 7, 'documented settings');
foreach ($matches as [, $env, $value])
{
    putenv($env);
    same_limit(Service_Prx395_Limits::get(strtolower(substr($env, strlen('PRX395_')))), (int) $value, $env.' default');
}
foreach (['0', '201', '1.5', '01', '99999999999999999999'] as $invalid)
{
    putenv('PRX395_MAX_PAGES='.$invalid);
    same_limit(Service_Prx395_Limits::get('max_pages'), 20, 'invalid pages');
}
putenv('PRX395_MAX_PAGES= 200 ');
same_limit(Service_Prx395_Limits::get('max_pages'), 200, 'max pages');

$api = new Controller_Prx395_Api;
$api->request = new Request;
foreach ([2, 12] as $mib)
{
    $bytes = $mib * 1024 ** 2;
    putenv('PRX395_MAX_PDF_BYTES='.$bytes);
    $api->request->length = $bytes + 65536;
    $api->action_extraction();
    same_limit($api->error, [400, 'file_required'], 'multipart threshold fits');
    $api->request->length++;
    $api->action_extraction();
    same_limit($api->error, [413, 'file_too_large'], 'multipart threshold exceeded');
}

putenv('WORKER_API_TOKEN=fixture');
putenv('PRX395_RESULT_TTL_HOURS=48');
putenv('PRX395_TASK_TIMEOUT_SEC=420');
putenv('PRX395_MAX_ATTEMPTS=5');
$job = (new Service_Prx395_Extraction)->submit_sample(new Prx_Actor, 'test-key');
same_limit($job, 42, 'job id');
same_limit(Service_Prx212_Storage::$max, 12 * 1024 ** 2, 'scoped storage budget');
same_limit(Service_Prx120_JobQueue::$options, ['module' => 'prx395', 'max_attempts' => 5, 'timeout_sec' => 420], 'queue options');
same_limit(str_contains(Database::$sql, "expires_at) VALUES (") && str_contains(Database::$sql, "now() + (48 * interval '1 hour')"), TRUE, 'registration expiry');

putenv('PRX395_MAX_PAGES=2');
putenv('PRX395_MAX_TEXT_BYTES=1024');
$result = ['provider' => 'remote', 'format' => 'markdown', 'pages_total' => 2, 'text' => str_repeat('Я', 512)];
same_limit(Service_Prx395_Extraction::validate_result($result)['pages_total'], 2, 'result fits');
foreach ([[['text' => $result['text'].'Я'], 413, 'result_too_large'], [['pages_total' => 3], 400, 'invalid_result']] as [$overrides, $status, $code])
{
    try {
        Service_Prx395_Extraction::validate_result($overrides + $result);
        throw new RuntimeException('Configured result limit was not enforced');
    } catch (Prx395_Error $e) {
        same_limit([$e->getCode(), $e->getMessage()], [$status, $code], 'result limit');
    }
}

echo "Extraction limits: defaults, invalid config, configured API 413, result and registration OK\n";
