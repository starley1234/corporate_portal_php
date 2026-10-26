<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API часовых поясов (NJ-648).
 *
 *   GET /nj648/api/now?p[]=moscow&p[]=Asia/Tokyo
 *   → {"ok":true,"people":[{"city":"Москва","time":"14:05","utc":"UTC+3",…}]}
 *
 *   GET /nj648/api/slots?p[]=moscow&p[]=vladivostok&date=2026-10-05&from=9&to=18
 *   → {"ok":true,"best":[{"from":"09:00","to":"12:00","hours":3}],"hours":[…]}
 *
 * Код участника — либо город из справочника (`moscow`), либо зона IANA
 * (`Asia/Tokyo`). Ничего не сохраняет, авторизации не требует.
 */
class Controller_Nj648_Api extends Controller {

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

    /** Текущее время участников. */
    public function action_now()
    {
        $people = Service_Nj648_Timezone::people(Arr::get($this->_in, 'p', []));
        $out = [];

        foreach ($people as $person)
        {
            $out[] = Service_Nj648_Timezone::now($person);
        }

        return $this->_respond(['ok' => TRUE, 'people' => $out]);
    }

    /** Сетка суток и окна, удобные всем. */
    public function action_slots()
    {
        $people = Service_Nj648_Timezone::people(Arr::get($this->_in, 'p', []));

        $grid = Service_Nj648_Timezone::grid(
            $people,
            Arr::get($this->_in, 'date', ''),
            Arr::get($this->_in, 'from', NULL),
            Arr::get($this->_in, 'to', NULL));

        $hours = [];

        foreach ($grid['hours'] as $hour)
        {
            $times = [];

            foreach ($hour['cells'] as $cell)
            {
                $times[$cell['code']] = $cell['time'].($cell['shift'] ? sprintf('%+d', $cell['shift']) : '');
            }

            $hours[] = [
                'base'  => $hour['label'],
                'all'   => $hour['all'],
                'times' => $times,
            ];
        }

        return $this->_respond([
            'ok'     => TRUE,
            'date'   => $grid['date'],
            'base'   => $grid['base']['tz'],
            'work'   => ['from' => $grid['from'], 'to' => $grid['to']],
            'people' => array_map(static function ($p) {
                return ['code' => $p['code'], 'city' => $p['city'], 'tz' => $p['tz']];
            }, $grid['people']),
            'best'  => $grid['best'],
            'hours' => $hours,
        ]);
    }

    /** Справочник городов — чтобы агент знал допустимые коды. */
    public function action_cities()
    {
        $out = [];

        foreach (Service_Nj648_Timezone::cities() as $code => $city)
        {
            $out[] = ['code' => $code] + $city;
        }

        return $this->_respond(['ok' => TRUE, 'cities' => $out]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
