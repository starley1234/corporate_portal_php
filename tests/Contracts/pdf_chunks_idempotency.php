<?php
/** Идемпотентность prx396: ключи регистрации и повторная отправка части — без PostgreSQL. */
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
require $root.'/application/modules/prx120_jobs/classes/Prx120/JobOutcome.php';
require $root.'/application/classes/Storage/Exception.php';

class Kohana { public static $config; public static $log; }
class PdfChunksIdemConfig
{
    public function load($name) { return $name === 'prx120_jobs.db_group' ? 'test' : $this; }
    public function as_array() { return require dirname(__DIR__, 2).'/application/modules/prx396_pdf_chunks/config/prx396_pdf_chunks.php'; }
    public function get($key) { return $this->as_array()[$key]; }
}
/** Канонические строки для выборок store_part/part_ack — маршрутизация по SQL. */
class PdfChunksCanned
{
    public static string $batch_status = 'running';
    public static array $job = ['channel' => 'pdf.chunks', 'status' => 'processing', 'attempts' => 1];
}
class Database
{
    const INSERT = 1;
    const SELECT = 2;
    const UPDATE = 3;
    public static array $statements = [];
    public static function instance($group = NULL) { return new self; }
    public function begin() { return TRUE; }
    public function commit() { return TRUE; }
    public function rollback() { return TRUE; }
    public function quote($value) { return "'".addslashes((string) $value)."'"; }
    public function query($type, $sql, $as_object) { self::$statements[] = $sql; return $type === self::SELECT ? new PdfChunksIdemResult($sql) : 1; }
}
require __DIR__.'/fixtures/db_bridge.php';
class PdfChunksIdemResult
{
    public function __construct(private string $sql) {}
    public function current()
    {
        $sql = $this->sql;
        if (str_contains($sql, 'RETURNING id')) return ['id' => 41];
        if (str_contains($sql, 'COUNT(*) FILTER')) return ['done' => 3, 'total' => 3];
        if (str_contains($sql, 'FROM prx_396_parts'))
            return ['part_no' => 2, 'page_from' => 3, 'page_to' => 4, 'status' => 'done', 'text_file_id' => 9, 'text_bytes' => 10];
        if (str_contains($sql, 'expires_at > now()'))
            return ['id' => 1, 'job_id' => 7, 'owner_id' => 1, 'file_id' => 5, 'file_sha256' => str_repeat('a', 64),
                    'pages_total' => 5, 'status' => PdfChunksCanned::$batch_status, 'error_code' => NULL,
                    'config_snapshot' => '{"max_text_bytes":1024}'];
        if (str_contains($sql, 'FROM prx_396_batches')) return ['pages_total' => 5, 'text_bytes' => 0, 'error_code' => NULL, 'status' => PdfChunksCanned::$batch_status];
        return FALSE;
    }
    public function as_array() { $row = $this->current(); return $row ? [$row] : []; }
}
class Service_Prx120_JobQueue
{
    public static int $pushes = 0;
    public static array $options = [];
    public static array $payload = [];
    public static function instance() { return new self; }
    public function get($id) { return PdfChunksCanned::$job; }
    public static function push($channel, $payload = [], $options = []) { self::$pushes++; self::$payload = $payload; self::$options = $options; return 42; }
}
class Service_Prx212_Storage
{
    public static int $saves = 0;
    public static int $puts = 0;
    public static int $deletes = 0;
    public static function instance() { return new self; }
    public function save($upload, $meta, $max) { self::$saves++; return 12; }
    public function put($content, $filename, $meta, $mime) { self::$puts++; return 13; }
    public function delete_owned($file_id, $module) { self::$deletes++; return TRUE; }
}
class Prx_Idempotency
{
    const KEY_PATTERN = '\A[A-Za-z0-9._:-]{1,128}\z';
    const STATE_NEW = 'new';
    const STATE_REPLAY = 'replay';
    const STATE_CONFLICT = 'conflict';
    const STATE_EXPIRED = 'expired';
    public static string $state = 'new';
    public static function begin(...$args) {
        if (self::$state === 'replay') return ['state' => self::STATE_REPLAY, 'id' => 5, 'result' => ['batch_id' => 99]];
        if (self::$state === 'conflict') return ['state' => self::STATE_CONFLICT, 'id' => 5, 'result' => NULL];
        if (self::$state === 'expired') return ['state' => self::STATE_EXPIRED, 'id' => 5, 'result' => NULL];
        return ['state' => self::STATE_NEW, 'id' => 5, 'result' => NULL];
    }
    public static function finish(...$args) {}
    public static function abort(...$args) {}
}
class Visitor { public static function uid() { return 'u:7'; } }
class Log { const INFO = 1; const ERROR = 2; }
class Log_Plain { public static function event(...$args) {} }
// Заглушки HTTP-контура для Controller_Prx396_Worker (маршрут /part).
class Controller { public $request; public $response; public $auto_render = TRUE; }
class Request { const GET = 'GET'; const POST = 'POST'; }
class Prx_Gate { public static function worker_request($request) { return TRUE; } }
class PdfChunksWorkerRequest
{
    public function __construct(private string $m, private string $b, private array $h, private array $p) {}
    public function method() { return $this->m; }
    public function body() { return $this->b; }
    public function headers($key) { return $this->h[$key] ?? ''; }
    public function param($key) { return $this->p[$key] ?? NULL; }
}
class PdfChunksWorkerResponse
{
    public int $code = 200;
    public array $headers = [];
    public string $body = '';
    public function headers($key, $value = NULL) { if ($value === NULL) return $this->headers[$key] ?? NULL; $this->headers[$key] = $value; return $this; }
    public function status($code = NULL) { if ($code === NULL) return $this->code; $this->code = $code; return $this; }
    public function body($body = NULL) { if ($body === NULL) return $this->body; $this->body = $body; return $this; }
}
require $module.'/classes/Controller/Prx396/Worker.php';

function same_idem($actual, $expected, $context): void
{
    if ($actual !== $expected) throw new RuntimeException($context.': '.var_export($actual, TRUE).' != '.var_export($expected, TRUE));
}

Kohana::$config = new PdfChunksIdemConfig;

// Заглушка повторяет реальный контракт ключа — сверяем с исходником класса.
$idemSource = file_get_contents($root.'/application/classes/Prx/Idempotency.php');
same_idem(str_contains($idemSource, "const KEY_PATTERN = '".Prx_Idempotency::KEY_PATTERN."'"), TRUE, 'KEY_PATTERN синхронен');
foreach (['new', 'replay', 'conflict', 'expired'] as $state)
    same_idem(str_contains($idemSource, "STATE_".strtoupper($state)." = '".$state."'"), TRUE, 'STATE_'.$state.' синхронен');

putenv('WORKER_API_TOKEN=fixture');
$tmp = tempnam(sys_get_temp_dir(), 'prx396');
file_put_contents($tmp, '%PDF-1.4 contract');
$upload = ['tmp_name' => $tmp, 'name' => 'document.pdf', 'size' => 9, 'type' => 'application/pdf'];
$register = new ReflectionMethod(Service_Prx396_Extraction::class, 'register');
$register->setAccessible(TRUE);
$service = new Service_Prx396_Extraction;

// 1. Недопустимый ключ — 400 до сохранения файла и постановки.
$saves = Service_Prx212_Storage::$saves;
$pushes = Service_Prx120_JobQueue::$pushes;
try {
    $register->invoke($service, 1, 'not a valid key', $upload);
    throw new RuntimeException('Invalid idempotency key accepted');
} catch (Prx396_Error $e) {
    same_idem([$e->getCode(), $e->getMessage()], [400, 'invalid_idempotency_key'], 'invalid key');
}
same_idem(Service_Prx212_Storage::$saves, $saves, 'no upload stored for invalid key');
same_idem(Service_Prx120_JobQueue::$pushes, $pushes, 'no job for invalid key');

// 2. Replay: тот же ключ и файл → та же партия, без новой постановки.
Prx_Idempotency::$state = 'replay';
same_idem($register->invoke($service, 1, 'replay-key', $upload), 99, 'replayed batch');
same_idem(Service_Prx120_JobQueue::$pushes, $pushes, 'replay does not push');
Prx_Idempotency::$state = 'new';
same_idem($register->invoke($service, 1, 'fresh-key', $upload), 41, 'fresh batch');
same_idem(Service_Prx120_JobQueue::$pushes, $pushes + 1, 'fresh key pushes once');

// 3. Другой файл того же ключа и истёкший ключ — 409.
Prx_Idempotency::$state = 'conflict';
try {
    $register->invoke($service, 1, 'replay-key', $upload);
    throw new RuntimeException('Conflict was not mapped');
} catch (Prx396_Error $e) {
    same_idem([$e->getCode(), $e->getMessage()], [409, 'idempotency_conflict'], 'conflict');
}
Prx_Idempotency::$state = 'expired';
try {
    $register->invoke($service, 1, 'replay-key', $upload);
    throw new RuntimeException('Expired was not mapped');
} catch (Prx396_Error $e) {
    same_idem([$e->getCode(), $e->getMessage()], [409, 'idempotency_key_expired'], 'expired');
}
Prx_Idempotency::$state = 'new';

// 4. Повторная отправка сохранённой части: part_ack без перезаписи файла.
$puts = Service_Prx212_Storage::$puts;
$ack = $service->store_part(1, 1, ['part_no' => 2]); // текст не передан — часть уже done
same_idem($ack, ['batch_id' => 1, 'part_no' => 2, 'parts_done' => 3, 'parts_total' => 3, 'batch_status' => 'running'], 'resent part ack');
same_idem(Service_Prx212_Storage::$puts, $puts, 'idempotent replay does not rewrite file');

// 5. Устаревшая попытка (другой attempt) — 409 lease_lost, не ack.
PdfChunksCanned::$job['attempts'] = 2;
try {
    $service->store_part(1, 1, ['part_no' => 2]);
    throw new RuntimeException('Stale attempt accepted');
} catch (Prx396_Error $e) {
    same_idem([$e->getCode(), $e->getMessage()], [409, 'lease_lost'], 'stale attempt');
}
PdfChunksCanned::$job['attempts'] = 1;

// 6. Batch уже done: ack на любую часть, включая нулевую.
PdfChunksCanned::$batch_status = 'done';
same_idem($service->store_part(1, 1, []), ['batch_id' => 1, 'part_no' => 0, 'parts_done' => 3, 'parts_total' => 3, 'batch_status' => 'done'], 'done batch ack');
PdfChunksCanned::$batch_status = 'running';

// 7. Потолок тела /part = 6*max_text_bytes+4096 (прецедент prx395), а не min(max,32MiB)+64KiB.
//    При max_text=1024: новый потолок 10240. Тело 11000 → 413; тело ~7000 → проходит.
putenv('PRX396_MAX_TEXT_BYTES=1024');
$worker = new Controller_Prx396_Worker;
$worker->response = new PdfChunksWorkerResponse;
$worker->request = new PdfChunksWorkerRequest(Request::POST, str_repeat('x', 11000),
    ['X-PRX-Worker-Attempt' => '1'], ['id' => 1]);
$worker->action_part();
same_idem($worker->response->code, 413, 'oversized part body');
same_idem(json_decode($worker->response->body, TRUE)['error']['code'], 'result_too_large', '413 code');

$worker->response = new PdfChunksWorkerResponse;
$worker->request = new PdfChunksWorkerRequest(Request::POST,
    json_encode(['part_no' => 2, 'text' => str_repeat('a', 7000), 'format' => 'plain',
                 'provider' => 'tesseract', 'pages_processed' => 2, 'warnings' => []]),
    ['X-PRX-Worker-Attempt' => '1'], ['id' => 1]);
$worker->action_part();
same_idem($worker->response->code, 200, 'part body within 6*max_text cap');
same_idem(json_decode($worker->response->body, TRUE)['part_no'], 2, 'ack part');
putenv('PRX396_MAX_TEXT_BYTES');

// 8. Неверный попытка-заголовок — 400 invalid_attempt до тела запроса.
$worker->response = new PdfChunksWorkerResponse;
$worker->request = new PdfChunksWorkerRequest(Request::POST, 'x', ['X-PRX-Worker-Attempt' => '0'], ['id' => 1]);
$worker->action_part();
same_idem($worker->response->code, 400, 'bad attempt header');
same_idem(json_decode($worker->response->body, TRUE)['error']['code'], 'invalid_attempt', 'attempt code');

unlink($tmp);
echo "PDF chunks idempotency: keys, replay, part resend, worker body cap OK\n";
