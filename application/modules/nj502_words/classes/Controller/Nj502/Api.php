<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API «Сумма прописью» (NJ-502) — для интеграций (1С, боты).
 *
 *   POST /nj502/api/convert
 *   {"amount": "22122,00", "ucfirst": false, "kopecks_words": false}
 *   → {"ok": true, "result": {"text": "двадцать две тысячи сто двадцать два рубля 00 копеек", ...}}
 *
 * Данные не сохраняются; авторизация не нужна (чистое преобразование).
 */
class Controller_Nj502_Api extends Controller {

    public $auto_render = FALSE;

    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();
        $decoded = $raw ? json_decode($raw, TRUE) : NULL;
        $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
    }

    /**
     * POST /nj502/api/convert
     */
    public function action_convert()
    {
        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $amount    = isset($this->_body['amount']) ? $this->_body['amount'] : '';
        $ucfirst   = ! empty($this->_body['ucfirst']);
        $kopecks   = ! empty($this->_body['kopecks_words'])
            ? Service_Nj502_Words::KOPECKS_WORDS
            : Service_Nj502_Words::KOPECKS_DIGITS;

        $result = Service_Nj502_Words::convert($amount, $ucfirst, $kopecks);

        if ($result === NULL)
        {
            return $this->_respond([
                'ok' => FALSE,
                'error' => 'Некорректные данные: amount — сумма от 0 до 999 999 999 999 руб. (строка/число)',
            ], 400);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
