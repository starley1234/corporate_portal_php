<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj628_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_check()
    {
        $q   = $this->request->query();
        $p   = (array) $this->request->post();
        $a   = (string) ($p['a'] ?? $q['a'] ?? '');
        $v   = (string) ($p['value'] ?? $q['value'] ?? '');

        $r = Service_Nj628_Checksum::check($a, $v);

        if ($r === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Неизвестный алгоритм: '.$a], 400);
        }

        return $this->_respond(['ok' => TRUE, 'algorithm' => $a] + $r);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
