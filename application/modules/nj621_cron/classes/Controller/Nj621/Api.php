<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API cron-человечизатора (NJ-621).
 *
 *   POST /nj621/api/explain {"expr": "* /15 * * * *"}
 *   → {"ok": true, "human": "...", "next": ["...", ...]}
 */
class Controller_Nj621_Api extends Controller {

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

    public function action_explain()
    {
        $expr   = isset($this->_body['expr']) ? $this->_body['expr'] : '';
        $result = Service_Nj621_Cron::explain($expr);

        return $this->_respond([
            'ok'     => (bool) $result['ok'],
            'error'  => (string) $result['error'],
            'human'  => $result['human'] ?? '',
            'fields' => $result['fields'] ?? NULL,
            'next'   => $result['next'] ?? [],
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
