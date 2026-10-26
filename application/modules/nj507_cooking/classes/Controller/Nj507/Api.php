<?php defined('SYSPATH') or die('No direct script access.');

/** JSON API кулинарного справочника; ничего не сохраняет. */
class Controller_Nj507_Api extends Controller {

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
        $result = Service_Nj507_Cooking::calculate(
            (string) ($p['product'] ?? ''), (string) ($p['measure'] ?? ''),
            $p['amount'] ?? NULL, (string) ($p['direction'] ?? 'to_mass'),
            $p['from_servings'] ?? 1, $p['to_servings'] ?? 1);
        if ($result === NULL) return $this->_respond(['ok' => FALSE, 'error' => 'Проверьте продукт, меру, количество и число порций.'], 400);
        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    public function action_form()
    {
        if ($this->request->method() !== Request::POST) return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
        $p = $this->_params();
        $result = Service_Nj507_Cooking::form_factor(
            (string) ($p['from_shape'] ?? ''), $p['from_a'] ?? NULL, $p['from_b'] ?? NULL,
            (string) ($p['to_shape'] ?? ''), $p['to_a'] ?? NULL, $p['to_b'] ?? NULL);
        if ($result === NULL) return $this->_respond(['ok' => FALSE, 'error' => 'Проверьте формы и размеры: сторона или диаметр — от 1 до 200 см.'], 400);
        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    public function action_products()
    {
        return $this->_respond(['ok' => TRUE, 'products' => Service_Nj507_Cooking::products(), 'measures' => Service_Nj507_Cooking::measures()]);
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
