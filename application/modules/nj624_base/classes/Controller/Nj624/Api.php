<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj624_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_convert()
    {
        $q = $this->request->query();
        $p = (array) $this->request->post();
        $f = (int) ($p['from'] ?? $q['from'] ?? 10);
        $t = (int) ($p['to'] ?? $q['to'] ?? 2);
        $v = (string) ($p['value'] ?? $q['value'] ?? '');

        $r = Service_Nj624_Base::convert($v, $f, $t);

        if ($r === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось перевести. Проверьте число и основания (2|8|10|16).'], 400);
        }

        return $this->_respond(['ok' => TRUE, 'from' => $f, 'to' => $t, 'in' => $v, 'out' => $r]);
    }

    public function action_bitop()
    {
        $q  = $this->request->query();
        $p  = (array) $this->request->post();
        $op = (string) ($p['op'] ?? $q['op'] ?? '');
        $a  = (string) ($p['a'] ?? $q['a'] ?? '0');
        $b  = (string) ($p['b'] ?? $q['b'] ?? '0');

        $r = Service_Nj624_Base::bitop($op, $a, $b);

        if ($r === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Неизвестная операция: '.$op], 400);
        }

        return $this->_respond(['ok' => TRUE] + $r);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
