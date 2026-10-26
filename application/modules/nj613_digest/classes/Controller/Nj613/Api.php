<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj613 API: /digest/api/tick — пиналка (собрать созревшие подписки);
 * /digest/api/item — приём готового резюме от воркера. Токен воркера.
 */
class Controller_Nj613_Api extends Controller_Nj120_Api {

    /**
     * Живой статус-ряд опрашивает БРАУЗЕР: действие status пропускаем мимо
     * токена воркера — доступ проверяем по сессии (свой дайджест видит только
     * его владелец). Остальные действия — как раньше, по токену.
     */
    protected function _authorize()
    {
        if (strtolower((string) $this->request->action()) === 'status')
        {
            return TRUE;
        }

        return parent::_authorize();
    }

    /** GET /digest/api/status — живой ряд сбора дайджеста текущего пользователя. */
    public function action_status()
    {
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        if ($uid <= 0)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'auth'], 403);
        }

        $live = Service_Nj613_Digest::live_for($uid);

        // Авто-остановка застрявшего воркера: страницу опрашивает именно этот
        // endpoint (нет пульса дольше общего порога / застой прогресса).
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
                    $live = Service_Nj613_Digest::live_for($uid);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj613: авто-остановка задачи: :e', [':e' => $e->getMessage()]);
            }
        }

        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last = Service_Nj613_Digest::last_for($uid);

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
        $this->_respond(Service_Nj613_Digest::tick());
    }

    public function action_item()
    {
        $res = Service_Nj613_Digest::save_item((array) $this->_body);

        $this->_respond($res + ['ok' => ! isset($res['error'])]);
    }

}
