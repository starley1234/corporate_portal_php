<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API инструмента (NJ-614) — для интеграций, агентов и ботов.
 *
 *   POST /nj614/api/format   {"text": "...", "indent": 2, "sort": false}
 *   POST /nj614/api/validate {"text": "..."}
 *   → {"ok": true, "output": "...", "errors": [...], "stats": {...}}
 *
 * Работает и на GET (?q=&indent=) для простых вызовов из адресной строки.
 * Данные не сохраняются; авторизация не нужна (чистое преобразование).
 */
class Controller_Nj614_Api extends Controller {

    /** @var bool API не рендерит шаблоны */
    public $auto_render = FALSE;

    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();
        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }

        if (empty($this->_body) && $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }
    }

    public function action_format()
    {
        $text   = isset($this->_body['text']) ? $this->_body['text'] : '';
        $indent = isset($this->_body['indent']) ? (int) $this->_body['indent'] : 2;
        $sort   = ! empty($this->_body['sort']);

        $result = Service_Nj614_Json::format($text, $indent, $sort);

        return $this->_respond([
            'ok'       => (bool) $result['ok'],
            'output'   => (string) $result['output'],
            'error'    => (string) $result['error'],
            'position' => $result['pos'],
            'stats'    => $result['stats'],
            'mode'     => $result['mode'],
        ], $result['ok'] ? 200 : 400);
    }

    public function action_validate()
    {
        $text   = isset($this->_body['text']) ? $this->_body['text'] : '';
        $result = Service_Nj614_Json::validate($text);

        return $this->_respond([
            'ok'       => (bool) $result['ok'],
            'error'    => (string) $result['error'],
            'position' => $result['pos'],
            'stats'    => $result['stats'],
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
