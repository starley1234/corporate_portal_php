<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj606_leads: API воркера — POST /leads/api/collect и /leads/api/companies.
 */
class Controller_Nj606_Api extends Controller {

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

        $this->_json(200, Service_Nj606_Leads::api_collect());
    }

    public function action_companies()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $flow_id = (int) $this->request->post('flow_id');
        $companies = json_decode((string) $this->request->post('companies'), TRUE);

        if ($flow_id <= 0 OR ! is_array($companies))
        {
            return $this->_json(422, ['error' => 'Жду POST flow_id и companies (JSON-массив)']);
        }

        $this->_json(200, Service_Nj606_Leads::api_companies($flow_id, $companies));
    }

    /**
     * GET /leads/api/status/<code> — живой статус-ряд страницы потока (эталон
     * /trends/<code>): статус, ЭТАП воркера, секундомер, счётчик компаний.
     * Доступ — владельцу или админу.
     */
    public function action_status()
    {
        $code = (string) $this->request->param('code');
        // Api-контроллер наследует голый Controller: can()/_current_user здесь нет
        list($uid, $is_admin) = Service_Nj120_Viewer::context();
        $live = Service_Nj606_Leads::live_for($code, $uid, $is_admin);

        if ($live === NULL)
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Поток не найден или недоступен']);
        }

        $flow  = Service_Nj606_Leads::by_code($code);
        // Авто-остановка застрявшего воркера — здесь, потому что страницу
        // опрашивает именно этот endpoint (три правила из одного места):
        //   1) пульса нет дольше JobQueue::silent_stop_sec() (~4 мин) — воркер
        //      мёртв или заморожен;
        //   2) пульс есть, но прогресс не обновлялся дольше порога
        //      (auto_stop_stalled) — «живой, но стоит на месте»;
        //   3) ряд обязан завершиться сам: ниже подставляется последняя задача.
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
                    $live = Service_Nj606_Leads::live_for($code, $uid, $is_admin);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj606: авто-остановка застрявшего сбора: :e', [':e' => $e->getMessage()]);
            }
        }

        // Если живой задачи нет (её остановил авто-стоп, доставил результат или
        // свипер), отдаём ПОСЛЕДНЮЮ задачу: ряд на странице обязан завершиться
        // сам («Отменено»/«Готово»), а не крутить спиннер вечно.
        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last_job = Service_Nj606_Leads::last_for($code, $uid, $is_admin);

            if ($last_job AND in_array((string) $last_job['status'], ['done', 'failed', 'cancelled'], TRUE))
            {
                $live = $last_job;
            }
        }

        $found = ($flow AND $live['found'])
            ? Service_Nj606_Leads::found_since((int) $flow['id'], $live['started_at'])
            : 0;

        return $this->_json(200, [
            'ok'           => TRUE,
            'status'       => (string) $live['status'],
            'stage'        => (string) $live['stage'],
            'stages'       => (array) ($live['stages'] ?? []),
            'stages_ready' => count((array) ($live['stages'] ?? [])),
            'elapsed_sec'  => (int) $live['elapsed_sec'],
            'silent_sec'   => $live['silent_sec'],
            'error'        => (string) $live['error'],
            'found'        => $found,
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
