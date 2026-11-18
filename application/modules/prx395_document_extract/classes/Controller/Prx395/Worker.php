<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx395_Worker extends Controller_Prx395_Json
{
    private function lease(string $method): array
    {
        if ( ! Gate::worker_request()) throw new Prx395_Error('unauthorized', 401);
        $this->require_method($method);
        $attempt = (string) $this->request->headers('X-PRX-Worker-Attempt');
        if ( ! preg_match('/\A[1-9][0-9]{0,8}\z/', $attempt)) throw new Prx395_Error('invalid_attempt', 400);
        return [(int) $this->request->param('id'), (int) $attempt];
    }

    public function action_health(): void
    {
        $this->respond(function () {
            if ( ! Gate::worker_request()) throw new Prx395_Error('unauthorized', 401);
            $this->require_method(Request::GET);
            $this->_json_response(['ok' => TRUE, 'module' => 'prx395']);
        });
    }

    public function action_file(): void
    {
        $this->respond(function () {
            [$id, $attempt] = $this->lease(Request::GET);
            $pdf = (new Service_Prx395_Extraction)->read_pdf($id, $attempt);
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
            if (strlen($raw) > 6 * Service_Prx395_Extraction::MAX_TEXT + 4096)
                throw new Prx395_Error('result_too_large', 413);
            $body = json_decode($raw, TRUE);
            if ( ! is_array($body)) throw new Prx395_Error('invalid_result', 400);
            $receipt = (new Service_Prx395_Extraction)->store_result($id, $attempt, $body);
            $this->_json_response(['receipt' => $receipt]);
        });
    }
}
