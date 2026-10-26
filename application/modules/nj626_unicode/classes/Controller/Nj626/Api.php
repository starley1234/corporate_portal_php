<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj626_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_analyze()
    {
        $q   = $this->request->query();
        $p   = (array) $this->request->post();
        $txt = (string) ($p['text'] ?? $q['text'] ?? '');

        return $this->_respond([
            'ok'      => TRUE,
            'chars'   => Service_Nj626_Unicode::analyze($txt),
            'decoded' => Service_Nj626_Unicode::decode_escapes($txt),
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
