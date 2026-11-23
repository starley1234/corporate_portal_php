<?php
/** Проверка worker gate без БД: php .tools/gate_stand.php. */
if ( ! defined('SYSPATH')) define('SYSPATH', __DIR__.'/../system/');
class Request {
    public $header = NULL;
    public $token = NULL;
    public function headers($key) { return $this->header; }
    public function query($key) { return $this->token; }
}
require dirname(__DIR__).'/application/classes/Prx/Gate.php';
$request = new Request;
$ok = 0;
function verify_gate($label, $actual, $expected) {
    global $ok;
    if ($actual !== $expected) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    $ok++;
}
putenv('WORKER_API_TOKEN=');
verify_gate('пустой токен закрыт', Prx_Gate::worker_request($request), FALSE);
putenv('WORKER_API_TOKEN=shared');
$request->token = 'shared';
verify_gate('query выключен', Prx_Gate::worker_request($request), FALSE);
verify_gate('query разрешён', Prx_Gate::worker_request($request, TRUE), TRUE);
$request->header = 'wrong';
verify_gate('header важнее query', Prx_Gate::worker_request($request, TRUE), FALSE);
$request->header = ' shared ';
verify_gate('обычный API обрезает пробелы', Prx_Gate::worker_request($request, TRUE), TRUE);
verify_gate('строгий заголовок без обрезки', Prx_Gate::worker_request($request, FALSE, NULL, FALSE), FALSE);
$request->header = ' ';
verify_gate('пустой после trim: query', Prx_Gate::worker_request($request, TRUE), TRUE);
verify_gate('outbox: пробел — заданный заголовок', Prx_Gate::worker_request($request, TRUE, NULL, TRUE, TRUE), FALSE);
$request->header = '0';
verify_gate('outbox: ноль выбирает query как PHP ?: ', Prx_Gate::worker_request($request, TRUE, NULL, TRUE, TRUE), TRUE);
$request->header = 'site';
verify_gate('отдельный site token', Prx_Gate::worker_request($request, FALSE, 'site', FALSE), TRUE);
verify_gate('неподходящий site token', Prx_Gate::worker_request($request, FALSE, 'other', FALSE), FALSE);
echo "Worker gate: $ok проверок OK\n";
