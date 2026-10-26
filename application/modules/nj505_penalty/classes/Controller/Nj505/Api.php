<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API калькулятора пеней и процентов (NJ-505).
 *
 *   POST|GET /nj505/api/calc
 *     {mode: tax|gk395|contract, amount, due, paid?, payer=org|person,
 *      incl=1, moratorium=1, rate (% в день), cap (% потолок), today?}
 *     → {ok:true, result:{total_kop, rows:[…], days, warnings, …}} (деньги — копейки)
 *     Ошибки (400): bad_mode, bad_amount, bad_date, bad_rate, too_early, no_delay, too_big, no_rate
 *   GET /nj505/api/rates   история ключевой ставки Банка России
 *
 * Авторизация не нужна; данные не сохраняются.
 */
class Controller_Nj505_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_calc()
    {
        $p = $this->_params();
        $today = isset($p['today']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['today']) ? (string) $p['today'] : NULL;

        $r = Service_Nj505_Penalty::calculate(
            (string) ($p['mode'] ?? ''), $p['amount'] ?? '', (string) ($p['due'] ?? ''),
            isset($p['paid']) ? (string) $p['paid'] : NULL,
            array_intersect_key($p, array_flip(['payer', 'incl', 'moratorium', 'rate', 'cap'])),
            $today
        );

        if (isset($r['error']))
        {
            return $this->_respond(['ok' => FALSE] + $r, 400);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $r]);
    }

    public function action_rates()
    {
        return $this->_respond(['ok' => TRUE, 'last' => Service_Nj505_Penalty::last_rate(), 'rates' => Service_Nj505_Penalty::rates_table()]);
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
