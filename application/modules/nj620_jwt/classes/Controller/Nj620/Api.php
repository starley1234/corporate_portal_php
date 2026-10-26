<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API JWT-декодера (NJ-620).
 *
 *   POST /nj620/api/decode {"token": "..."}
 *   → {"ok": true, "header": {...}, "claims": {...}, "dates": {...}}
 */
class Controller_Nj620_Api extends Controller {

    public $auto_render = FALSE;

    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();
        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if (empty($this->_body) && $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }
    }

    public function action_decode()
    {
        $token  = isset($this->_body['token']) ? $this->_body['token'] : '';
        $result = Service_Nj620_Jwt::decode($token);

        return $this->_respond([
            'ok'          => (bool) $result['ok'],
            'error'       => (string) $result['error'],
            'alg'         => $result['alg'] ?? NULL,
            'typ'         => $result['typ'] ?? NULL,
            'header'      => $result['header'] ?? NULL,
            'claims'      => $result['claims'] ?? NULL,
            'dates'       => $result['dates'] ?? NULL,
            'signature'   => $result['signature'] ?? '',
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
