<?php defined('SYSPATH') or die('No direct script access.');

/** Публичный API преобразования дат; даты не сохраняются. */
class Controller_Nj513_Api extends Controller {

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
        $date = Service_Nj513_Datewords::convert((string) ($p['date'] ?? ''));
        if ($date === NULL) return $this->_respond(['ok' => FALSE, 'error' => 'Введите реальную дату в формате YYYY-MM-DD или DD.MM.YYYY.'], 400);
        $range = NULL;
        if (isset($p['end']) AND (string) $p['end'] !== '')
        {
            $range = Service_Nj513_Datewords::range((string) ($p['date'] ?? ''), (string) $p['end']);
            if ($range === NULL) return $this->_respond(['ok' => FALSE, 'error' => 'Конечная дата не может быть раньше начальной.'], 400);
        }
        return $this->_respond(['ok' => TRUE, 'date' => $date, 'range' => $range]);
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
