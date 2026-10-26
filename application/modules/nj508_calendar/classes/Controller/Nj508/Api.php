<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API производственного календаря и дат (NJ-508).
 *
 *   POST|GET /nj508/api/calc   {"mode":"between","from":"01.05.2026","to":"31.05.2026"}
 *     mode = between {from,to,inclusive=1,approx=0}
 *            until   {target,today?,approx=0}
 *            age     {birth,on?,inclusive=0}
 *            add     {from,n,kind=calendar|work,approx=0}
 *   GET      /nj508/api/year?year=2026   календарь года (месяцы, нормы, праздники)
 *   GET      /nj508/api/info             какие годы заполнены
 *
 * Авторизация не нужна; данные не сохраняются. Года без календаря → 422
 * с error=no_calendar (молча «по прошлому году» не считаем).
 */
class Controller_Nj508_Api extends Controller {

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
        $flag = function ($key, $default) use ($p) {
            if ( ! array_key_exists($key, $p)) return $default;
            return in_array((string) $p[$key], ['1', 'true', 'on', 'yes'], TRUE);
        };

        switch ($mode)
        {
            case 'between':
                $r = Service_Nj508_Calendar::between($p['from'] ?? '', $p['to'] ?? '', $flag('inclusive', TRUE), $flag('approx', FALSE));
                break;

            case 'until':
                $r = Service_Nj508_Calendar::until($p['target'] ?? '', isset($p['today']) ? (string) $p['today'] : NULL, $flag('approx', FALSE));
                break;

            case 'age':
                $r = Service_Nj508_Calendar::age($p['birth'] ?? '', isset($p['on']) && $p['on'] !== '' ? (string) $p['on'] : NULL, $flag('inclusive', FALSE));
                break;

            case 'add':
                $r = Service_Nj508_Calendar::add($p['from'] ?? '', $p['n'] ?? 0, (string) ($p['kind'] ?? 'calendar'), $flag('approx', FALSE));
                break;

            default:
                return $this->_respond(['ok' => FALSE, 'error' => 'bad_mode', 'message' => 'mode: between | until | age | add'], 400);
        }

        if (isset($r['error']))
        {
            return $this->_respond(['ok' => FALSE] + $r, $r['error'] === 'no_calendar' ? 422 : 400);
        }

        return $this->_respond(['ok' => TRUE, 'result' => $r]);
    }

    public function action_year()
    {
        $q = $this->request->query();
        $y = (int) ($q['year'] ?? date('Y'));
        $t = Service_Nj508_Calendar::year_table($y);

        if ($t === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'no_calendar',
                'message' => 'Календаря на '.$y.' год нет.', 'known_years' => Service_Nj508_Calendar::known_years()], 422);
        }

        return $this->_respond(['ok' => TRUE, 'year' => $t]);
    }

    public function action_info()
    {
        return $this->_respond(['ok' => TRUE, 'known_years' => Service_Nj508_Calendar::known_years()]);
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
