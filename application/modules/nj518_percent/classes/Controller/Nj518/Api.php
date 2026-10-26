<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API калькулятора процентов (NJ-518).
 *
 *   POST|GET /nj518/api/calc    {"mode": "markup", "a": 700, "b": 40}
 *   GET      /nj518/api/modes   список режимов
 *
 * Данные не сохраняются; авторизация не нужна (чистая арифметика).
 */
class Controller_Nj518_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_calc()
    {
        $p = $this->_params();

        $result = Service_Nj518_Percent::calculate(
            (string) ($p['mode'] ?? ''), $p['a'] ?? NULL, $p['b'] ?? NULL);

        if ($result === NULL)
        {
            return $this->_respond([
                'ok'    => FALSE,
                'error' => 'Некорректные данные: mode — один из /nj518/api/modes, a и b — числа (деление на ноль недопустимо).',
            ], 400);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $result]);
    }

    public function action_modes()
    {
        return $this->_respond(['ok' => TRUE, 'modes' => Service_Nj518_Percent::modes()]);
    }

    /** Параметры: JSON-тело, форма или query. */
    protected function _params()
    {
        $raw = $this->request->body();
        $out = [];

        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $out = is_array($decoded) ? $decoded : [];
        }

        return $out + (array) $this->request->post() + (array) $this->request->query();
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
