<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx395_Api extends Controller_Prx395_Json
{
    public function action_extraction(): void
    {
        $this->respond(function () {
            $client = Service_Prx395_Clients::authenticate((string) $this->request->headers('Authorization'));
            if ($client === NULL)
            {
                $this->response->headers('WWW-Authenticate', 'Bearer realm="PRX document extraction"');
                throw new Prx395_Error('unauthorized', 401);
            }
            $id = (int) $this->request->param('id');
            $this->require_method($id ? Request::GET : Request::POST);
            $service = new Service_Prx395_Extraction;
            if ($id)
            {
                $this->_json_response($service->status($client, $id));
                return;
            }
            if ((int) $this->request->headers('Content-Length') > Service_Prx395_Extraction::MAX_PDF + 65536)
                throw new Prx395_Error('file_too_large', 413);
            $job_id = $service->submit($client, (string) $this->request->headers('Idempotency-Key'), $_FILES['file'] ?? NULL);
            $url = '/api/v1/document-extractions/'.$job_id;
            // PHP SAPI превращает Location с 202 в redirect. URL статуса — в JSON.
            $this->_json_response(['job_id' => $job_id, 'status_url' => $url], 202);
        });
    }
}
