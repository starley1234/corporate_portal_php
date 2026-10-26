<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Unix-время» (NJ-619).
 *
 *   GET/POST /nj619/api/convert {"ts": 1700000000, "tz": "Europe/Moscow"}
 *   → {"ok": true, "seconds": ..., "local": "...", "iso": "..."}
 */
class Controller_Nj619_Api extends Controller {

    public $auto_render = FALSE;

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

        if (empty($this->_body) && $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }
    }

    public function action_convert()
    {
        $ts     = isset($this->_body['ts']) ? $this->_body['ts'] : '';
        $tz     = isset($this->_body['tz']) ? $this->_body['tz'] : 'Europe/Moscow';
        $result = Service_Nj619_UnixTime::to_date($ts, $tz);

        return $this->_respond([
            'ok'      => (bool) $result['ok'],
            'error'   => (string) $result['error'],
            'seconds' => $result['seconds'] ?? NULL,
            'millis'  => $result['millis'] ?? NULL,
            'iso'     => $result['iso'] ?? '',
            'utc'     => $result['utc'] ?? '',
            'local'   => $result['local'] ?? '',
            'tz'      => $tz,
        ], $result['ok'] ? 200 : 400);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
