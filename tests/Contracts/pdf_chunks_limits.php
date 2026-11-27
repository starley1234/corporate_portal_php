<?php
/** Лимиты prx396: .env.example ↔ конфиг, снимок в задаче, visitor_id и бюджет — без PostgreSQL. */
define('SYSPATH', TRUE);
$root = dirname(__DIR__, 2);
$module = $root.'/application/modules/prx396_pdf_chunks';
require_once $root.'/application/classes/Prx/DomainError.php';
require $root.'/application/modules/prx150_api_hub/classes/Prx150/Error.php';
require $module.'/classes/Prx396/Error.php';
require $module.'/classes/Service/Prx396/Limits.php';
require $module.'/classes/Model/Prx396/Batches.php';
require $module.'/classes/Model/Prx396/Parts.php';
require $module.'/classes/Service/Prx396/Extraction.php';
require $root.'/application/classes/Storage.php';
require $root.'/application/classes/Storage/Exception.php';
require $root.'/application/modules/prx120_jobs/classes/Prx120/Budget/Exception.php';

class Kohana { public static $config; public static $log; }
class PdfChunksLimitsConfig
{
    public function load($name) { return $name === 'prx120_jobs.db_group' ? 'test' : $this; }
    public function as_array() { return require dirname(__DIR__, 2).'/application/modules/prx396_pdf_chunks/config/prx396_pdf_chunks.php'; }
    public function get($key) { return $this->as_array()[$key]; }
}
class Database
{
    const INSERT = 1;
    const SELECT = 2;
    const UPDATE = 3;
    public static string $sql = '';
    public static array $statements = [];
    public static function instance($group = NULL) { return new self; }
    public function begin() { return TRUE; }
    public function commit() { return TRUE; }
    public function rollback() { return TRUE; }
    public function quote($value) { return "'".addslashes((string) $value)."'"; }
    public function query($type, $sql, $as_object) { self::$sql = $sql; self::$statements[] = $sql; return $type === self::SELECT ? new PdfChunksDbResult($sql) : 1; }
}
require __DIR__.'/fixtures/db_bridge.php';
class PdfChunksDbResult
{
    public function __construct(private string $sql) {}
    public function current() { return str_contains($this->sql, 'RETURNING id') ? ['id' => 41] : FALSE; }
    public function as_array() { return []; }
}
class Service_Prx120_JobQueue
{
    public static array $options = [];
    public static array $payload = [];
    public static int $pushes = 0;
    public static bool $budget_error = FALSE;
    public static function instance() { return new self; }
    public static function push($channel, $payload = [], $options = []) {
        self::$pushes++;
        if (self::$budget_error) throw new Prx120_Budget_Exception('Дневной бюджет токенов исчерпан', ['daily' => []]);
        self::$payload = $payload;
        self::$options = $options;
        return 42;
    }
}
class Service_Prx212_Storage
{
    public static int $saves = 0;
    public static int $deletes = 0;
    public static ?int $max = NULL;
    public static function instance() { return new self; }
    public function save($upload, $meta, $max) { self::$saves++; self::$max = $max; return 12; }
    public function delete_owned($file_id, $module) { self::$deletes++; return TRUE; }
}
class Prx_Idempotency
{
    const KEY_PATTERN = '\A[A-Za-z0-9._:-]{1,128}\z';
    const STATE_NEW = 'new';
    const STATE_REPLAY = 'replay';
    const STATE_CONFLICT = 'conflict';
    const STATE_EXPIRED = 'expired';
    public static function begin(...$args) { return ['state' => self::STATE_NEW, 'id' => 5]; }
    public static function finish(...$args) {}
    public static function abort(...$args) {}
}
class Visitor { public static function uid() { return 'u:7'; } }
class Log { const INFO = 1; const ERROR = 2; }
class Log_Plain { public static function event(...$args) {} }

function same_limit($actual, $expected, $context): void
{
    if ($actual !== $expected) throw new RuntimeException($context.': '.var_export($actual, TRUE).' != '.var_export($expected, TRUE));
}

Kohana::$config = new PdfChunksLimitsConfig;

// 1. Документированные в .env.example значения — это значения по умолчанию конфига.
preg_match_all('/^# (PRX396_[A-Z_]+)=([0-9]+)$/m', file_get_contents($root.'/.env.example'), $matches, PREG_SET_ORDER);
same_limit(count($matches), 10, 'documented settings');
foreach ($matches as [, $env, $value]) putenv($env); // сброс перед сверкой
foreach ($matches as [, $env, $value])
{
    $key = strtolower(substr($env, strlen('PRX396_')));
    if ($key === 'ttl_hours' || $key === 'result_ttl_hours') $key = 'result_ttl_hours'; // алиас
    same_limit(Service_Prx396_Limits::get($key), (int) $value, $env.' default');
}
foreach (['0', '2001', '1.5', '01', '99999999999999999999'] as $invalid)
{
    putenv('PRX396_MAX_PAGES='.$invalid);
    same_limit(Service_Prx396_Limits::get('max_pages'), 500, 'invalid pages');
}
putenv('PRX396_MAX_PAGES= 200 ');
same_limit(Service_Prx396_Limits::get('max_pages'), 200, 'max pages');
putenv('PRX396_MAX_PAGES'); // как в .env.example

// 2. Порог загрузки: max_pdf + запас multipart.
$mib = 1024 ** 2;
Service_Prx396_Limits::assert_request_size(100 * $mib + 65536);
try {
    Service_Prx396_Limits::assert_request_size(100 * $mib + 65537);
    throw new RuntimeException('Configured PDF limit was not enforced');
} catch (Prx396_Error $e) {
    same_limit([$e->getCode(), $e->getMessage()], [413, 'file_too_large'], 'multipart threshold');
}

// 3. Терминальные коды: битый PDF/текст/OCR и контрактные 4xx валят batch;
//    временные (сеть, таймаут OCR) обязаны ретраиться.
$permanent = Service_Prx396_Extraction::PERMANENT_CODES;
foreach (['invalid_pdf', 'encrypted_pdf', 'page_limit', 'no_text', 'result_too_large',
          'ocr_config', 'ocr_unavailable', 'file_unavailable', 'lease_lost',
          'invalid_part', 'invalid_plan', 'invalid_attempt', 'method_not_allowed'] as $code)
    same_limit(in_array($code, $permanent, TRUE), TRUE, $code.' терминален');
foreach (['worker_endpoint_unavailable', 'ocr_timeout', 'batch_incomplete', 'job_failed'] as $code)
    same_limit(in_array($code, $permanent, TRUE), FALSE, $code.' ретраится');
foreach (['ocr_timeout', 'invalid_part', 'invalid_attempt'] as $code)
    same_limit(in_array($code, Service_Prx396_Extraction::FAILED_CODES, TRUE), TRUE, $code.' виден в статусе');

// 4. public_status: failed задачи — финал, а не «running, будет повторяться».
$svc = (new ReflectionClass(Service_Prx396_Extraction::class))->newInstanceWithoutConstructor();
$status = new ReflectionMethod(Service_Prx396_Extraction::class, 'public_status');
$status->setAccessible(TRUE);
same_limit($status->invoke($svc, 'running', ['status' => 'failed']), 'failed', 'job failed is terminal');
same_limit($status->invoke($svc, 'queued', ['status' => 'failed']), 'failed', 'job failed while queued');
same_limit($status->invoke($svc, 'running', ['status' => 'processing']), 'running', 'processing');
same_limit($status->invoke($svc, 'running', ['status' => 'pending']), 'queued', 'pending');
same_limit($status->invoke($svc, 'running', ['status' => 'cancelled']), 'canceled', 'cancelled');
same_limit($status->invoke($svc, 'running', ['status' => 'done']), 'running', 'job done, batch ещё не');
same_limit($status->invoke($svc, 'done', ['status' => 'done']), 'succeeded', 'batch done');
same_limit($status->invoke($svc, 'failed', ['status' => 'failed']), 'failed', 'batch failed');
same_limit($status->invoke($svc, 'running', NULL), 'queued', 'нет задачи');

// 5. Разбор кода ошибки задачи для статуса владельца.
same_limit(Service_Prx396_Extraction::error_code('PermanentJobError: no_text'), 'no_text', 'permanent parse');
same_limit(Service_Prx396_Extraction::error_code('DocumentJobError: worker_endpoint_unavailable'), 'worker_endpoint_unavailable', 'retryable parse');
same_limit(Service_Prx396_Extraction::error_code('PermanentJobError: strange_code'), 'job_failed', 'unknown code');
same_limit(Service_Prx396_Extraction::error_code('Превышен таймаут задачи 600'), 'job_timeout', 'timeout');
same_limit(Service_Prx396_Extraction::error_code('', FALSE), 'job_unavailable', 'job gone');

// 6. Постановка: снимок лимитов в полезной нагрузке + visitor_id (правило prx120).
putenv('WORKER_API_TOKEN=fixture');
$tmp = tempnam(sys_get_temp_dir(), 'prx396');
file_put_contents($tmp, '%PDF-1.4 contract');
$upload = ['tmp_name' => $tmp, 'name' => 'document.pdf', 'size' => 9, 'type' => 'application/pdf'];
$register = new ReflectionMethod(Service_Prx396_Extraction::class, 'register');
$register->setAccessible(TRUE);
$service = new Service_Prx396_Extraction;

$batch_id = $register->invoke($service, 1, 'contract-key-1', $upload);
same_limit($batch_id, 41, 'batch id');
same_limit(Service_Prx120_JobQueue::$options, [
    'module' => 'prx396', 'max_attempts' => 3, 'timeout_sec' => 1800, 'visitor_id' => 'u:7',
], 'queue options with visitor_id');
$snapshot = Service_Prx120_JobQueue::$payload['limits'];
same_limit(Service_Prx120_JobQueue::$payload['batch_id'], 41, 'payload batch_id');
same_limit(array_keys($snapshot), ['max_pdf_bytes', 'max_pages', 'chunk_pages', 'max_text_bytes',
    'result_ttl_hours', 'pdf_memory_mb', 'probe_timeout_sec'], 'snapshot keys');
same_limit($snapshot['probe_timeout_sec'], 60, 'probe timeout in snapshot');
same_limit($snapshot['pdf_memory_mb'], 512, 'pdf memory in snapshot');
same_limit(count(array_filter(Database::$statements, fn($q) => str_contains($q, "now() + (72 * interval '1 hour')"))), 1, 'registration expiry');
same_limit(Service_Prx212_Storage::$max, 100 * $mib, 'scoped storage budget');

// Перекрытие .env меняет и снимок, и опции задачи.
putenv('PRX396_TASK_TIMEOUT_SEC=600');
putenv('PRX396_MAX_PAGES=77');
putenv('PRX396_PROBE_TIMEOUT_SEC=90');
$register->invoke($service, 1, 'contract-key-2', $upload);
same_limit(Service_Prx120_JobQueue::$options['timeout_sec'], 600, 'task timeout override');
same_limit(Service_Prx120_JobQueue::$payload['limits']['max_pages'], 77, 'max pages override');
same_limit(Service_Prx120_JobQueue::$payload['limits']['probe_timeout_sec'], 90, 'probe timeout override');
putenv('PRX396_TASK_TIMEOUT_SEC');
putenv('PRX396_MAX_PAGES');
putenv('PRX396_PROBE_TIMEOUT_SEC');

// Неверное значение молча откатывается на дефолт.
putenv('PRX396_PROBE_TIMEOUT_SEC=9999');
$register->invoke($service, 1, 'contract-key-3', $upload);
same_limit(Service_Prx120_JobQueue::$payload['limits']['probe_timeout_sec'], 60, 'invalid probe timeout fallback');
putenv('PRX396_PROBE_TIMEOUT_SEC');

// 7. Исчерпанный бюджет: 429, ключ и файл освобождаются для повтора.
$pushes_before = Service_Prx120_JobQueue::$pushes;
$deletes_before = Service_Prx212_Storage::$deletes;
Service_Prx120_JobQueue::$budget_error = TRUE;
try {
    $register->invoke($service, 1, 'contract-key-budget', $upload);
    throw new RuntimeException('Budget refusal was not mapped');
} catch (Prx396_Error $e) {
    same_limit([$e->getCode(), $e->getMessage()], [429, 'budget_exceeded'], 'budget refusal');
}
Service_Prx120_JobQueue::$budget_error = FALSE;
same_limit(Service_Prx120_JobQueue::$pushes, $pushes_before + 1, 'push attempted');
same_limit(Service_Prx212_Storage::$deletes, $deletes_before + 1, 'upload discarded after refusal');

unlink($tmp);
echo "PDF chunks limits: defaults, thresholds, statuses, snapshot, visitor budget OK\n";
