<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj630_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_range()
    {
        $q   = $this->request->query();
        $p   = (array) $this->request->post();
        $val = (string) ($p['value'] ?? $q['value'] ?? '');

        $r = Service_Nj630_Ipcalc::report($val);

        if ($r === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось разобрать подсеть. Формат: a.b.c.d/n, пример 192.168.1.0/24.'], 400);
        }

        return $this->_respond(['ok' => TRUE] + $r);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
