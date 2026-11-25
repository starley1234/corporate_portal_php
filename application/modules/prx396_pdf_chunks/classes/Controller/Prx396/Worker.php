<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx396_Worker extends Controller
{
    public $auto_render = FALSE;

    /** Worker API не использует сессии; ключ проверяется до любого действия. */
    private function respond(callable $action): void
    {
        $this->response->headers('Cache-Control', 'no-store');
        try
        {
            if ( ! Prx_Gate::worker_request($this->request)) throw new Prx396_Error('unauthorized', 401);
            $action();
        }
        catch (Prx396_Error $e)
        {
            $this->_json_response($e->payload(), $e->getCode());
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx396 worker error_type=:type', [':type' => get_class($e)]);
            $this->_json_response(['error' => ['code' => 'service_unavailable']], 503);
        }
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method)
        {
            $this->response->headers('Allow', $method);
            throw new Prx396_Error('method_not_allowed', 405);
        }
    }

    private function _json_response(array $data, int $status = 200): void
    {
        $this->response->status($status)
            ->headers('Content-Type', 'application/json; charset=utf-8')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function lease(string $method): array
    {
        $this->require_method($method);
        $attempt = (string) $this->request->headers('X-PRX-Worker-Attempt');
        if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $attempt)) throw new Prx396_Error('invalid_attempt', 400);
        return [(int) $this->request->param('id'), (int) $attempt];
    }

    private function json_body(int $max_bytes): array
    {
        $raw = (string) $this->request->body();
        if (strlen($raw) > $max_bytes) throw new Prx396_Error('result_too_large', 413);
        $body = json_decode($raw, TRUE);
        if ( ! is_array($body)) throw new Prx396_Error('invalid_result', 400);
        return $body;
    }

    public function action_health(): void
    {
        $this->respond(function () {
            $this->require_method(Request::GET);
            $this->_json_response(['ok' => TRUE, 'module' => 'prx396']);
        });
    }

    public function action_file(): void
    {
        $this->respond(function () {
            [$id, $attempt] = $this->lease(Request::GET);
            $pdf = (new Service_Prx396_Extraction)->read_pdf($id, $attempt);
            $this->response->headers('Content-Type', 'application/pdf');
            $this->response->headers('Content-Length', (string) strlen($pdf));
            $this->response->body($pdf);
        });
    }

    public function action_plan(): void
    {
        $this->respond(function () {
            [$id, $attempt] = $this->lease(Request::POST);
            $body = $this->json_body(65536);
            $this->_json_response((new Service_Prx396_Extraction)->plan($id, $attempt, $body));
        });
    }

    public function action_part(): void
    {
        $this->respond(function () {
            [$id, $attempt] = $this->lease(Request::POST);
            // Потолок тела /part — как у prx395: JSON-экранирование текста
            // (кавычки, \uXXXX для не-ASCII) растит тело до 6× длины текста.
            // Прежний min(max_text, 32 МиБ) резал валидные части при
            // max_text_bytes > 32 МиB (413 result_too_large на больших текстах).
            $max = 6 * Service_Prx396_Limits::get('max_text_bytes') + 4096;
            $body = $this->json_body($max);
            $this->_json_response((new Service_Prx396_Extraction)->store_part($id, $attempt, $body));
        });
    }

    public function action_complete(): void
    {
        $this->respond(function () {
            [$id, $attempt] = $this->lease(Request::POST);
            $body = $this->json_body(65536);
            $this->_json_response((new Service_Prx396_Extraction)->complete($id, $attempt, $body));
        });
    }
}
