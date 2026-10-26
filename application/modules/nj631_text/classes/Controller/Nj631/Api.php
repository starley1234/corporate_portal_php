<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj631_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_tool()
    {
        $q    = $this->request->query();
        $p    = (array) $this->request->post();
        $tool = (string) ($p['tool'] ?? $q['tool'] ?? '');
        $text = (string) ($p['text'] ?? $q['text'] ?? '');

        $r = Service_Nj631_Text::apply($tool, $text, [
            'find'    => (string) ($p['find'] ?? $q['find'] ?? ''),
            'replace' => (string) ($p['replace'] ?? $q['replace'] ?? ''),
        ]);

        if ($r === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Неизвестный инструмент: '.$tool], 400);
        }

        return $this->_respond(['ok' => TRUE, 'tool' => $tool] + $r);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
