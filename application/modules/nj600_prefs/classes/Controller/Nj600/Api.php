<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj600: GET /prefs/api/profile — компактный профиль текущего владельца
 * (кука ubid для анонима, user_id для вошедшего). Ошибки — ЦЕЛИКОМ json {error}.
 */
class Controller_Nj600_Api extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
    }

    /** Токен воркера (WORKER_API_TOKEN сайта, заголовок X-Worker-Token). */
    protected function _token_ok()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return FALSE;
        }

        $given = (string) $this->request->headers('x-worker-token');

        return $given !== '' AND hash_equals($token, $given);
    }

    /**
     * Ответ JSON. ПОРЯДОК АРГУМЕНТОВ ТЕРПИМЫЙ — и это не украшательство.
     *
     * Найдено 28.09.2026 новым линтером `.tools/php_dupe_lint.py`: в этом классе
     * было объявлено ДВА метода `_json()` — `_json($status, $body)` и
     * `_json($data, $status)`. Синтаксис целый, линтеры молчали, а живой PHP
     * фатально падал на компиляции класса («Cannot redeclare …::_json()») — то
     * есть весь /prefs/api отвечал 500, включая выгрузку профилей для воркера
     * nj600.compact. Вызовы в файле остались обоих видов, поэтому метод один и
     * разбирает порядок по типу первого аргумента: массив — это данные.
     */
    protected function _json($first, $second = 200)
    {
        if (is_array($first))
        {
            $data   = $first;
            $status = (int) $second ?: 200;
        }
        else
        {
            $status = (int) $first ?: 200;
            $data   = is_array($second) ? $second : ['ok' => TRUE];
        }

        $this->response->status($status);
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /** GET /prefs/api/profiles — данные профилей для воркера nj600.compact (токен). */
    public function action_profiles()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал (WORKER_API_TOKEN сайта)']);

            return;
        }

        $limit = (int) $this->request->query('limit');

        try
        {
            $this->_json(200, ['profiles' => Service_Nj600_Prefs::dump_for_worker($limit > 0 ? min($limit, 40) : 15)]);
        }
        catch (Throwable $e)
        {
            $this->_json(400, ['error' => 'Выгрузка не удалась: '.$e->getMessage()]);
        }
    }

    /** POST /prefs/api/save — ИИ-резюме профиля от воркера (токен). */
    public function action_save()
    {
        if ( ! $this->_token_ok())
        {
            $this->_json(401, ['error' => 'Токен воркера не совпал']);

            return;
        }

        $owner = (string) $this->request->post('owner_key');

        if ( ! preg_match('/^(u:\d+|g:[a-z0-9]{6,40})$/i', $owner))
        {
            $this->_json(400, ['error' => 'owner_key ожидается вида u:<id> или g:<ubid>']);

            return;
        }

        $this->_json(200, Service_Nj600_Prefs::save_summary(
            $owner, (string) $this->request->post('summary'), (string) $this->request->post('model')));
    }

    /**
     * GET /prefs/api/status — живой статус-ряд сжатия профилей (управление).
     *
     * Контракт общий (partials/live_status) + авто-остановка застрявшего
     * воркера: страницу опрашивает именно этот endpoint.
     */
    public function action_status()
    {
        if ( ! $this->_admin_ok())
        {
            return $this->_json(['ok' => FALSE, 'error' => 'auth'], 403);
        }

        $live = Service_Nj600_Prefs::live_now();

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
                    $live = Service_Nj600_Prefs::live_now();
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj600: авто-остановка сжатия: :e', [':e' => $e->getMessage()]);
            }
        }

        if (empty($live['found']) OR ($live['status'] ?? '') === 'none')
        {
            $last = Service_Nj600_Prefs::last_run();

            if ($last AND in_array((string) $last['status'], ['done', 'failed', 'cancelled'], TRUE))
            {
                $live = $last;
            }
        }

        return $this->_json([
            'ok'          => TRUE,
            'status'      => (string) $live['status'],
            'stage'       => (string) $live['stage'],
            'stages'      => (array) ($live['stages'] ?? []),
            'elapsed_sec' => (int) $live['elapsed_sec'],
            'silent_sec'  => $live['silent_sec'],
            'found'       => 0,
            'error'       => (string) $live['error'],
            'finished_at' => (string) ($live['finished_at'] ?? ''),
        ]);
    }

    /** POST /prefs/api/cancel — остановить сжатие (управление, CSRF). */
    public function action_cancel()
    {
        if ( ! $this->_admin_ok())
        {
            return $this->_json(['ok' => FALSE, 'error' => 'auth'], 403);
        }

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу'], 403);
        }

        $res = Service_Nj600_Prefs::cancel_run();

        $this->_json($res, empty($res['error']) ? 200 : 409);
    }

    /** Управление модулем (acl nj600.admin) или роль admin. */
    protected function _admin_ok()
    {
        if ($this->_current_user === NULL OR ! $this->_current_user)
        {
            return FALSE;
        }

        try
        {
            if ($this->can('nj600.admin'))
            {
                return TRUE;
            }
        }
        catch (Throwable $e)
        {
            // нет карты прав — падать не должны, проверим роль
        }

        try
        {
            return Auth::instance()->logged_in('admin');
        }
        catch (Throwable $e)
        {
            return FALSE;
        }
    }


    // ------------------------------------------------------- рекомендации

    /**
     * GET /prefs/api/recommend?limit=4 — рекомендации текущему посетителю
     * (пользователю по user_id, гостю по куке ubid). Показы пишутся в журнал,
     * каждая строка несёт go — ссылку перехода с учётом клика.
     */
    public function action_recommend()
    {
        $limit = max(1, min(10, (int) $this->request->query('limit') ?: 4));

        try
        {
            $items = Service_Nj600_Reco::for_owner(NULL, $limit, TRUE);

            foreach ($items as $i => $r)
            {
                $items[$i]['go'] = ! empty($r['log_id']) ? '/prefs/api/go?id='.(int) $r['log_id'] : $r['href'];
                unset($items[$i]['score']);
            }

            $this->_json(['ok' => TRUE, 'items' => $items]);
        }
        catch (Throwable $e)
        {
            $this->_json(['ok' => FALSE, 'error' => 'Рекомендации недоступны: '.$e->getMessage()], 400);
        }
    }

    /** GET /prefs/api/go?id=<log_id> — переход по рекомендации (отмечает клик). */
    public function action_go()
    {
        $href = '';

        try
        {
            $href = Service_Nj600_Reco::click((int) $this->request->query('id'));
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj600: reco go: :e', [':e' => $e->getMessage()]);
        }

        HTTP::redirect($href !== '' ? $href : '/services', 302);
    }

    /** POST /prefs/api/dismiss {href, csrf} — «не предлагать» от самого посетителя. */
    public function action_dismiss()
    {
        if ($this->request->method() !== Request::POST)
        {
            return $this->_json(['ok' => FALSE, 'error' => 'POST'], 405);
        }

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Сессия устарела — обновите страницу'], 403);
        }

        try
        {
            $ok = Service_Nj600_Reco::dismiss((string) $this->request->post('href'));

            $this->_json(['ok' => (bool) $ok], $ok ? 200 : 400);
        }
        catch (Throwable $e)
        {
            $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 400);
        }
    }

    public function action_profile()
    {
        try
        {
            $p = Service_Nj600_Prefs::profile();

            $this->response->status(200);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode($p, JSON_UNESCAPED_UNICODE));
        }
        catch (Throwable $e)
        {
            $this->response->status(400);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode([
                'error' => 'Профиль недоступен: '.$e->getMessage()
                    .' @ '.basename($e->getFile()).':'.$e->getLine(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}
