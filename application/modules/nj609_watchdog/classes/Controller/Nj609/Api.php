<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj609_watchdog: API воркера — collect / events (X-Worker-Token).
 */
class Controller_Nj609_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset=UTF-8');
    }

    public function action_collect()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $this->_json(200, Service_Nj609_Watchdog::api_collect());
    }

    public function action_events()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $subject_id = (int) $this->request->post('subject_id');
        $events = json_decode((string) $this->request->post('events'), TRUE);

        if ($subject_id <= 0 OR ! is_array($events))
        {
            return $this->_json(422, ['error' => 'Жду POST subject_id и events (JSON-массив)']);
        }

        $this->_json(200, Service_Nj609_Watchdog::api_events($subject_id, $events));
    }

    /**
     * GET /watchdog/api/status/<code> — живой статус-ряд проверки контрагента.
     *
     * Контракт общий (partials/live_status): status/stage/stages/elapsed_sec/
     * silent_sec/found/error. Здесь же живёт авто-остановка застрявшего воркера —
     * страницу опрашивает именно этот endpoint: пульса нет дольше общего порога
     * (~4 мин) → гасим задачу; пульс есть, а прогресса нет → отдельное правило;
     * если активной задачи нет — отдаём последнюю, чтобы ряд завершился сам, а не
     * крутил спиннер вечно (жалоба владельца: «нет готово»).
     */
    public function action_status()
    {
        $code = (string) $this->request->param('code');
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        $live = Service_Nj609_Watchdog::live_for($code, $uid, $is_admin);

        if ($live === NULL)
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Контрагент не найден или недоступен']);
        }

        $subject = Service_Nj609_Watchdog::subject_by_code($code, $uid, $is_admin);
        $found   = 0;

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
                    $live = Service_Nj609_Watchdog::live_for($code, $uid, $is_admin);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj609: авто-остановка проверки: :e', [':e' => $e->getMessage()]);
            }
        }

        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last = Service_Nj609_Watchdog::last_for($code, $uid, $is_admin);

            if ($last AND in_array((string) $last['status'], ['done', 'failed', 'cancelled'], TRUE))
            {
                $live = $last;
            }
        }

        if ($subject AND ! empty($live['found']))
        {
            $found = Service_Nj609_Watchdog::found_since((int) $subject['id'], $live['started_at']);
        }

        return $this->_json(200, [
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

    protected function _token_ok()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return Kohana::$environment === Kohana::DEVELOPMENT;
        }

        return hash_equals($token, trim((string) $this->request->headers('x-worker-token')));
    }

    protected function _json($status, array $data)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
