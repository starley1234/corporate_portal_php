<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API кредитного калькулятора (NJ-647).
 *
 *   GET  /nj647/api/calc?sum=5000000&rate=18&term=20&type=annuity
 *   POST /nj647/api/calc {"sum":5000000,"rate":18,"months":240,"extra":10000}
 *   → {"ok":true,"payment":…,"total":…,"interest":…,"months":…,"schedule":[…]}
 *
 *   GET /nj647/api/capacity?payment=60000&rate=18&term=20
 *   → {"ok":true,"sum":…} — какой кредит потянет такой платёж
 *
 * Ничего не сохраняет и не требует авторизации: это чистый расчёт.
 * График в ответе отдаётся по запросу (schedule=1) — он длинный.
 */
class Controller_Nj647_Api extends Controller {

    /** @var bool API не рендерит шаблоны */
    public $auto_render = FALSE;

    /** @var array тело запроса: JSON, POST или строка адреса */
    protected $_in = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

        $raw = $this->request->body();

        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_in = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_in = (array) $this->request->post();
        }

        if ( ! $this->_in)
        {
            $this->_in = (array) $this->request->query();
        }
    }

    public function action_calc()
    {
        $r = Service_Nj647_Loan::calculate($this->_in);

        if (empty($r['ok']))
        {
            return $this->_respond(['ok' => FALSE, 'error' => $r['error']], 422);
        }

        $out = [
            'ok'            => TRUE,
            'type'          => $r['type'],
            'sum'           => round($r['sum'], 2),
            'rate'          => $r['rate'],
            'months'        => $r['months'],
            'months_real'   => $r['months_real'],
            'payment'       => round($r['payment'], 2),
            'payment_first' => round($r['payment_first'], 2),
            'payment_last'  => round($r['payment_last'], 2),
            'total'         => round($r['total'], 2),
            'interest'      => round($r['interest'], 2),
            'interest_pct'  => round($r['interest_pct'], 2),
            'start'         => $r['start'],
            'end'           => $r['end'],
            'extra'         => round($r['extra'], 2),
            'extra_mode'    => $r['extra_mode'],
            'saved'         => $r['saved'] === NULL ? NULL : [
                'months'   => $r['saved']['months'],
                'interest' => round($r['saved']['interest'], 2),
            ],
        ];

        // График длинный (до 600 строк) — только по явной просьбе
        if ( ! empty($this->_in['schedule']))
        {
            $out['schedule'] = array_map(static function ($row) {
                return [
                    'n'         => $row['n'],
                    'date'      => $row['date'],
                    'payment'   => round($row['payment'], 2),
                    'interest'  => round($row['interest'], 2),
                    'principal' => round($row['principal'], 2),
                    'extra'     => round($row['extra'], 2),
                    'balance'   => round($row['balance'], 2),
                ];
            }, $r['rows']);
        }

        return $this->_respond($out);
    }

    public function action_capacity()
    {
        $payment = Service_Nj647_Loan::parse_number(Arr::get($this->_in, 'payment', ''));
        $rate    = Service_Nj647_Loan::parse_number(Arr::get($this->_in, 'rate', ''));
        $months  = Service_Nj647_Loan::parse_number(Arr::get($this->_in, 'months', ''));

        if ($months === NULL)
        {
            $term   = Service_Nj647_Loan::parse_number(Arr::get($this->_in, 'term', ''));
            $months = ($term === NULL) ? NULL : $term * 12;
        }

        $sum = ($payment === NULL OR $rate === NULL OR $months === NULL)
            ? NULL
            : Service_Nj647_Loan::capacity($payment, $rate, (int) $months);

        if ($sum === NULL)
        {
            return $this->_respond(
                ['ok' => FALSE, 'error' => 'Нужны платёж, ставка и срок: payment, rate, term (лет) или months.'], 422);
        }

        return $this->_respond([
            'ok'      => TRUE,
            'payment' => round($payment, 2),
            'rate'    => $rate,
            'months'  => (int) $months,
            'sum'     => round($sum, 2),
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
