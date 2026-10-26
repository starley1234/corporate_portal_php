<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Nj632_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_convert()
    {
        $q    = $this->request->query();
        $p    = (array) $this->request->post();
        $mode = (string) ($p['mode'] ?? $q['mode'] ?? 'csv2json');
        $data = (string) ($p['data'] ?? $q['data'] ?? '');
        $delim = (string) ($p['delimiter'] ?? $q['delimiter'] ?? ',');

        if ($mode === 'json2csv')
        {
            $out = Service_Nj632_Csv::from_json($data, $delim);
        }
        else
        {
            $out = Service_Nj632_Csv::to_json($data, $delim);
        }

        if ($out === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Не удалось преобразовать. Проверьте входные данные.'], 400);
        }

        return $this->_respond(['ok' => TRUE, 'out' => $out]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
