<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Протокол воркера prx400 (X-Worker-Token, Prx_Gate::worker_request):
 *   GET  /prx400_specflow/worker/health
 *   GET  /prx400_specflow/worker/<job_id>/file(/<n>) — исходный PDF n спецификации (lease + sha256;
 *                                                     без n — файл 1, совместимость)
 *   POST /prx400_specflow/worker/<job_id>/result  — позиции или {error_code}
 * Номер попытки X-PRX-Worker-Attempt обязателен и сверяется с очередью.
 */
class Controller_Prx400_Worker extends Controller
{
    public $auto_render = FALSE;

    private function respond(callable $action): void
    {
        $this->response->headers('Cache-Control', 'no-store');
        try {
            if ( ! Prx_Gate::worker_request($this->request)) throw new Prx400_Error('unauthorized', 401);
            $action();
        } catch (Prx400_Error $e) {
            $this->_json_response($e->payload(), $e->getCode());
        } catch (Throwable $e) {
            Log_Plain::event(Log::ERROR, 'prx400 worker id=:id error_type=:type', [
                ':id' => (int) $this->request->param('id'), ':type' => get_class($e),
            ]);
            $this->_json_response(['error' => ['code' => 'service_unavailable']], 503);
        }
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method) {
            $this->response->headers('Allow', $method);
            throw new Prx400_Error('method_not_allowed', 405);
        }
    }

    /** @return array{0: int, 1: int} [job_id, attempt] */
    private function lease(string $method): array
    {
        $this->require_method($method);
        $attempt = (string) $this->request->headers('X-PRX-Worker-Attempt');
        if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $attempt)) throw new Prx400_Error('invalid_attempt', 400);
        return [(int) $this->request->param('id'), (int) $attempt];
    }

    public function action_health(): void
    {
        $this->respond(function () {
            $this->require_method(Request::GET);
            $this->_json_response(['ok' => TRUE, 'module' => Service_Prx400_Specs::MODULE]);
        });
    }

    public function action_file(): void
    {
        $this->respond(function () {
            [$job_id, $attempt] = $this->lease(Request::GET);
            $file_no = (int) ($this->request->param('file_no') ?: 1);
            $pdf = (new Service_Prx400_Pipeline)->read_pdf($job_id, $attempt, $file_no);
            $this->response->headers('Content-Type', 'application/pdf');
            $this->response->headers('Content-Length', (string) strlen($pdf));
            $this->response->body($pdf);
        });
    }

    public function action_result(): void
    {
        $this->respond(function () {
            [$job_id, $attempt] = $this->lease(Request::POST);
            $raw = (string) $this->request->body();
            if (strlen($raw) > Service_Prx400_Limits::get('max_result_bytes')) throw new Prx400_Error('result_too_large', 413);
            $body = json_decode($raw, TRUE);
            if ( ! is_array($body)) throw new Prx400_Error('invalid_result', 400);

            $this->_json_response((new Service_Prx400_Pipeline)->store_result($job_id, $attempt, $body));
        });
    }

    private function _json_response(array $data, int $status = 200): void
    {
        $this->response->status($status)->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
