<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API тестера регулярных выражений (NJ-622).
 *
 *   POST /nj622/api/test {"pattern": "/\\d+/", "test": "a1 b22", "replace": "#", "flags": ""}
 *   → {"ok": true, "match_count": 2, "matches": [...]}
 */
class Controller_Nj622_Api extends Controller {

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

    public function action_test()
    {
        $pattern = isset($this->_body['pattern']) ? $this->_body['pattern'] : '';
        $test    = isset($this->_body['test']) ? $this->_body['test'] : '';
        $replace = isset($this->_body['replace']) ? $this->_body['replace'] : '';
        $flags   = isset($this->_body['flags']) ? $this->_body['flags'] : '';

        $result = Service_Nj622_Regex::run($pattern, $test, $replace, $flags);

        return $this->_respond([
            'ok'           => (bool) $result['ok'],
            'error'        => (string) $result['error'],
            'match_count'  => $result['match_count'] ?? 0,
            'matches'      => $result['matches'] ?? [],
            'replaced'     => $result['replaced'] ?? NULL,
            'replace_count'=> $result['replace_count'] ?? 0,
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
