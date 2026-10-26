<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API римских чисел (NJ-623).
 *
 *   GET /nj623/api/convert?value=1987
 *   → {"ok": true, "dir": "to", "in": "1987", "out": "MCMLXXXVII"}
 */
class Controller_Nj623_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_convert()
    {
        $q    = $this->request->query();
        $post = (array) $this->request->post();
        $val  = isset($post['value']) ? $post['value'] : (isset($q['value']) ? $q['value'] : '');

        $r = Service_Nj623_Roman::convert((string) $val);

        if ($r === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось перевести. Проверьте значение: арабское 1–3999 или корректная римская запись.'], 400);
        }

        return $this->_respond(['ok' => TRUE] + $r);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
