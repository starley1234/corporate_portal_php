<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API сравнения текстов (NJ-649).
 *
 *   POST /nj649/api/compare {"left":"…","right":"…","space":true,"words":true}
 *   → {"ok":true,"stats":{…},"unified":"--- было\n+++ стало\n@@ …","rows":[…]}
 *
 * `rows` отдаются только при `rows=1`: на большом тексте это тяжёлый ответ,
 * а чаще всего нужны сводка и готовая заплатка. Ничего не сохраняется.
 */
class Controller_Nj649_Api extends Controller {

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

    public function action_compare()
    {
        $left  = (string) Arr::get($this->_in, 'left', '');
        $right = (string) Arr::get($this->_in, 'right', '');

        if ($left === '' AND $right === '')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужны поля left и right с текстами.'], 422);
        }

        $r = Service_Nj649_Diff::compare($left, $right, [
            'case'  => ! empty($this->_in['case']),
            'space' => ! empty($this->_in['space']),
            'empty' => ! empty($this->_in['empty']),
            'words' => ! empty($this->_in['words']),
        ]);

        if (empty($r['ok']))
        {
            return $this->_respond(['ok' => FALSE, 'error' => $r['error']], 422);
        }

        $out = [
            'ok'      => TRUE,
            'stats'   => $r['stats'],
            'unified' => $r['unified'],
        ];

        if ( ! empty($this->_in['rows']))
        {
            $out['rows'] = array_map(static function ($row) {
                return [
                    'type'  => $row['type'],
                    'ln'    => $row['ln'],
                    'rn'    => $row['rn'],
                    'left'  => $row['left'],
                    'right' => $row['right'],
                ];
            }, $r['rows']);
        }

        return $this->_respond($out);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
