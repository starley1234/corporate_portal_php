<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API кодировщика (NJ-616).
 *
 *   POST /nj616/api/convert {"text": "...", "mode": "b64_encode"}
 *   → {"ok": true, "output": "..."}
 */
class Controller_Nj616_Api extends Controller {

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

    public function action_convert()
    {
        $text   = isset($this->_body['text']) ? $this->_body['text'] : '';
        $mode   = isset($this->_body['mode']) ? $this->_body['mode'] : 'b64_encode';
        $result = Service_Nj616_Convert::convert($text, $mode);

        return $this->_respond([
            'ok'     => (bool) $result['ok'],
            'output' => (string) $result['output'],
            'error'  => (string) $result['error'],
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
