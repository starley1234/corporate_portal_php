<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj610_inbox: API воркера (mail / drafts / poll, X-Worker-Token) и живого
 * статус-ряда страницы (status / run / cancel — только владелец).
 *
 * Почему разделение: раньше токен воркера проверялся в before() для ВСЕХ
 * действий, поэтому кнопки страницы (запуск проверки, остановка) сюда попасть
 * не могли. Теперь у воркерских действий своя проверка токена, у страничных —
 * сессия владельца.
 */
class Controller_Nj610_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset=UTF-8');
    }

    /** Проверка токена воркера (для mail / drafts / poll). */
    protected function _worker_guard()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        $ok = ($token === '') ? (Kohana::$environment === Kohana::DEVELOPMENT)
            : hash_equals($token, trim((string) $this->request->headers('x-worker-token')));

        if ( ! $ok)
        {
            // исключение, а не exit: ответ реально уйдёт воркеру со статусом 403
            throw HTTP_Exception::factory(403, 'Токен воркера не совпал');
        }
    }

    /** Страничные действия — только владелец (страница и так закрыта им). */
    protected function _owner_guard()
    {
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        if ( ! $is_admin)
        {
            return FALSE;
        }

        return TRUE;
    }

    /**
     * GET /inbox/api/status — живой статус-ряд чтения почты.
     *
     * Тот же контракт, что у всех модулей (partials/live_status), плюс
     * авто-остановка застрявшего воркера: страницу опрашивает этот endpoint.
     */
    public function action_status()
    {
        if ( ! $this->_owner_guard())
        {
            return $this->_json(['ok' => FALSE, 'error' => 'auth'], 403);
        }

        $live = Service_Nj610_Inbox::live_now();

        if (($live['status'] ?? '') === 'running' AND class_exists('Service_Nj120_JobQueue'))
        {
            try
            {
                $queue   = Service_Nj120_JobQueue::instance();
                $stopped = FALSE;

                if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                {
                    $stopped = $queue->auto_stop_silent((int) $live['job_id']) > 0;
                }

                if ( ! $stopped AND $queue->auto_stop_stalled((int) $live['job_id']) > 0)
                {
                    $stopped = TRUE;
                }

                if ($stopped)
                {
                    $live = Service_Nj610_Inbox::live_now();
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj610: авто-остановка прогона: :e', [':e' => $e->getMessage()]);
            }
        }

        // Ряд обязан завершиться сам: если активной задачи нет — отдаём последнюю.
        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last = Service_Nj610_Inbox::last_run();

            if ($last AND in_array((string) $last['status'], ['done', 'failed', 'cancelled'], TRUE))
            {
                $live = $last;
            }
        }

        $found = ! empty($live['found'])
            ? Service_Nj610_Inbox::found_since($live['started_at'])
            : 0;

        return $this->_json([
            'ok'          => TRUE,
            'status'      => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'found'       => (int) $found,
            'error'       => (string) $live['error'],
            'finished_at' => (string) ($live['finished_at'] ?? ''),
        ]);
    }

    /** POST /inbox/api/run — «Проверить почту сейчас» (владелец, CSRF). */
    public function action_run()
    {
        if ( ! $this->_owner_guard())
        {
            return $this->_json(['ok' => FALSE, 'error' => 'auth'], 403);
        }

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу'], 403);
        }

        $res = Service_Nj610_Inbox::run_now();

        $this->_json(empty($res['error']) ? 200 : 500, $res);
    }

    /** POST /inbox/api/cancel — остановить прогон (владелец, CSRF). */
    public function action_cancel()
    {
        if ( ! $this->_owner_guard())
        {
            return $this->_json(['ok' => FALSE, 'error' => 'auth'], 403);
        }

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу'], 403);
        }

        $res = Service_Nj610_Inbox::cancel_run();

        $this->_json(empty($res['error']) ? 200 : 409, $res);
    }

    /** Пинок от «Пиналки» (nj603): воркер сам читает IMAP и присылает письма. */
    public function action_poll()
    {
        $this->_worker_guard();

        try
        {
            $job_id = Service_Nj120_JobQueue::push('nj610.inbox', [], ['module' => 'nj610_inbox']);

            $this->_json(['ok' => TRUE, 'job_id' => $job_id]);
        }
        catch (Throwable $e)
        {
            $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    public function action_mail()
    {
        $this->_worker_guard();

        $messages = json_decode((string) $this->request->post('messages'), TRUE);

        if ( ! is_array($messages))
        {
            return $this->_json(['error' => 'Жду POST messages (JSON-массив)'], 422);
        }

        $this->_json(Service_Nj610_Inbox::api_mail($messages));
    }

    public function action_drafts()
    {
        $this->_worker_guard();

        $drafts = json_decode((string) $this->request->post('drafts'), TRUE);

        if ( ! is_array($drafts))
        {
            return $this->_json(['error' => 'Жду POST drafts (JSON-массив)'], 422);
        }

        $this->_json(Service_Nj610_Inbox::api_drafts($drafts));
    }

    protected function _json(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
