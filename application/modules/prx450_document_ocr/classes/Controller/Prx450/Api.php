<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Машинный API: POST /api/v1/document-ocr/parse.
 *
 * Ключ и scope проверяет Controller_Prx150_Machine. Контракт запроса и ответа
 * — как у внешнего шлюза: multipart `file` + query-параметры → JSON
 * {file, content, cached, cache_source, engine_used, llm_fixed, raw_result}.
 */
class Controller_Prx450_Api extends Controller_Prx150_Machine
{
    protected const SCOPE = Service_Prx450_Ocr::SCOPE;

    public function after()
    {
        if (in_array($this->response->status(), [503, 504], TRUE)) $this->response->headers('Retry-After', '5');
        parent::after();
    }

    public function action_parse(): void
    {
        if ($this->request->method() !== Request::POST)
        {
            $this->response->headers('Cache-Control', 'no-store');
            $this->response->headers('Allow', 'POST');
            $this->_json_response((new Prx450_Error('method_not_allowed', 405))->payload(), 405);
            return;
        }
        $this->respond(function () {
            $result = Service_Prx450_Ocr::parse(
                $_FILES['file'] ?? NULL,
                $this->request->query(),
                NULL, $this->principal()->id(),
                $this->principal()->fileLimits()
            );

            $this->_json_response($result, 200);
        });
    }
}
