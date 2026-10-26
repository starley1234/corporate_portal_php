<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj607_industry: API воркера — progress / result / fail (X-Worker-Token).
 */
class Controller_Nj607_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset=UTF-8');

        // Статус прогона — ПУБЛИЧНЫЙ (страница отрасли и так открыта по коду):
        // его опрашивает живой статус-ряд, у которого нет токена воркера.
        if ($this->request->action() === 'status')
        {
            return;
        }

        $token = trim((string) getenv('WORKER_API_TOKEN'));

        $ok = ($token === '') ? (Kohana::$environment === Kohana::DEVELOPMENT)
            : hash_equals($token, trim((string) $this->request->headers('x-worker-token')));

        if ( ! $ok)
        {
            // исключение, а не exit: ответ реально уйдёт воркеру со статусом 403
            throw HTTP_Exception::factory(403, 'Токен воркера не совпал');
        }
    }

    /**
     * GET /industry/api/status/<code> — публичный статус прогона для живого
     * статус-ряда (partials/live_status) + авто-остановка застрявшего воркера.
     *
     * Контракт общий: {status: queued|running|done|failed|cancelled, stage,
     * stages, elapsed_sec, silent_sec, found, error}. Статусы профиля
     * (new/running/done/error/closed) остаются в поле 'state'.
     */
    public function action_status()
    {
        $code = (string) $this->request->param('code');

        try
        {
            $r = $code !== '' ? Service_Nj607_Industry::by_code($code) : NULL;
        }
        catch (Exception $e)
        {
            $this->response->status(503);
            $this->response->body(json_encode(['error' => 'db_pending'], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($r === NULL)
        {
            $this->response->status(404);
            $this->response->body(json_encode(['error' => 'not_found']));

            return;
        }

        // у прогона нет своей колонки job_id — задачу ищем по каналу и коду
        $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
        $live  = $queue ? $queue->live_status('nj607.industry', 'code', $code) : [];

        // Авто-остановка застрявшего сбора: (1) пульса нет дольше 4 минут;
        // (2) пульс есть, прогресс застыл (10 минут). Раньше страница сама
        // прекращала опрос через 15 минут и писала «сбор затянулся» — вместо
        // этого задачу гасит сервер, а страница показывает понятный финал.
        if ($queue AND ! empty($live['found']) AND ($live['status'] ?? '') === 'running')
        {
            try
            {
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
                    $live = $queue->live_status('nj607.industry', 'code', $code);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj607: авто-остановка застрявшего сбора: :e', [':e' => $e->getMessage()]);
            }
        }

        $state = (string) $r['status'];

        $live_status = [
            'new'     => 'queued',
            'running' => 'running',
            'done'    => 'done',
            'error'   => 'failed',
            'closed'  => 'cancelled',
        ][$state] ?? 'running';

        $this->response->body(json_encode([
            'ok'          => TRUE,
            // контракт статус-ряда
            'status'      => $live_status,
            'stage'       => (string) $r['progress'] !== '' ? (string) $r['progress'] : (string) ($live['stage'] ?? ''),
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
            'silent_sec'  => $live['silent_sec'] ?? NULL,
            'found'       => 0,
            // прежние поля страницы
            'state'       => $state,
            'code'        => (string) $r['code'],
            'progress'    => (string) $r['progress'],
            'error'       => (string) ($r['error'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
    }

    public function action_progress()
    {
        Service_Nj607_Industry::api_progress((string) $this->request->param('code'),
            (string) $this->request->post('phase'));

        $this->_json(['ok' => TRUE]);
    }

    public function action_result()
    {
        $code = (string) $this->request->param('code');
        $md   = (string) $this->request->post('report_md');

        if ($code === '' OR trim($md) === '')
        {
            return $this->_json(['error' => 'Жду POST report_md'], 422);
        }

        $this->_json(Service_Nj607_Industry::api_result($code, $md,
            (string) $this->request->post('model_used')));
    }

    public function action_fail()
    {
        Service_Nj607_Industry::api_fail((string) $this->request->param('code'),
            (string) $this->request->post('error'));

        $this->_json(['ok' => TRUE]);
    }

    protected function _json(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
