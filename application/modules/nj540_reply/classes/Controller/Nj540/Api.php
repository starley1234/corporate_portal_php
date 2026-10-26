<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Отзыв 2 Ответ» (NJ-540).
 *
 *   GET  /nj540/api/status/<code>  → {"ok":true,"status":"new|done|error",...}
 *   POST /nj540/api/regen/<code>   → переписать ответ (CSRF), редирект на страницу
 */
class Controller_Nj540_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_status()
    {
        $row = Service_Nj540_Reply::by_code($this->request->param('id'));

        if ($row === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Отзыв не найден'], 404);
        }

        $row = Service_Nj540_Reply::reconcile($row);

        $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
        $live  = ($queue AND ! empty($row['job_id'])) ? $queue->status_of((int) $row['job_id']) : [];

        // Авто-остановка застрявшего воркера — здесь, потому что статус
        // опрашивает именно этот endpoint (владелец: «нет пульса 4 мин —
        // застрявший воркер лучше автоматически останавливать»). Иначе страница
        // результата бесконечно показывала «ИИ пишет ответ…».
        if ($queue AND ! empty($live['found']) AND ($live['status'] ?? '') === 'running')
        {
            try
            {
                $stopped = FALSE;

                if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                {
                    $stopped = $queue->auto_stop_silent((int) $row['job_id']) > 0;
                }

                if ( ! $stopped AND $queue->auto_stop_stalled((int) $row['job_id']) > 0)
                {
                    $stopped = TRUE;
                }

                if ($stopped)
                {
                    $row  = Service_Nj540_Reply::reconcile(Service_Nj540_Reply::by_code((string) $row['code']));
                    $live = $queue->status_of((int) $row['job_id']);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj540: авто-остановка застрявшего ответа: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        // Контракт общего статус-ряда (partials/live_status). Прежние поля
        // (status/reply/analysis/meta) остаются — их читает страница результата.
        $live_status = ! empty($live['found'])
            ? (string) $live['status']
            : ([
                'new'   => 'queued',
                'done'  => 'done',
                'error' => 'failed',
            ][(string) $row['status']] ?? 'queued');

        return $this->_respond(Service_Nj540_Reply::status_payload($row) + [
            'status_live' => $live_status,
            'stage'       => (string) ($live['stage'] ?? ''),
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
            'silent_sec'  => $live['silent_sec'] ?? NULL,
            'found'       => (bool) ($live['found'] ?? FALSE),
        ]);
    }

    public function action_regen()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        // Перегенерация — новая работа LLM, поэтому только по аккаунту
        // (владелец, 08.10.2026). Страницами результата по коду (ими делятся)
        // по-прежнему можно делиться: читающий статус остаётся открытым.
        if ( ! Gate::logged_in())
        {
            $this->redirect('auth/login?return_url=review');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        $result = Service_Nj540_Reply::regen(
            $this->request->param('id'),
            Service_Nj540_Reply::client_ip()
        );

        if (isset($result['code']))
        {
            HTTP::redirect('/review/'.$result['code'], 303);
        }

        // ошибка (лимит/очередь) — назад на страницу с сообщением
        HTTP::redirect('/review?regen_error='.urlencode((string) ($result['error'] ?? 'Не удалось поставить задачу')), 303);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
