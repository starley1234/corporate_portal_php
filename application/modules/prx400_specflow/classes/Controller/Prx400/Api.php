<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Внешнее API prx400 (ключ prx150 + scope prx400.extract):
 *
 *   POST /api/v1/spec-extractions        multipart file[, parse_mode, template_id]
 *        Authorization: Bearer <ключ>, Idempotency-Key: <ключ запроса>
 *        → 202 {spec_id, status: "queued", status_url}
 *   GET  /api/v1/spec-extractions/<id>   → 200 {status: queued|running|failed|canceled|review|done, …}
 *
 * Ключ видит только свои спецификации. Ключ/scope/лимит/журнал — respond().
 */
class Controller_Prx400_Api extends Controller_Prx150_Machine
{
    protected const SCOPE = Service_Prx400_Specs::SCOPE;

    public function action_index(): void
    {
        $this->respond(function () {
            $id = (int) $this->request->param('id');
            $this->require_method($id ? Request::GET : Request::POST);
            $service = new Service_Prx400_Specs;
            if ($id) {
                $this->_json_response($service->status($this->principal(), $id));
                return;
            }
            Service_Prx400_Limits::assert_request_size((int) $this->request->headers('Content-Length'));
            $spec_id = $service->submit(
                $this->principal(),
                (string) $this->request->headers('Idempotency-Key'),
                $_FILES['file'] ?? NULL,
                [
                    'parse_mode' => $this->request->post('parse_mode'),
                    'template_id' => $this->request->post('template_id'),
                ]
            );
            $this->_json_response([
                'spec_id' => $spec_id,
                'status' => 'queued',
                'status_url' => '/api/v1/spec-extractions/'.$spec_id,
            ], 202);
        });
    }

    private function require_method(string $method): void
    {
        if ($this->request->method() !== $method) {
            $this->response->headers('Allow', $method);
            throw new Prx400_Error('method_not_allowed', 405);
        }
    }
}
