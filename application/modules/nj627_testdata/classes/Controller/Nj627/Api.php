<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj627_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_generate()
    {
        $q   = $this->request->query();
        $p   = (array) $this->request->post();
        $t   = (string) ($p['t'] ?? $q['t'] ?? 'fio');
        $n   = (int) ($p['n'] ?? $q['n'] ?? 5);
        $one = Service_Nj627_Testdata::one($t);

        if ($one === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Неизвестный тип: '.$t.' (fio|phone|email|company)'], 400);
        }

        return $this->_respond([
            'ok'   => TRUE,
            'type' => $t,
            'data' => Service_Nj627_Testdata::generate($t, $n),
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
