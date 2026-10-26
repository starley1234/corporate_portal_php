<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API Markdown-инструмента (NJ-615) — для интеграций и агентов.
 *
 *   POST /nj615/api/render {"text": "# Заголовок\n\nТекст"}
 *   → {"ok": true, "html": "...", "stats": {...}}
 *
 * Данные не сохраняются; авторизация не нужна.
 */
class Controller_Nj615_Api extends Controller {

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

    public function action_render()
    {
        $text   = isset($this->_body['text']) ? $this->_body['text'] : '';
        $result = Service_Nj615_Markdown::render($text);

        return $this->_respond([
            'ok'    => (bool) $result['ok'],
            'html'  => (string) $result['html'],
            'error' => (string) $result['error'],
            'stats' => $result['stats'],
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
