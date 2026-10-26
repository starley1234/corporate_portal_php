<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API nj591 («Профиль компании»):
 *   GET  /company/api/status/<code>  — статус + результат (публично, по коду);
 *                                     поля статус-ряда: status/stage/stages/
 *                                     elapsed_sec/silent_sec (partials/live_status);
 *   POST /company/api/progress       — прогресс воркера (токен);
 *   POST /company/api/result         — строгий JSON профиля от воркера (токен).
 */
class Controller_Nj591_Api extends Controller {

    public function action_status()
    {
        $code = (string) $this->request->param('code');

        try
        {
            $p = $code !== '' ? Service_Nj591_Company::by_code($code) : NULL;
        }
        catch (Exception $e)
        {
            // таблицы ещё не созданы (миграции не применены) — честный JSON вместо исключения
            Kohana::$log->add(Log::ERROR, 'nj591: status api: :e', [':e' => $e->getMessage()]);
            $this->response->status(503);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(['error' => 'db_pending', 'hint' => 'Таблицы сервиса ещё не созданы — администратору: /migrate_pg'], JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($p === NULL)
        {
            $this->response->status(404);
            $this->response->body(json_encode(['error' => 'not_found']));

            return;
        }

        $p = Service_Nj591_Company::reconcile($p);

        $queue = class_exists('Service_Nj120_JobQueue') ? Service_Nj120_JobQueue::instance() : NULL;
        $live  = ($queue AND ! empty($p['job_id'])) ? $queue->status_of((int) $p['job_id']) : [];

        // Авто-остановка застрявшего сбора — здесь, потому что страницу
        // опрашивает именно этот endpoint, и он дешёвый (владелец: «нет пульса
        // 4 мин — застрявший воркер лучше автоматически останавливать»):
        //   1) пульса нет дольше JobQueue::silent_stop_sec() (~4 мин);
        //   2) пульс есть, а прогресс застыл (auto_stop_stalled, 10 мин).
        // Раньше баннер на /company/<code> в обоих случаях бесконечно показывал
        // «Воркер работает…», и профиль приходилось создавать заново вручную.
        if ($queue AND ! empty($live['found']) AND ($live['status'] ?? '') === 'running')
        {
            try
            {
                $stopped = FALSE;

                if ($live['silent_sec'] !== NULL AND (int) $live['silent_sec'] > $queue->silent_stop_sec())
                {
                    $stopped = $queue->auto_stop_silent((int) $p['job_id']) > 0;
                }

                if ( ! $stopped AND $queue->auto_stop_stalled((int) $p['job_id']) > 0)
                {
                    $stopped = TRUE;
                }

                if ($stopped)
                {
                    // reconcile переведёт профиль в честную ошибку с причиной
                    $p    = Service_Nj591_Company::reconcile(Service_Nj591_Company::by_code($code));
                    $live = $queue->status_of((int) $p['job_id']);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj591: авто-остановка застрявшего сбора: :e', [':e' => $e->getMessage()]);
            }
        }

        // Общий контракт живого статус-ряда (partials/live_status): статус в
        // терминах queued/running/done/failed/cancelled. Внутреннее состояние
        // профиля (new/running/done/error) остаётся в поле 'state' — старые
        // потребители API не ломаются.
        $live_status = [
            'new'     => 'queued',
            'running' => 'running',
            'done'    => 'done',
            'error'   => 'failed',
        ][(string) $p['status']] ?? 'running';

        $job = NULL;

        if ( ! empty($live['found']))
        {
            $job = ['status'      => (string) $live['status'],
                    'elapsed_sec' => (int) $live['elapsed_sec'],
                    // повтор виден в UI: «Повтор №2: …» — LLM-попытки бывают долгими
                    'attempts'    => (int) $live['attempts']];
        }

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode([
            'ok'          => TRUE,
            'code'        => (string) $p['code'],
            // контракт статус-ряда
            'status'      => $live_status,
            'stage'       => (string) $p['progress'] !== '' ? (string) $p['progress'] : (string) ($live['stage'] ?? ''),
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) ($live['elapsed_sec'] ?? 0),
            'silent_sec'  => $live['silent_sec'] ?? NULL,
            'found'       => 0,
            // прежние поля (обратная совместимость)
            'state'       => (string) $p['status'],
            'error'       => (string) $p['error'],
            'progress'    => (string) $p['progress'],
            'domain'      => (string) $p['domain'],
            'company'     => (string) $p['company'],
            'result'      => Service_Nj591_Company::result_of($p),
            'sources'     => Service_Nj591_Company::sources_of($p),
            'job'         => $job,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** POST /company/api/progress — прогресс воркера. */
    public function action_progress()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $profile_id = (int) $this->request->post('profile_id');
        $phase      = trim((string) $this->request->post('phase'));

        if ($profile_id <= 0 OR $phase === '')
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'need profile_id и phase']));

            return;
        }

        Service_Nj591_Company::set_progress($profile_id, $phase);

        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(['ok' => TRUE]));
    }

    /** POST /company/api/result — строгий JSON профиля от воркера. */
    public function action_result()
    {
        if ( ! $this->_auth())
        {
            $this->response->status(401);
            $this->response->body(json_encode(['error' => 'auth']));

            return;
        }

        $profile_id = (int) $this->request->post('profile_id');
        $company    = trim((string) $this->request->post('company'));
        $result     = json_decode((string) $this->request->post('result'), TRUE);
        $sources    = json_decode((string) $this->request->post('sources'), TRUE);

        if ($profile_id <= 0 OR ! is_array($result))
        {
            $this->response->status(400);
            $this->response->body(json_encode(['error' => 'need profile_id и result (JSON)']));

            return;
        }

        try
        {
            $ok = Service_Nj591_Company::set_result($profile_id, $company,
                $result, is_array($sources) ? $sources : []);
        }
        catch (Throwable $e)
        {
            // причина сразу доезжает до воркера и в красный баннер UI
            Kohana::$log->add(Log::ERROR, 'nj591: result: :e', [':e' => $e->getMessage()]);
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
