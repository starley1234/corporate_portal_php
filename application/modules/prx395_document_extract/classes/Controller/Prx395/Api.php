<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Машинный API: POST /api/v1/document-extractions, GET .../<id>.
 *
 * Ключ, scope и лимит проверяет Controller_Prx150_Machine. Владелец
 * задания — целочисленный ID ключа prx150, поэтому чужое задание не видно
 * даже по угаданному ID.
 */
class Controller_Prx395_Api extends Controller_Prx150_Machine
{
    protected const SCOPE = Service_Prx395_Extraction::SCOPE;

    public function action_extraction(): void
    {
        $this->respond(function () {
            $principal = $this->principal();
            $id = (int) $this->request->param('id');
            $this->require_method($id ? Request::GET : Request::POST);
            $service = new Service_Prx395_Extraction;
            if ($id)
            {
                $this->_json_response($service->status($principal, $id));
                return;
            }
            if ((int) $this->request->headers('Content-Length') > Service_Prx395_Extraction::MAX_PDF + 65536)
                throw new Prx395_Error('file_too_large', 413);
            $job_id = $service->submit($principal, (string) $this->request->headers('Idempotency-Key'), $_FILES['file'] ?? NULL);
            $url = '/api/v1/document-extractions/'.$job_id;
            // PHP SAPI превращает Location с 202 в redirect. URL статуса — в JSON.
            $this->_json_response(['job_id' => $job_id, 'status_url' => $url], 202);
        });
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method)
        {
            $this->response->headers('Allow', $method);
            throw new Prx395_Error('method_not_allowed', 405);
        }
    }
}
