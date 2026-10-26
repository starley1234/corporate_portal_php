<?php defined('SYSPATH') or die('No direct script access.');

/** Публичное API справочника размеров. */
class Controller_Nj511_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_convert()
    {
        if ($this->request->method() !== Request::POST) return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
        $p = $this->_params();
        $result = Service_Nj511_Size::calculate((string) ($p['type'] ?? ''), $p['measure'] ?? ($p['v'] ?? NULL));
        if ($result === NULL) return $this->_respond(['ok' => FALSE, 'error' => 'Выберите категорию и укажите положительную мерку.'], 400);
        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    public function action_categories()
    {
        return $this->_respond(['ok' => TRUE, 'categories' => Service_Nj511_Size::categories()]);
    }

    protected function _params()
    {
        $body = json_decode((string) $this->request->body(), TRUE);
        return (is_array($body) ? $body : []) + (array) $this->request->post();
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
