<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API генератора паролей (NJ-509).
 *
 *   POST /nj509/api/generate
 *   {"length": 24, "symbols": true, "exclude_ambiguous": true, "count": 5}
 *   → {"ok": true, "items": ["..."], "entropy": 141.2, "pool_size": 57}
 *
 * Пароли генерируются random_int (CSPRNG) и не сохраняются нигде — ни в БД,
 * ни в логах доступа (роут не пишет payload).
 */
class Controller_Nj509_Api extends Controller {

    public $auto_render = FALSE;

    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
        $this->response->headers('Cache-Control', 'no-store');

        $raw = $this->request->body();
        $decoded = $raw ? json_decode($raw, TRUE) : NULL;
        $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
    }

    public function action_generate()
    {
        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $result = Service_Nj509_Password::generate([
            'length'            => isset($this->_body['length']) ? $this->_body['length'] : 16,
            'upper'             => (bool) ($this->_body['upper'] ?? TRUE),
            'lower'             => (bool) ($this->_body['lower'] ?? TRUE),
            'digits'            => (bool) ($this->_body['digits'] ?? TRUE),
            'symbols'           => (bool) ($this->_body['symbols'] ?? FALSE),
            'exclude_ambiguous' => (bool) ($this->_body['exclude_ambiguous'] ?? FALSE),
            'count'             => isset($this->_body['count']) ? $this->_body['count'] : 1,
        ]);

        if ($result === NULL)
        {
            return $this->_respond([
                'ok' => FALSE,
                'error' => 'Некорректные параметры: length '.Service_Nj509_Password::MIN_LENGTH.
                    '–'.Service_Nj509_Password::MAX_LENGTH.', минимум один набор символов, count ≤ '.Service_Nj509_Password::MAX_COUNT,
            ], 400);
        }

        return $this->_respond(['ok' => TRUE] + $result);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
