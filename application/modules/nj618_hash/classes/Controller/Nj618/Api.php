<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API хеш-инструмента (NJ-618).
 *
 *   POST /nj618/api/hash {"text": "...", "alg": "sha256", "upper": false}
 *   → {"ok": true, "hash": "..."}
 */
class Controller_Nj618_Api extends Controller {

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

    public function action_hash()
    {
        $text  = isset($this->_body['text']) ? $this->_body['text'] : '';
        $algo  = isset($this->_body['alg']) ? $this->_body['alg'] : 'sha256';
        $upper = ! empty($this->_body['upper']);

        $result = Service_Nj618_Hash::hash_text($text, $algo, $upper);

        return $this->_respond([
            'ok'    => (bool) $result['ok'],
            'hash'  => (string) $result['hash'],
            'alg'   => $algo,
            'error' => (string) $result['error'],
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
