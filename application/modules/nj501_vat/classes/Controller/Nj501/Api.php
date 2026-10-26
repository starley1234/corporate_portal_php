<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API калькулятора НДС (NJ-501) — для интеграций и ботов.
 *
 *   POST /nj501/api/calc
 *   {"amount": "10 000", "rate": 22, "mode": "extract"}
 *   → {"ok": true, "result": {"vat": "1 803,28 ₽", "net": "...", "total": "...", ...}}
 *
 * Данные не сохраняются; авторизация не нужна (чистая арифметика).
 * Тот же Service_Nj501_Vat, что и на странице — расхождение формул невозможно.
 */
class Controller_Nj501_Api extends Controller {

    /** @var bool API не рендерит шаблоны */
    public $auto_render = FALSE;

    /** @var bool запрос отклонён в before() */
    protected $_halt = FALSE;

    /** @var array тело запроса (JSON) */
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
    }

    /**
     * POST /nj501/api/calc
     */
    public function action_calc()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $amount = isset($this->_body['amount']) ? $this->_body['amount'] : '';
        $rate   = isset($this->_body['rate'])   ? $this->_body['rate']   : 22;
        $mode   = isset($this->_body['mode'])   ? $this->_body['mode']   : Service_Nj501_Vat::MODE_EXTRACT;

        $result = Service_Nj501_Vat::calculate($amount, $rate, $mode);

        if ($result === NULL)
        {
            return $this->_respond([
                'ok' => FALSE,
                'error' => 'Некорректные данные: amount — сумма (строка/число), rate — 0/5/7/10/20/22, mode — extract|add',
            ], 400);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    /**
     * Список ставок (справка).
     */
    public function action_rates()
    {
        if ($this->_halt) return;

        return $this->_respond(['ok' => TRUE, 'rates' => Service_Nj501_Vat::rates()]);
    }

    /**
     * Записать JSON в ответ.
     */
    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
