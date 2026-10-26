<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API сокращателя (NJ-530).
 *
 *   POST /nj530/api/shorten  {"url": "https://…"} → {"ok":true,"code":…,"short":…}
 *   GET  /nj530/api/stats/<code>                    → {"ok":true,"clicks":N,…}
 */
class Controller_Nj530_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_shorten()
    {
        if ($this->request->method() !== Request::POST)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);
        }

        // Создание ссылок — только для зарегистрированных (решение владельца
        // 06.10.2026, как и веб-форма /jump). Статистика ниже — публичная.
        if ( ! Auth::instance()->logged_in())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Создание ссылок доступно после входа', 'login' => '/auth/login'], 401);
        }

        $raw = $this->request->body();
        $body = $raw ? json_decode($raw, TRUE) : (array) $this->request->post();
        $url = isset($body['url']) ? $body['url'] : '';

        $user_id = Auth::instance()->get_user() ? (int) Auth::instance()->get_user()->id : NULL;
        $result = Service_Nj530_Jump::create($url, $user_id, Service_Nj530_Jump::client_ip());

        if ($result === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужен корректный http(s) URL'], 400);
        }

        return $this->_respond([
            'ok'       => TRUE,
            'code'     => $result['code'],
            'url'      => $result['url'],
            'existing' => $result['existing'],
            'short'    => URL::base(TRUE).'x/'.$result['code'],
        ]);
    }

    public function action_stats()
    {
        $row = Service_Nj530_Jump::resolve($this->request->param('id'));

        if ($row === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Ссылка не найдена'], 404);
        }

        return $this->_respond([
            'ok'    => TRUE,
            'code'  => $row['code'],
            'url'   => $row['url'],
            'host'  => $row['host'],
            'clicks'=> (int) $row['clicks'],
            'created_at' => $row['created_at'],
            'short' => URL::base(TRUE).'x/'.$row['code'],
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
