<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API декодера (NJ-664).
 *
 *   POST /nj664/api/decode {"input":"{\"a\":1}","mode":"array"}
 *   → {"ok":true,"mode":"slashes","mode_label":"…","result":"…","stats":{…}}
 *
 * `mode`: json | min | array | php | all — во что превратить разобранные
 * данные. При `all` в `result` попадает JSON, а рядом — `php_array` и `php_code`.
 * Ничего не сохраняется и никуда не уходит.
 */
class Controller_Nj664_Api extends Controller {

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

    public function action_decode()
    {
        $input = (string) Arr::get($this->_in, 'input', Arr::get($this->_in, 'text', ''));
        $mode  = (string) Arr::get($this->_in, 'mode', 'json');

        $r = Service_Nj664_Decode::decode($input);

        if ( ! $r['ok'])
        {
            return $this->_respond([
                'ok'    => FALSE,
                'error' => $r['error'],
                'line'  => $r['line'],
                'col'   => $r['col'],
            ], 422);
        }

        $out = [
            'ok'         => TRUE,
            'mode'       => $r['mode'],
            'mode_label' => Service_Nj664_Decode::mode_label($r['mode']),
            'result'     => self::_render($r['data'], $mode),
            'stats'      => Service_Nj664_Decode::stats($r['data']),
        ];

        if ($mode === 'all')
        {
            $out['result']    = Service_Nj664_Decode::beautify($r['data']);
            $out['minified']  = Service_Nj664_Decode::minify($r['data']);
            $out['php_array'] = Service_Nj664_Decode::php_array($r['data']);
            $out['php_code']  = Service_Nj664_Decode::php_code($r['data']);
        }

        return $this->_respond($out);
    }

    /** Пример входа — чтобы агенту было чем проверить раскодирование. */
    public function action_sample()
    {
        return $this->_respond([
            'ok'     => TRUE,
            'input'  => Service_Nj664_Decode::sample(),
            'output' => Service_Nj664_Decode::beautify(json_decode(Service_Nj664_Decode::sample(), TRUE)),
        ]);
    }

    /** Во что превратить данные по режиму. */
    protected static function _render($data, $mode)
    {
        switch ($mode)
        {
            case 'min':
                return Service_Nj664_Decode::minify($data);

            case 'array':
                return Service_Nj664_Decode::php_array($data);

            case 'php':
                return Service_Nj664_Decode::php_code($data);

            default:
                return Service_Nj664_Decode::beautify($data);
        }
    }

    /** Ответ одним JSON. */
    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return NULL;
    }
}
