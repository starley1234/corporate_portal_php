<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный JSON API быстрых опросов (NJ-520).
 *
 *   GET /nj520/api/stats/<code> → {"ok":true,"title":...,"total":N,"options":[...]}
 */
class Controller_Nj520_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_stats()
    {
        $results = Service_Nj520_Poll::results($this->request->param('id'));

        if ($results === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Опрос не найден'], 404);
        }

        // Распределение голосов — только тому, кто уже проголосовал (или по
        // закрытому опросу): иначе страницу с прятанием цифр обходили бы
        // одним запросом к этому же адресу.
        $voted = Service_Nj520_Poll::has_voted((int) $results['poll']['id'], Service_Nj520_Poll::voter_key());

        if ( ! Service_Nj520_Poll::results_visible($results['poll'], $voted))
        {
            return $this->_respond([
                'ok'     => TRUE,
                'title'  => $results['poll']['title'],
                'total'  => $results['total'],
                'closed' => FALSE,
                'hidden' => TRUE,
                'note'   => 'Результаты открываются после голоса на /p/'.$results['poll']['code'],
                'url'    => URL::base(TRUE).'p/'.$results['poll']['code'],
            ]);
        }

        return $this->_respond([
            'ok'      => TRUE,
            'hidden'  => FALSE,
            'title'   => $results['poll']['title'],
            'total'   => $results['total'],
            // Страница опроса обновляет цифры этим же ответом: ей нужны
            // лидер (бейдж) и признак закрытия (перестать опрашивать).
            'leader'  => $results['leader'],
            'closed'  => ! empty($results['poll']['closed_at']),
            'options' => $results['options'],
            'url'     => URL::base(TRUE).'p/'.$results['poll']['code'],
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
