<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Внешнее API prx400: POST спецификации PDF → асинхронный структурированный JSON.
 *
 * POST /prx400_specflow/api/spec      multipart (pdf[, parse_mode, template_id])
 *                                     → 202 {spec_id, status:"queued", poll_url}
 * GET  /prx400_specflow/api/spec/<id> → 202 {status:"queued"|"running", poll_url}
 *                                       200 {status:"failed", error:{code}}
 *                                       200 {status:"review"|"done", result:{meta,positions,audit},
 *                                            awaiting_operator:bool}
 *
 * Схема result = схеме экспорта ERP (Service_Prx400_Export::json). Разбор
 * асинхронный по построению (OCR/vision — минуты): интегратор поллит poll_url.
 * Auth: заголовок X-PRX-API-Token против env PRX400_API_TOKEN (hash_equals);
 * переменная не задана → 503 api_disabled (API выключен честно).
 */
class Controller_Prx400_Api extends Controller {

    public $auto_render = FALSE;

    public function action_spec(): void
    {
        if ( ! $this->_guard_token())
        {
            return;
        }

        if ($this->request->method() === Request::POST)
        {
            $this->_create();

            return;
        }
        if ($this->request->method() === Request::GET)
        {
            $this->_status((int) $this->request->param('id'));

            return;
        }
        $this->_json(['error' => ['code' => 'method_not_allowed']], 405);
    }

    private function _create(): void
    {
        try
        {
            $data = (new Service_Prx400_Specs)->api_create($_FILES, $this->request->post());
        }
        catch (Prx400_Error $e)
        {
            $this->_json(['error' => ['code' => $e->getMessage()]], $e->getCode() ?: 400);

            return;
        }
        catch (Throwable $e)
        {
            Log_Plain::event(Log::ERROR, 'prx400_diag stage=api_create result=error :t', [':t' => get_class($e)]);
            $this->_json(['error' => ['code' => 'service_unavailable']], 503);

            return;
        }

        Log_Plain::event(Log::INFO, 'prx400_diag stage=api_create spec=:s result=queued', [':s' => (int) $data['id']]);
        $this->_json([
            'spec_id' => (int) $data['id'],
            'status' => 'queued',
            'parse_mode' => (string) $data['parse_mode'] ?? 'ocr',
            'poll_url' => '/prx400_specflow/api/spec/'.(int) $data['id'],
        ], 202);
    }

    private function _status(int $id): void
    {
        if ($id <= 0)
        {
            $this->_json(['error' => ['code' => 'spec_id_required']], 400);

            return;
        }
        try
        {
            $data = (new Service_Prx400_Specs)->api_status($id);
        }
        catch (Prx400_Error $e)
        {
            $this->_json(['error' => ['code' => $e->getMessage()]], $e->getCode() ?: 404);

            return;
        }

        $pending = in_array($data['status'], ['queued', 'running'], TRUE);
        $this->_json($data, $pending ? 202 : 200);
    }

    /** Токен интегратора: env PRX400_API_TOKEN (пусто → API выключен). */
    private function _guard_token(): bool
    {
        $want = trim((string) getenv('PRX400_API_TOKEN'));
        if ($want === '')
        {
            $this->_json(['error' => ['code' => 'api_disabled']], 503);

            return FALSE;
        }
        $got = (string) $this->request->headers('x-prx-api-token');
        if ($got === '' OR ! hash_equals($want, $got))
        {
            $this->_json(['error' => ['code' => 'unauthorized']], 401);

            return FALSE;
        }

        return TRUE;
    }

    private function _json(array $data, int $status = 200): void
    {
        $this->response->status($status)
            ->headers('Content-Type', 'application/json; charset=utf-8')
            ->headers('Cache-Control', 'no-store')
            ->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
