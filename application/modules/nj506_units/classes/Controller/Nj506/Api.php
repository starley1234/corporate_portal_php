<?php defined('SYSPATH') or die('No direct script access.');

/** Публичный JSON API без сохранения пользовательских данных. */
class Controller_Nj506_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_calc()
    {
        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $p = $this->_params();
        $result = Service_Nj506_Units::calculate(
            (string) ($p['category'] ?? ''), $p['value'] ?? NULL,
            (string) ($p['from'] ?? ''), (string) ($p['to'] ?? ''));

        if ($result === NULL)
            return $this->_respond(['ok' => FALSE, 'error' => 'Проверьте категорию, единицы и значение. Для длины, массы, объёма и площади оно должно быть неотрицательным.'], 400);

        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    public function action_catalog()
    {
        return $this->_respond(['ok' => TRUE, 'categories' => Service_Nj506_Units::catalog()]);
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
