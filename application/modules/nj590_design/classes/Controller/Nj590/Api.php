<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API nj590 («Дизайн страницы»):
 *   GET  /design/api/status/<code>  — статус прогона + варианты (публично, по коду);
 *   POST /design/api/progress       — промежуточный прогресс воркера (токен);
 *   POST /design/api/upload         — HTML-вариант от воркера (токен).
 */
class Controller_Nj590_Api extends Controller {

    public function action_status()
    {
        $code = (string) $this->request->param('code');

        try
        {
            $run = $code !== '' ? Service_Nj590_Design::by_code($code) : NULL;
        }
        catch (Exception $e)
        {
            // таблицы ещё не созданы (миграции не применены) — честный JSON вместо исключения
            Kohana::$log->add(Log::ERROR, 'nj590: status api: :e', [':e' => $e->getMessage()]);
            $this->response->status(503);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(['error' => 'db_pending', 'hint' => 'Таблицы сервиса ещё не созданы — администратору: /migrate_pg'], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($run === NULL)
        {
            $this->response->status(404);
            $this->response->body(json_encode(['error' => 'not_found']));

            return;
        }

        $run  = Service_Nj590_Design::reconcile($run);
        $revs = [];

        $variants = [];

        foreach (Service_Nj590_Design::variants((int) $run['id']) as $v)
        {
            $variants[] = [
                'no'      => (int) $v['no'],
                'title'   => (string) $v['title'],
                'concept' => (string) $v['concept'],
                'size'    => (int) $v['size'],
                'file_url'=> ((string) $v['path'] !== '') ? '/design/'.$run['code'].'/file/'.(int) $v['id'] : '',
            ];
        }

        $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
        $live  = ($queue AND ! empty($run['job_id'])) ? $queue->status_of((int) $run['job_id']) : [];

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
                    $stopped = $queue->auto_stop_silent((int) $run['job_id']) > 0;
                }

                if ( ! $stopped AND $queue->auto_stop_stalled((int) $run['job_id']) > 0)
                {
                    $stopped = TRUE;
                }

                if ($stopped)
                {
                    $run   = Service_Nj590_Design::reconcile(Service_Nj590_Design::by_code((string) $run['code']));
                    $live = $queue->status_of((int) $run['job_id']);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj590: авто-остановка застрявшей задачи: :e', [':e' => $e->getMessage()]);
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
        ][(string) $run['status']] ?? 'running';

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode([
            // контракт статус-ряда
            'ok'             => TRUE,
            'status'         => $live_status,
            'stage'          => (string) $run['progress'] !== '' ? (string) $run['progress'] : (string) ($live['stage'] ?? ''),
            'stages'         => (array) ($live['stages'] ?? []),
            'elapsed_sec'    => (int) ($live['elapsed_sec'] ?? 0),
            'silent_sec'     => $live['silent_sec'] ?? NULL,
            'found'          => 0,
            'state'          => (string) $run['status'],
            'code'           => (string) $run['code'],
            'error'          => (string) $run['error'],
            'progress'       => (string) $run['progress'],
            'url'            => (string) $run['url'],
            'page_title'     => (string) $run['title'],
            'summary'        => (string) $run['summary'],
            'variants_count' => (int) $run['variants_count'],
            'model'          => (string) ($run['model'] ?? ''),
            'model_used'     => (string) ($run['model_used'] ?? ''),
            'chosen'         => (int) $run['chosen'],
            'variants'       => $variants,
            'job'            => $job,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /design/api/progress — прогресс воркера (виден в UI сразу). */
    public function action_progress()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $run_id = (int) $this->request->post('run_id');
        $phase  = trim((string) $this->request->post('phase'));

        if ($run_id <= 0 OR $phase === '')
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'need run_id и phase']));

            return;
        }

        Service_Nj590_Design::set_progress($run_id, $phase, [
            'page_title'   => trim((string) $this->request->post('page_title')),
            'page_summary' => trim((string) $this->request->post('page_summary')),
        ]);

        // прогон остановили? воркер завершится на ближайшей контрольной точке
        $run = Service_Nj590_Design::by_id($run_id);

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode([
            'ok'     => TRUE,
            'cancel' => ($run !== NULL AND (string) $run['status'] === 'error'),
        ]));
    }

    /** POST /design/api/upload — HTML-вариант от воркера. */
    public function action_upload()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $run_id     = (int) $this->request->post('run_id');
        $no         = (int) $this->request->post('no');
        $title      = trim((string) $this->request->post('title'));
        $concept    = trim((string) $this->request->post('concept'));
        $model_used = trim((string) $this->request->post('model')); // фактическая модель воркера

        if (empty($_FILES['file']['tmp_name']) OR ! is_uploaded_file($_FILES['file']['tmp_name']))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'no_file']));

            return;
        }

        $bytes = file_get_contents($_FILES['file']['tmp_name']);

        try
        {
            $res = Service_Nj590_Design::add_variant($run_id, $no, $title, $concept, $bytes, $model_used);
        }
        catch (Throwable $e)
        {
            // причина сразу доезжает до воркера и в красный баннер UI,
            // а не в HTML-страницу Kohana, из которой ничего не разобрать
            Kohana::$log->add(Log::ERROR, 'nj590: upload: :e', [':e' => $e->getMessage()]);
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'сбой сохранения: '
                .$e->getMessage().' @ '.basename((string) $e->getFile()).':'.$e->getLine()],
                JSON_UNESCAPED_UNICODE));

            return;
        }

        if (isset($res['error']))
        {
            $this->response->status(400);
            $this->response->body(json_encode($res));

            return;
        }

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(['ok' => TRUE, 'id' => $res['id']]));
    }

    /** Токен воркера (WORKER_API_TOKEN, заголовок X-Worker-Token). */
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
