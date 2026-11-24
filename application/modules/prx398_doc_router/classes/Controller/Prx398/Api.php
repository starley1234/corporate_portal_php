<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx398_Api extends Controller_Prx150_Machine
{
    protected const SCOPE = Service_Prx398_Route::SCOPE;

    public function action_index(): void
    {
        $this->respond(function () {
            $id = (int) $this->request->param('id');
            $this->require_method($id ? Request::GET : Request::POST);
            $service = new Service_Prx398_Route;
            if ($id) { $this->_json_response($service->status($this->principal(), $id)); return; }
            Service_Prx398_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
            $job_id = $service->submit($this->principal(), (string) $this->request->headers('Idempotency-Key'), $_FILES['file'] ?? NULL);
            $this->_json_response(['job_id' => $job_id, 'status_url' => '/api/v1/document-routes/'.$job_id], 202);
        });
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method) {
            $this->response->headers('Allow', $method);
            throw new Prx398_Error('method_not_allowed', 405);
        }
    }
}
