<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API «Досье» (nj598):
 *  * GET  /dossier/api/status/<code> — публичный статус (поллинг страницы),
 *    поля статус-ряда: status/stage/stages/elapsed_sec/silent_sec;
 *  * POST /dossier/api/progress      — прогресс воркера (X-Worker-Token);
 *  * POST /dossier/api/result        — результат воркера (X-Worker-Token);
 *  * POST /dossier/api/fail          — ошибка воркера (X-Worker-Token).
 * Ошибки — ЦЕЛИКОМ в json {error} с причиной @ файл:строка.
 */
class Controller_Nj598_Api extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
    }

    /** GET /dossier/api/status/<code>. */
    public function action_status()
    {
        $this->_guarded(function () {
            $r = Service_Nj598_Dossier::by_code((string) $this->request->param('code'));

            if ($r === NULL)
            {
                $this->_json(['error' => 'not_found'], 404);

                return;
            }

            $r = Service_Nj598_Dossier::reconcile($r);

            $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
            $live  = ($queue AND ! empty($r['job_id'])) ? $queue->status_of((int) $r['job_id']) : [];

            // Авто-остановка застрявшей разведки — здесь, потому что страницу
            // опрашивает именно этот endpoint: (1) нет пульса дольше
            // silent_stop_sec() (~4 мин); (2) пульс есть, прогресс застыл
            // (auto_stop_stalled, 10 мин). После остановки reconcile закрывает
            // досье понятной ошибкой — без вечного спиннера.
            if ($queue AND ! empty($live['found']) AND ($live['status'] ?? '') === 'running')
            {
                try
                {
                    $stopped = FALSE;

                    if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                    {
                        $stopped = $queue->auto_stop_silent((int) $r['job_id']) > 0;
                    }

                    if ( ! $stopped AND $queue->auto_stop_stalled((int) $r['job_id']) > 0)
                    {
                        $stopped = TRUE;
                    }

                    if ($stopped)
                    {
                        $r    = Service_Nj598_Dossier::reconcile(Service_Nj598_Dossier::by_code((string) $r['code']));
                        $live = $queue->status_of((int) $r['job_id']);
                    }
                }
                catch (Throwable $e)
                {
                    Kohana::$log->add(Log::WARNING, 'nj598: авто-остановка застрявшей разведки: :e', [':e' => $e->getMessage()]);
                }
            }

            $job = ! empty($live['found'])
                ? ['status' => (string) $live['status'], 'elapsed_sec' => (int) $live['elapsed_sec']]
                : NULL;

            // Общий контракт статус-ряда (partials/live_status): статус в
            // терминах queued/running/done/failed/cancelled. Внутреннее
            // состояние остаётся в 'state' — прежние потребители не ломаются.
            $live_status = [
                'new'     => 'queued',
                'running' => 'running',
                'done'    => 'done',
                'error'   => 'failed',
            ][(string) $r['status']] ?? 'running';

            $this->_json([
                'ok'          => TRUE,
                // контракт статус-ряда
                'status'      => $live_status,
                'stage'       => (string) $r['progress'] !== '' ? (string) $r['progress'] : (string) ($live['stage'] ?? ''),
                'stages'      => (array) ($live['stages'] ?? []),
                'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
                'silent_sec'  => $live['silent_sec'] ?? NULL,
                'found'       => 0,
                // прежние поля
                'state'       => (string) $r['status'],
                'code'        => (string) $r['code'],
                'error'       => (string) $r['error'],
                'progress'    => (string) $r['progress'],
                'company'     => (string) $r['company'],
                'model_used'  => (string) $r['model_used'],
                'job'         => $job,
            ]);
        });
    }

    /** POST /dossier/api/progress — {dossier_id, phase}. */
    public function action_progress()
    {
        $this->_guarded(function () {
            if ( ! $this->_auth())
            {
                $this->_json(['error' => 'Доступ только по токену воркера (X-Worker-Token)'], 403);

                return;
            }

            Service_Nj598_Dossier::progress((int) Arr::get($_POST, 'dossier_id'),
                (string) Arr::get($_POST, 'phase'));
            $this->_json(['ok' => TRUE]);
        });
    }

    /** POST /dossier/api/result — {dossier_id, company, model_used, usage, result(json)}. */
    public function action_result()
    {
        $this->_guarded(function () {
            if ( ! $this->_auth())
            {
                $this->_json(['error' => 'Доступ только по токену воркера (X-Worker-Token)'], 403);

                return;
            }

            $id  = (int) Arr::get($_POST, 'dossier_id');
            $res = json_decode((string) Arr::get($_POST, 'result', ''), TRUE);

            if ($id <= 0 OR ! is_array($res))
            {
                $this->_json(['error' => 'Жду POST dossier_id и result (JSON: red_flags, pros, cheatsheet, sources)'], 422);

                return;
            }

            $usage = json_decode((string) Arr::get($_POST, 'usage', '[]'), TRUE);
            Service_Nj598_Dossier::save_result($id, $res,
                (string) Arr::get($_POST, 'company'),
                (string) Arr::get($_POST, 'model_used'),
                is_array($usage) ? $usage : []);

            $this->_json(['ok' => TRUE]);
        });
    }

    /** POST /dossier/api/fail — {dossier_id, error}. */
    public function action_fail()
    {
        $this->_guarded(function () {
            if ( ! $this->_auth())
            {
                $this->_json(['error' => 'Доступ только по токену воркера (X-Worker-Token)'], 403);

                return;
            }

            Service_Nj598_Dossier::save_error((int) Arr::get($_POST, 'dossier_id'),
                (string) Arr::get($_POST, 'error', 'неизвестная ошибка'));
            $this->_json(['ok' => TRUE]);
        });
    }

    /** Действие с самоотчётом: Throwable → json 400 с причиной @ файл:строка. */
    protected function _guarded(callable $fn)
    {
        try
        {
            $fn();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj598 api: :e', [':e' => $e->getMessage()]);
            $this->_json([
                'error' => 'Сбой: '.$e->getMessage()
                    .' @ '.basename($e->getFile()).':'.$e->getLine(),
            ], 400);
        }
    }

    /** Токен воркера: X-Worker-Token или ?token= (как в nj120/nj570/nj593). */
    protected function _auth()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        if ($given === '')
        {
            $given = (string) $this->request->query('token');
        }

        return hash_equals($token, $given);
    }

    protected function _json(array $data, $status = 200)
    {
        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
