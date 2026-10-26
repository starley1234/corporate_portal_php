<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Работа 2 Оффер» (NJ-550).
 *
 *   GET /nj550/api/status/<code> → {"ok":true,"status":"new|done|error",...}
 */
class Controller_Nj550_Api extends Controller {

    public $auto_render = FALSE;

    public function before()
    {
        parent::before();
        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);
    }

    public function action_status()
    {
        $row = Service_Nj550_Jobs::by_code($this->request->param('id'));

        if ($row === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Поиск не найден'], 404);
        }

        $row = Service_Nj550_Jobs::reconcile($row);

        $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
        $live  = ($queue AND ! empty($row['job_id'])) ? $queue->status_of((int) $row['job_id']) : [];

        // Авто-остановка застрявшего поиска (как в других модулях с воркерами):
        // пульса нет дольше ~4 мин или прогресс застыл 10 мин — задача гасится,
        // reconcile переводит страницу в честную ошибку с кнопкой «Начать заново».
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
                    $row  = Service_Nj550_Jobs::reconcile(Service_Nj550_Jobs::by_code((string) $row['code']));
                    $live = $queue->status_of((int) $row['job_id']);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj550: авто-остановка застрявшего поиска: :e',
                    [':e' => $e->getMessage()]);
            }
        }

        // Контракт общего статус-ряда; прежние поля (status/summary/meta) целы.
        $live_status = ! empty($live['found'])
            ? (string) $live['status']
            : ([
                'new'   => 'queued',
                'done'  => 'done',
                'error' => 'failed',
            ][(string) $row['status']] ?? 'queued');

        return $this->_respond(Service_Nj550_Jobs::status_payload($row) + [
            'status_live' => $live_status,
            'stage'       => (string) ($live['stage'] ?? ''),
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
            'silent_sec'  => $live['silent_sec'] ?? NULL,
            'found'       => (bool) ($live['found'] ?? FALSE),
        ]);
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
