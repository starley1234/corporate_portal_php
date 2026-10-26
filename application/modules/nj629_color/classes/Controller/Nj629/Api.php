<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj629_Api extends Controller {

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

        $rgb = Service_Nj629_Color::parse($val);

        if ($rgb === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось разобрать цвет. Примеры: #3B82F6, 59,130,246, rgb(59,130,246).'], 400);
        }

        return $this->_respond(['ok' => TRUE] + Service_Nj629_Color::report($rgb));
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
