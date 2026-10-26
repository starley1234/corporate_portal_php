<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API генератора ID (NJ-617).
 *
 *   GET /nj617/api/generate?k=uuid4&n=5&upper=1
 *   → {"ok": true, "ids": ["..."], "kind": "uuid4"}
 */
class Controller_Nj617_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_generate()
    {
        $q     = $this->request->query();
        $post  = (array) $this->request->post();
        $kind  = isset($post['k']) ? $post['k'] : (isset($q['k']) ? $q['k'] : 'uuid4');
        $count = isset($post['n']) ? (int) $post['n'] : (isset($q['n']) ? (int) $q['n'] : 1);
        $upper = ! empty($post['upper']) || ! empty($q['upper']);

        if ( ! isset(Service_Nj617_Uuid::kinds()[$kind]))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Неизвестный формат: '.$kind.' (uuid4|uuid7|ulid)'], 400);
        }

        return $this->_respond([
            'ok'   => TRUE,
            'kind' => $kind,
            'ids'  => Service_Nj617_Uuid::batch($kind, $count, $upper),
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
