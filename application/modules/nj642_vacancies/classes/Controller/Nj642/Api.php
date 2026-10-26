<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Вакансии компаний» (nj642_vacancies).
 *
 *   POST /vacancies/api/collect        — пиналка: поставить сборы созревшим мониторам (X-Worker-Token);
 *   POST /vacancies/api/ingest         — приём вакансий от воркера (X-Worker-Token);
 *   POST /vacancies/api/companies      — сайты работодателей, найденные воркером сам (X-Worker-Token);
 *   GET  /vacancies/api/hints/<code>   — кандидаты в компании монитора из нашей базы (X-Worker-Token);
 *   POST /vacancies/api/finish         — воркер закончил обход: задача → «Готово»;
 *   GET  /vacancies/api/status/<code>  — живой статус-ряд сбора (эталон /trends/<code>);
 *   POST /vacancies/api/run            — «Собрать сейчас» (владелец, CSRF);
 *   POST /vacancies/api/cancel         — «Остановить» (владелец, CSRF);
 *   POST /vacancies/api/match          — пометка вакансии: new|saved|hidden (владелец, CSRF).
 *
 * Статус-ряд отдаёт общий контракт partials/live_status: status/stage/stages/
 * elapsed_sec/silent_sec/found. Авто-остановка застрявшего воркера живёт здесь
 * же — этот endpoint и опрашивает страница.
 */
class Controller_Nj642_Api extends Controller {

    public $auto_render = FALSE;

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

    /** POST /vacancies/api/collect — сбор созревших мониторов (зовёт пиналка nj603). */
    public function action_collect()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $this->_json(200, Service_Nj642_Vacancies::api_collect());
    }

    /** POST /vacancies/api/ingest — вакансии, прочитанные воркером на сайте компании. */
    public function action_ingest()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $vacancies = $this->_body['vacancies'] ?? NULL;

        if (is_string($vacancies))
        {
            $vacancies = json_decode($vacancies, TRUE);
        }

        if ( ! is_array($vacancies))
        {
            return $this->_json(422, ['error' => 'Жду vacancies (JSON-массив) и company/domain']);
        }

        $res = Service_Nj642_Vacancies::api_ingest([
            'company'      => (string) ($this->_body['company'] ?? ''),
            'domain'       => (string) ($this->_body['domain'] ?? ''),
            'profile_code' => (string) ($this->_body['profile_code'] ?? ''),
            'source_url'   => (string) ($this->_body['source_url'] ?? ''),
            'watch_id'     => (int) ($this->_body['watch_id'] ?? 0),
            'full'         => ! empty($this->_body['full']),
            'pages_read'   => (int) ($this->_body['pages_read'] ?? 0),
            'vacancies'    => $vacancies,
        ]);

        $this->_json(isset($res['error']) ? 422 : 200, $res);
    }

    /**
     * GET /vacancies/api/hints/<code> — воркер в авто-режиме спрашивает: кого мы
     * уже знаем по этому городу и направлению (карта отрасли, профили, прошлые
     * сборы). Так сбор стартует даже без поисковика.
     */
    public function action_hints()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $code = (string) $this->request->param('code');

        $this->_json(200, [
            'ok'        => TRUE,
            'companies' => Service_Nj642_Vacancies::discover_seed($code),
        ]);
    }

    /** POST /vacancies/api/companies — воркер принёс сайты работодателей, найденные сам. */
    public function action_companies()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $found = $this->_body['companies'] ?? NULL;

        if (is_string($found))
        {
            $found = json_decode($found, TRUE);
        }

        if ( ! is_array($found))
        {
            return $this->_json(422, ['error' => 'Жду companies (JSON-массив) и watch_id']);
        }

        $res = Service_Nj642_Vacancies::api_companies([
            'watch_id'  => (int) ($this->_body['watch_id'] ?? 0),
            'source'    => (string) ($this->_body['source'] ?? 'поиск'),
            'companies' => $found,
        ]);

        $this->_json(isset($res['error']) ? 422 : 200, $res);
    }

    /** POST /vacancies/api/finish — воркер закончил обход (задача → «Готово»). */
    public function action_finish()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_json(403, ['error' => 'Токен воркера не совпал']);
        }

        $res = Service_Nj642_Vacancies::finish_run((int) ($this->_body['watch_id'] ?? 0));

        $this->_json(empty($res['error']) ? 200 : 404, $res);
    }

    /** GET /vacancies/api/status/<code> — живой статус-ряд сбора монитора. */
    public function action_status()
    {
        $code = (string) $this->request->param('code');
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        $live = Service_Nj642_Vacancies::live_for($code, $uid, $is_admin);

        if ($live === NULL)
        {
            return $this->_json(404, ['ok' => FALSE, 'error' => 'Монитор не найден или недоступен']);
        }

        // Авто-остановка застрявшего сбора: (1) воркер молчит дольше общего
        // порога, (2) пульс есть, а прогресса нет. Правило одно на все модули —
        // JobQueue::silent_stop_sec()/stalled_progress_sec().
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
                    $live = Service_Nj642_Vacancies::live_for($code, $uid, $is_admin);
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj642: авто-остановка сбора вакансий: :e', [':e' => $e->getMessage()]);
            }
        }

        // Завершённый ряд обязан быть виден: если живого сбора нет, отдаём
        // последнюю задачу («Готово»/«Ошибка»/«Отменено»), иначе страница
        // осталась бы без итога.
        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last = Service_Nj642_Vacancies::last_for($code, $uid, $is_admin);

            if ($last AND in_array((string) $last['status'], ['done', 'failed', 'cancelled'], TRUE))
            {
                $live = $last;
            }
        }

        $w     = Service_Nj642_Vacancies::by_code($code);
        $found = ($w AND ! empty($live['found']))
            ? Service_Nj642_Vacancies::found_since((int) $w['id'], $live['started_at'] ?? NULL)
            : 0;

        $this->_json(200, [
            'ok'          => TRUE,
            'status'      => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'error'       => (string) $live['error'],
            'found'       => (int) $found,
        ]);
    }

    /** POST /vacancies/api/run — «Собрать сейчас». */
    public function action_run()
    {
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        if ( ! $this->_csrf_ok())
        {
            return $this->_json(419, ['error' => 'Устаревшая форма — обновите страницу']);
        }

        $res = Service_Nj642_Vacancies::run_now((string) $this->_body['code'], $uid, $is_admin);

        $this->_json(empty($res['error']) ? 200 : 400, $res);
    }

    /** POST /vacancies/api/cancel — «Остановить». */
    public function action_cancel()
    {
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        if ( ! $this->_csrf_ok())
        {
            return $this->_json(419, ['error' => 'Устаревшая форма — обновите страницу']);
        }

        $res = Service_Nj642_Vacancies::cancel_run((string) $this->_body['code'], $uid, $is_admin);

        $this->_json(empty($res['error']) ? 200 : 400, $res);
    }

    /** POST /vacancies/api/match — пометка вакансии в ленте. */
    public function action_match()
    {
        list($uid, $is_admin) = Service_Nj120_Viewer::context();

        if ( ! $this->_csrf_ok())
        {
            return $this->_json(419, ['error' => 'Устаревшая форма — обновите страницу']);
        }

        $res = Service_Nj642_Vacancies::set_match_status($uid, (int) ($this->_body['match_id'] ?? 0),
            (string) ($this->_body['status'] ?? ''));

        $this->_json(empty($res['error']) ? 200 : 400, $res);
    }

    /** Сверка X-Worker-Token с .env сайта. */
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

    protected function _json($status, array $data)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
