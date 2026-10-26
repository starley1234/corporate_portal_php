<?php defined('SYSPATH') or die('No direct script access.');

/** API конвертации. Текст ответа не журналируется и не хранится. */
class Controller_Nj514_Api extends Controller {

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
        $text = (isset($p['text']) && is_string($p['text'])) ? $p['text'] : '';
        $result = Service_Nj514_Translit::convert($text, (string) ($p['standard'] ?? ''), (string) ($p['direction'] ?? 'to_latin'));
        if ($result === NULL) return $this->_respond(['ok' => FALSE, 'error' => 'Неизвестный стандарт, направление или превышен лимит текста ('.Service_Nj514_Translit::max_chars().' символов).'], 400);
        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    public function action_standards()
    {
        $standards = Service_Nj514_Translit::standards();
        foreach ($standards as &$standard) unset($standard['mapping'], $standard['ambiguities']);
        unset($standard);
        return $this->_respond(['ok' => TRUE, 'standards' => $standards]);
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
