<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API nj592 («Холодное письмо»):
 *   GET  /coldmail/api/status/<code> — статус + варианты (публично, по коду);
 *   POST /coldmail/api/progress      — прогресс воркера (токен);
 *   POST /coldmail/api/result        — варианты от воркера (токен).
 */
class Controller_Nj592_Api extends Controller {

    public function action_status()
    {
        $code = (string) $this->request->param('code');

        try
        {
            $l = $code !== '' ? Service_Nj592_Coldmail::by_code($code) : NULL;
        }
        catch (Exception $e)
        {
            // таблицы ещё не созданы (миграции не применены) — честный JSON вместо исключения
            Kohana::$log->add(Log::ERROR, 'nj592: status api: :e', [':e' => $e->getMessage()]);
            $this->response->status(503);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(['error' => 'db_pending', 'hint' => 'Таблицы сервиса ещё не созданы — администратору: /migrate_pg'], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($l === NULL)
        {
            $this->response->status(404);
            $this->response->body(json_encode(['error' => 'not_found']));

            return;
        }

        $l = Service_Nj592_Coldmail::reconcile($l);

        $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
        $live  = ($queue AND ! empty($l['job_id'])) ? $queue->status_of((int) $l['job_id']) : [];

        // Авто-остановка застрявшей задачи — здесь, потому что страницу
        // опрашивает именно этот endpoint (владелец: «нет пульса 4 мин —
        // застрявший воркер лучше автоматически останавливать»):
        //   1) пульса нет дольше JobQueue::silent_stop_sec() (~4 мин);
        //   2) пульс есть, а прогресс застыл (auto_stop_stalled, 10 мин).
        if ($queue AND ! empty($live['found']) AND ($live['status'] ?? '') === 'running')
        {
            try
            {
                $stopped = FALSE;

                if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                {
                    $stopped = $queue->auto_stop_silent((int) $l['job_id']) > 0;
                }

                if ( ! $stopped AND $queue->auto_stop_stalled((int) $l['job_id']) > 0)
                {
                    $stopped = TRUE;
                }

                if ($stopped)
                {
                    $l   = Service_Nj592_Coldmail::reconcile(Service_Nj592_Coldmail::by_code((string) $l['code']));
                    $live = $queue->status_of((int) $l['job_id']);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj592: авто-остановка застрявшей задачи: :e', [':e' => $e->getMessage()]);
            }
        }

        $job = ! empty($live['found'])
            ? ['status' => (string) $live['status'], 'elapsed_sec' => (int) $live['elapsed_sec'],
               'attempts' => (int) $live['attempts']]
            : NULL;

        // Общий контракт статус-ряда (partials/live_status): статус в терминах
        // queued/running/done/failed/cancelled. Внутреннее состояние остаётся
        // в 'state' — прежние потребители API не ломаются.
        $live_status = [
            'new'     => 'queued',
            'running' => 'running',
            'done'    => 'done',
            'error'   => 'failed',
        ][(string) $l['status']] ?? 'running';

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode([
            // контракт статус-ряда
            'ok'             => TRUE,
            'status'         => $live_status,
            'stage'          => (string) $l['progress'] !== '' ? (string) $l['progress'] : (string) ($live['stage'] ?? ''),
            'stages'         => (array) ($live['stages'] ?? []),
            'elapsed_sec'    => (int) ($live['elapsed_sec'] ?? 0),
            'silent_sec'     => $live['silent_sec'] ?? NULL,
            'found'          => 0,
            'state'          => (string) $l['status'],
            'code'     => (string) $l['code'],
            'error'    => (string) $l['error'],
            'progress' => (string) $l['progress'],
            'result'   => Service_Nj592_Coldmail::result_of($l),
            'sources'  => Service_Nj592_Coldmail::sources_of($l),
            'job'      => $job,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function action_progress()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $letter_id = (int) $this->request->post('letter_id');
        $phase     = trim((string) $this->request->post('phase'));

        if ($letter_id <= 0 OR $phase === '')
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'need letter_id и phase']));

            return;
        }

        Service_Nj592_Coldmail::set_progress($letter_id, $phase);

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(['ok' => TRUE]));
    }

    public function action_result()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $letter_id = (int) $this->request->post('letter_id');
        $result    = json_decode((string) $this->request->post('result'), TRUE);
        $sources   = json_decode((string) $this->request->post('sources'), TRUE);

        if ($letter_id <= 0 OR ! is_array($result))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'need letter_id и result (JSON)']));

            return;
        }

        try
        {
            $ok = Service_Nj592_Coldmail::set_result($letter_id, $result, is_array($sources) ? $sources : []);
        }
        catch (Throwable $e)
        {
            // причина сразу доезжает до воркера и в красный баннер UI
            Kohana::$log->add(Log::ERROR, 'nj592: result: :e', [':e' => $e->getMessage()]);
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'сбой сохранения: '
                .$e->getMessage().' @ '.basename((string) $e->getFile()).':'.$e->getLine()],
                JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(['ok' => $ok]));
    }

    protected function _auth()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        return $given !== '' AND hash_equals($token, $given);
    }
}
