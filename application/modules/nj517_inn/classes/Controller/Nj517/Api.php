<?php defined('SYSPATH') or die('No direct script access.');

/** POST /nj517/api/lookup — явный запрос открытой карточки организации в ФНС. */
class Controller_Nj517_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
        $this->response->headers('Cache-Control', 'no-store');
    }

    public function action_lookup()
    {
        if ($this->request->method() !== Request::POST)
        {
            $this->response->headers('Allow', 'POST');
            return $this->_respond(['ok' => FALSE, 'error' => 'method_not_allowed', 'message' => 'Используйте POST.'], 405);
        }

        $params = $this->_params();

        if ( ! Security::check((string) ($params['csrf'] ?? '')))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'csrf', 'message' => 'Страница устарела. Обновите её и повторите запрос.'], 403);
        }

        try
        {
            $result = Service_Nj517_Inn::lookup($params['inn'] ?? '');
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::ERROR, 'nj517 lookup failed: :e', [':e' => $e->getMessage()]);
            return $this->_respond(['ok' => FALSE, 'error' => 'unavailable', 'message' => 'Сервис ФНС временно недоступен. Попробуйте позже.'], 503);
        }

        if (isset($result['error']))
        {
            $status = $result['error'] === 'rate_limited' ? 429
                : (in_array($result['error'], ['bad_format', 'bad_length', 'bad_checksum', 'empty'], TRUE) ? 400
                : ($result['error'] === 'personal_inn' ? 422 : 503));

            return $this->_respond(['ok' => FALSE] + $result, $status);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    /** JSON body or ordinary POST form. */
    protected function _params()
    {
        $raw = $this->request->body();
        $json = $raw !== '' ? json_decode($raw, TRUE) : NULL;
        $out = is_array($json) ? $json : [];

        return $out + (array) $this->request->post();
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
