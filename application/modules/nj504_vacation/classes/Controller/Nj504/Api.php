<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API калькулятора отпуска (NJ-504).
 *
 *   POST|GET /nj504/api/calc
 *     mode = pay          {income, months=12, days, rate=13}
 *            dates        {start, days}
 *            compensation {hire, dismiss, annual=28, used=0, income, months=12, rate=13}
 *            plan         {year, days}
 *     → {ok:true, result:{…}} (деньги — в копейках); ошибки: HTTP 400, {ok:false, error, message}
 *
 * Авторизация не нужна; данные не сохраняются.
 */
class Controller_Nj504_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_calc()
    {
        $p = $this->_params();
        $mode = (string) ($p['mode'] ?? '');

        switch ($mode)
        {
            case 'pay':
                $r = Service_Nj504_Vacation::pay($p['income'] ?? '', $p['months'] ?? 12, $p['days'] ?? 0, $p['rate'] ?? 13);
                break;

            case 'dates':
                $r = Service_Nj504_Vacation::dates($p['start'] ?? '', $p['days'] ?? 0);
                break;

            case 'compensation':
                $r = Service_Nj504_Vacation::compensation(
                    $p['hire'] ?? '', $p['dismiss'] ?? '', $p['annual'] ?? 28, $p['used'] ?? 0,
                    $p['income'] ?? '', $p['months'] ?? 12, $p['rate'] ?? 13);
                break;

            case 'plan':
                $r = Service_Nj504_Vacation::plan($p['year'] ?? date('Y'), $p['days'] ?? 14);
                break;

            default:
                return $this->_respond(['ok' => FALSE, 'error' => 'bad_mode', 'message' => 'mode: pay | dates | compensation | plan'], 400);
        }

        if (isset($r['error']))
        {
            return $this->_respond(['ok' => FALSE] + $r, 400);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $r]);
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
