<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj625_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_convert()
    {
        $q   = $this->request->query();
        $p   = (array) $this->request->post();
        $val = (string) ($p['value'] ?? $q['value'] ?? '');

        $parsed = Service_Nj625_Bytes::parse($val);

        if ($parsed === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось разобрать значение. Пример: 500 МБ или 1.5 гибибайт.'], 400);
        }

        return $this->_respond([
            'ok'     => TRUE,
            'in'     => $val,
            'unit'   => $parsed['unit'],
            'bytes'  => $parsed['bytes'],
            'human'  => Service_Nj625_Bytes::human($parsed['bytes']),
            'table'  => Service_Nj625_Bytes::table($parsed['bytes']),
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
