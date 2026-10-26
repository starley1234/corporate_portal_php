<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj612 API: /touches/api/step — приём черновика от воркера (токен);
 * /touches/api/tick — пиналка nj603 (токен): автоотправка + детект ответов.
 */
class Controller_Nj612_Api extends Controller_Nj120_Api {

    // Контроллер очереди уже проверяет X-Worker-Token (см. nj120 Api);
    // наследуемся, чтобы не дублировать авторизацию воркеров.

    /**
     * Живой статус-ряд опрашивает БРАУЗЕР, а не воркер: страничные действия
     * (status/run/cancel) пропускаем мимо токена — их закрывает сессия владельца
     * (проверяется в самом действии). Остальные действия — как раньше, по токену.
     */
    protected function _authorize()
    {
        $action = strtolower((string) $this->request->action());

        if (in_array($action, ['status', 'run', 'cancel'], TRUE))
        {
            return TRUE;
        }

        return parent::_authorize();
    }

    public function action_step()
    {
        // токен проверен в before() родителя; тело — JSON воркера
        $res = Service_Nj612_Touch::save_step((array) $this->_body);

        $this->_respond($res + ['ok' => ! isset($res['error'])]);
    }

    /**
     * GET /touches/api/status/<code> — живой статус-ряд подготовки черновика.
     *
     * Общий контракт (partials/live_status) + авто-остановка застрявшего
     * воркера и подстановка последней задачи: ряд обязан завершиться сам.
     */
    public function action_status()
    {
        $code = (string) $this->request->param('code');
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        if ($uid <= 0)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'auth'], 403);
        }

        $live = Service_Nj612_Touch::live_for($uid, $code);

        if ($live === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Серия не найдена среди ваших'], 404);
        }

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
                    $live = Service_Nj612_Touch::live_for($uid, $code);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj612: авто-остановка задачи: :e', [':e' => $e->getMessage()]);
            }
        }

        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last = Service_Nj612_Touch::last_for($uid, $code);

            if ($last AND in_array((string) $last['status'], ['done', 'failed', 'cancelled'], TRUE))
            {
                $live = $last;
            }
        }

        return $this->_respond([
            'ok'          => TRUE,
            'status'      => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'found'       => 0,
            'error'       => (string) $live['error'],
            'finished_at' => (string) ($live['finished_at'] ?? ''),
        ]);
    }

    public function action_tick()
    {
        $res = Service_Nj612_Touch::tick();

        $this->_respond(['ok' => TRUE] + $res);
    }

}
