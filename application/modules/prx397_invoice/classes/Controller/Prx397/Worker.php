<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx397_Worker extends Controller
{
    public $auto_render = FALSE;

    private function respond(callable $action): void
    {
        $this->response->headers('Cache-Control', 'no-store');
        try {
            if ( ! Prx_Gate::worker_request($this->request)) throw new Prx397_Error('unauthorized', 401);
            $action();
        } catch (Prx397_Error $e) {
            $this->_json_response($e->payload(), $e->getCode());
        } catch (Throwable $e) {
            Log_Plain::event(Log::ERROR, 'prx397 worker error_type=:type', [':type' => get_class($e)]);
            $this->_json_response(['error' => ['code' => 'service_unavailable']], 503);
        }
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method) {
            $this->response->headers('Allow', $method);
            throw new Prx397_Error('method_not_allowed', 405);
        }
    }

    private function _json_response(array $data, int $status = 200): void
    {
        $this->response->status($status)->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function lease(string $method): array
    {
        $this->require_method($method);
        $attempt = (string) $this->request->headers('X-PRX-Worker-Attempt');
        if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $attempt)) throw new Prx397_Error('invalid_attempt', 400);
        return [(int) $this->request->param('id'), (int) $attempt];
    }

    public function action_health(): void
    {
        $this->respond(function () {
            $this->require_method(Request::GET);
            $this->_json_response(['ok' => TRUE, 'module' => 'prx397']);
        });
    }

    public function action_file(): void
    {
        $this->respond(function () {
            [$id, $attempt] = $this->lease(Request::GET);
            $pdf = (new Service_Prx397_Extraction)->read_pdf($id, $attempt);
            $this->response->headers('Content-Type', 'application/pdf');
            $this->response->headers('Content-Length', (string) strlen($pdf));
            $this->response->body($pdf);
        });
    }

    public function action_result(): void
    {
        $this->respond(function () {
            [$id, $attempt] = $this->lease(Request::POST);
            $raw = (string) $this->request->body();
            if (strlen($raw) > 2 * 1024 * 1024) throw new Prx397_Error('result_too_large', 413);
            $body = json_decode($raw, TRUE);
            if ( ! is_array($body)) throw new Prx397_Error('invalid_result', 400);
            $result = (new Service_Prx397_Extraction)->store_result($id, $attempt, $body);
            $this->_json_response($result);
        });
    }
}
