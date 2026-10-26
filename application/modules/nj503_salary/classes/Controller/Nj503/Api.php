<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API калькулятора зарплаты (NJ-503).
 *
 *   POST|GET /nj503/api/calc
 *     {amount, mode: gross|net, year?, resident=1, children=0, disabled=0,
 *      single=0, sme=0, injury=0.2}
 *     → {ok:true, result:{gross_kop, net_kop, ndfl_kop, contrib_kop, cost_kop,
 *        months:[…12], year_total, rate_steps, …}}   (деньги — в копейках)
 *   GET /nj503/api/params   ставки и вычеты по годам
 *
 * Авторизация не нужна; данные не сохраняются.
 */
class Controller_Nj503_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_calc()
    {
        $p = $this->_params();
        $mode = (string) ($p['mode'] ?? 'gross');

        if ( ! in_array($mode, [Service_Nj503_Salary::MODE_GROSS, Service_Nj503_Salary::MODE_NET], TRUE))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'bad_mode', 'message' => 'mode: gross | net'], 400);
        }

        $opts = array_intersect_key($p, array_flip(['year', 'resident', 'children', 'disabled', 'single', 'sme', 'injury']));
        $r = Service_Nj503_Salary::calculate($p['amount'] ?? '', $mode, $opts);

        if ($r === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'bad_input',
                'message' => 'Проверьте сумму (больше нуля, не более '.number_format(Service_Nj503_Salary::MAX_MONTHLY, 0, '', ' ').' ₽ в месяц) и год ('.implode(', ', Service_Nj503_Salary::years()).').'], 400);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $r]);
    }

    public function action_params()
    {
        return $this->_respond(['ok' => TRUE, 'default_year' => Service_Nj503_Salary::default_year(), 'years' => Service_Nj503_Salary::all_params()]);
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
