<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: JSON API.
 *
 * Воркер (X-Worker-Token):
 *   POST /cad/api/upload        — файл модели (step/stl/glb/ракурс) в base64;
 *   POST /cad/api/result        — итог прогона: код, схема, параметры, отчёт.
 *
 * Страница (сессия + CSRF):
 *   GET  /cad/api/status/<code> — живой статус-ряд сборки (общий контракт);
 *   POST /cad/api/run           — «собрать заново» / «пересобрать по параметрам»;
 *   POST /cad/api/cancel        — остановить сборку;
 *   POST /cad/api/delete        — удалить деталь.
 *
 * Живой ряд обязан завершаться сам, поэтому в action_status живёт
 * авто-остановка застрявшего воркера и подстановка последней задачи
 * (как в /vacancies, /watchdog, /touches и т.д.).
 */
class Controller_Nj643_Api extends Controller {

    public $auto_render = FALSE;

    /** @var array */
    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset=UTF-8');

        $raw = $this->request->body();

        if ($raw)
        {
            $decoded = json_decode($raw, TRUE);
            $this->_body = is_array($decoded) ? $decoded : (array) $this->request->post();
        }
        else
        {
            $this->_body = (array) $this->request->post();
        }
    }

    /**
     * Диспетчер действий: Kohana зовёт action_index, а имя действия приходит
     * параметром. Дефис в адресе заменяем на подчёркивание, иначе
     * /cad/api/merge-parts не нашёл бы action_merge_parts (так же у nj641).
     */
    public function action_index()
    {
        $action = (string) $this->request->param('action');
        $method = 'action_'.str_replace('-', '_', $action);

        if ($action !== '' AND method_exists($this, $method))
        {
            $this->{$method}();

            return;
        }

        $this->_json(404, ['ok' => FALSE, 'error' => 'Неизвестное действие API ('.$action.')']);
    }

    // ------------------------------------------------------------------
    // Живой статус-ряд
    // ------------------------------------------------------------------

    /** GET /cad/api/status/<code> — состояние сборки детали. */
    public function action_status()
    {
        $code = (string) $this->request->param('id');

        if ($code === '')
        {
            $code = (string) $this->request->query('code');
        }

        $live = Service_Nj643_Cad::live_for($code);

        if ($live === NULL)
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Деталь не найдена']);
        }

        // Авто-остановка застрявшего воркера: страницу опрашивает именно этот
        // endpoint. Нет пульса дольше общего порога (~4 мин) — гасим задачу;
        // пульс есть, а прогресса нет — отдельное правило.
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
                    $live = Service_Nj643_Cad::live_for($code);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj643: авто-остановка сборки: :e', [':e' => $e->getMessage()]);
            }
        }

        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last = Service_Nj643_Cad::last_for($code);

            if ($last AND in_array((string) $last['status'], ['done', 'failed', 'cancelled'], TRUE))
            {
                $live = $last;
            }
        }

        $p = Service_Nj643_Cad::by_code($code);

        return $this->_json(200, [
            'ok'          => TRUE,
            'status'      => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'found'       => (int) ($p['files_count'] ?? 0),
            'error'       => (string) $live['error'],
            'note'        => (string) ($live['note'] ?? ''),
            'finished_at' => (string) ($live['finished_at'] ?? ''),
        ]);
    }

    // ------------------------------------------------------------------
    // Действия страницы
    // ------------------------------------------------------------------

    /** POST /cad/api/run — собрать заново или пересобрать по параметрам. */
    public function action_run()
    {
        $guard = $this->_guard();

        if ($guard !== NULL)
        {
            return $guard;
        }

        $code   = (string) ($this->_body['code'] ?? '');
        $action = (string) ($this->_body['action'] ?? 'again');
        $params = (array) ($this->_body['params'] ?? []);

        if ($action === 'rebuild' AND $params !== [])
        {
            $res = Service_Nj643_Cad::rebuild_part($code, $params, $this->_uid(), $this->_is_admin());
        }
        else
        {
            $res = Service_Nj643_Cad::run_again($code, $this->_uid(), $this->_is_admin());
        }

        $this->_json(empty($res['error']) ? 200 : 409, $res);
    }

    /** POST /cad/api/cancel — остановить сборку. */
    public function action_cancel()
    {
        $guard = $this->_guard();

        if ($guard !== NULL)
        {
            return $guard;
        }

        $res = Service_Nj643_Cad::cancel_part((string) ($this->_body['code'] ?? ''), $this->_uid(), $this->_is_admin());

        $this->_json(empty($res['error']) ? 200 : 409, $res);
    }

    /** POST /cad/api/delete — удалить деталь. */
    public function action_delete()
    {
        $guard = $this->_guard();

        if ($guard !== NULL)
        {
            return $guard;
        }

        $res = Service_Nj643_Cad::delete_part((string) ($this->_body['code'] ?? ''), $this->_uid(), $this->_is_admin());

        $this->_json(empty($res['error']) ? 200 : 409, $res);
    }

    // ------------------------------------------------------------------
    // Приём от воркера
    // ------------------------------------------------------------------

    /** POST /cad/api/upload — файл модели (base64). */
    public function action_upload()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $res = Service_Nj643_Cad::api_upload($this->_body);

        $this->_json(empty($res['error']) ? 200 : (int) ($res['status'] ?? 422), $res);
    }

    /** POST /cad/api/result — итог прогона. */
    public function action_result()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $res = Service_Nj643_Cad::api_result($this->_body);

        $this->_json(empty($res['error']) ? 200 : (int) ($res['status'] ?? 422), $res);
    }

    // ------------------------------------------------------------------
    // Служебное
    // ------------------------------------------------------------------

    /** Токен воркера (WORKER_API_TOKEN сайта, заголовок X-Worker-Token). */
    protected function _token_ok()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return Kohana::$environment === Kohana::DEVELOPMENT;
        }

        return hash_equals($token, trim((string) $this->request->headers('x-worker-token')));
    }

    /** CSRF: форма страницы несёт csrf-токен сессии. */
    protected function _csrf_ok()
    {
        $token = trim((string) ($this->_body['csrf'] ?? ''));

        return $token !== '' AND $token === Security::token();
    }

    /** Гость — 401: деталь открывается по коду, но запускать сборку нельзя. */
    protected function _guard()
    {
        if ($this->_uid() <= 0)
        {
            return $this->_json(403, ['ok' => FALSE, 'error' => 'Войдите, чтобы управлять деталью']);
        }

        if ( ! $this->_csrf_ok())
        {
            return $this->_json(403, ['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу']);
        }

        return NULL;
    }

    protected function _uid()
    {
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        return (int) $uid;
    }

    protected function _is_admin()
    {
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        return (bool) $is_admin;
    }

    protected function _json($status, array $data)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
