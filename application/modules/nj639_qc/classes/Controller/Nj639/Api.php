<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Контроль качества» (nj639_qc).
 *
 *   POST /qc/api/run              — ручной прогон (фото + правило + схема + эталоны)
 *   GET  /qc/api/result/<id>      — статус/результат прогона (lazy reconcile nj120)
 *   GET  /qc/api/runs             — последние прогоны
 *   GET  /qc/api/rules            — сохранённые правила
 *   POST /qc/api/source           — создать источник авторежима
 *   GET  /qc/api/sources          — источники
 *   POST /qc/api/source-fire      — ручной запуск источника (тест захвата)
 *   POST /qc/api/worker-result    — приём результата от воркера (X-Worker-Token)
 */
class Controller_Nj639_Api extends Controller {

    public $auto_render = FALSE;

    protected $_body = [];

    public function before()
    {
        parent::before();

        $this->response->headers('Content-Type', 'application/json; charset='.Kohana::$charset);

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

        if (empty($this->_body) AND $this->request->query())
        {
            $this->_body = (array) $this->request->query();
        }

        // Замок: контроль качества работает только по бесплатному аккаунту
        // (владелец, 08.10.2026) — кадр занимает vision-модель. Воркерские
        // точки (приём вердикта, тик источников) ходят по X-Worker-Token и
        // проверяются сами; внешний триггер живёт в отдельном контроллере
        // webhook со своим токеном.
        if ( ! Gate::logged_in() AND ! Gate::worker_action($this->request->action()))
        {
            throw HTTP_Exception::factory(401, 'Контроль качества работает только по бесплатному аккаунту');
        }
    }

    public function action_index()
    {
        $action = $this->request->param('action');
        $method = 'action_'.str_replace('-', '_', (string) $action);

        if ($action !== NULL AND method_exists($this, $method))
        {
            $this->{$method}();
        }
        else
        {
            $this->_respond(['ok' => FALSE, 'error' => 'Неизвестное действие API.'], 404);
        }
    }

    // ------------------------------------------------------------ ручной прогон

    public function action_run()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $max = Service_Nj639_Qc::max_image_bytes();

        if (isset($_FILES['file']) AND is_array($_FILES['file']) AND (int) ($_FILES['file']['size'] ?? 0) > $max)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Файл больше лимита '.$this->_cfg('max_image_mb', 15).' МБ.'], 413);
        }

        $res = Service_Nj639_Qc::submit($this->_body);

        if ( ! $res['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error'] ?? 'Не удалось поставить прогон.'], 400);
        }

        return $this->_respond([
            'ok'      => TRUE,
            'run_id'  => (int) $res['run_id'],
            'status'  => $res['status'],
            'poll'    => '/qc/api/result/'.(int) $res['run_id'],
        ]);
    }

    public function action_result()
    {
        $run_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));

        $run = Service_Nj639_Qc::run_by_id((string) Visitor::uid(), $run_id);

        if ($run === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Прогон не найден.'], 404);
        }

        return $this->_respond(['ok' => TRUE] + $run);
    }

    public function action_runs()
    {
        return $this->_respond([
            'ok'   => TRUE,
            'runs' => Service_Nj639_Qc::recent_runs((string) Visitor::uid(), 20),
        ]);
    }

    // --------------------------------------------------------------- правила

    public function action_rules()
    {
        return $this->_respond([
            'ok'    => TRUE,
            'rules' => Service_Nj639_Qc::rules_of((string) Visitor::uid()),
        ]);
    }

    public function action_rule_save()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $res = Service_Nj639_Qc::save_rule(
            (string) Visitor::uid(),
            (string) ($this->_body['name'] ?? ''),
            (string) ($this->_body['rule_text'] ?? ''),
            (array) ($this->_body['variants'] ?? []),
            (string) ($this->_body['schema_json'] ?? '')
        );

        if ( ! $res['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error']], 400);
        }

        return $this->_respond(['ok' => TRUE, 'rule_id' => (int) $res['rule_id'], 'name' => $res['name']]);
    }

    /** Одно правило (текст/варианты/схема/эталоны) — для формы при выборе. */
    public function action_rule()
    {
        $rule_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));

        $rule = Service_Nj639_Qc::rule_form_data((string) Visitor::uid(), $rule_id);

        if ($rule === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Правило не найдено.'], 404);
        }

        return $this->_respond(['ok' => TRUE] + $rule);
    }

    /** Прикрепить эталонные изображения к правилу (data URLs). */
    public function action_rule_refs()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $rule_id = (int) ($this->_body['rule_id'] ?? 0);

        if ($rule_id <= 0 OR ! Service_Nj639_Qc::rule_by_id((string) Visitor::uid(), $rule_id))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Правило не найдено.'], 404);
        }

        $saved = 0;

        foreach ((array) ($this->_body['refs'] ?? []) as $ref)
        {
            if (is_string($ref) AND strpos($ref, 'data:') === 0
                AND Service_Nj639_Qc::store_ref((string) Visitor::uid(), $rule_id, $ref))
            {
                $saved++;
            }
        }

        return $this->_respond(['ok' => TRUE, 'saved' => $saved]);
    }

    // -------------------------------------------------------------- источники

    public function action_source()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $res = Service_Nj639_Qc::create_source((string) Visitor::uid(), $this->_body);

        if ( ! $res['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error']], 400);
        }

        $out = ['ok' => TRUE, 'source_id' => (int) $res['source_id']];

        if (isset($res['webhook_url']))
        {
            $out['webhook_url'] = $res['webhook_url'];
        }

        return $this->_respond($out);
    }

    public function action_sources()
    {
        return $this->_respond([
            'ok'      => TRUE,
            'sources' => Service_Nj639_Qc::sources_of((string) Visitor::uid()),
        ]);
    }

    /** Ручной запуск источника (тест захвата кадра). */
    public function action_source_fire()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $code = (string) ($this->_body['code'] ?? '');

        $src = Service_Nj639_Qc::source_by_code((string) Visitor::uid(), $code);

        if ( ! $src)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Источник не найден.'], 404);
        }

        $res = Service_Nj639_Qc::source_fire([
            'source_id' => (int) $src['id'],
            'trigger'   => 'manual',
            'capture'   => (array) ($this->_body['capture'] ?? []),
        ]);

        if ( ! ($res['ok'] ?? FALSE))
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error'] ?? 'Не удалось запустить источник.'], 400);
        }

        return $this->_respond([
            'ok'     => TRUE,
            'run_id' => (int) $res['run_id'],
            'poll'   => '/qc/api/result/'.(int) $res['run_id'],
        ]);
    }

    /** Обновление полей источника (toggle enabled). */
    public function action_source_update()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $source_id = (int) ($this->_body['source_id'] ?? 0);
        $src = Service_Nj639_Qc::source_by_id($source_id);

        if ( ! $src)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Источник не найден.'], 404);
        }

        if ((string) $src['visitor_uid'] !== (string) Visitor::uid())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Это не ваш источник.'], 403);
        }

        $enabled = array_key_exists('enabled', $this->_body) ? (bool) $this->_body['enabled'] : NULL;

        if ($enabled !== NULL)
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_639_sources SET enabled = '.($enabled ? 'TRUE' : 'FALSE').' WHERE id = '.(int) $source_id, FALSE);
        }

        return $this->_respond(['ok' => TRUE]);
    }

    // ------------------------------------------------------- приём от воркера

    /**
     * Пиналка nj603 дёргает этот URL по расписанию (каждые 30 мин) — воркер
     * ставит задачи захвата для всех созревших timer-источников (паттерн nj605 collect).
     * Аутентификация: X-Worker-Token = WORKER_API_TOKEN.
     */
    public function action_cron_poll()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Токен воркера не совпал'], 403);
        }

        $res = Service_Nj639_Qc::cron_poll();

        return $this->_respond(['ok' => TRUE, 'queued' => (int) $res['queued']]);
    }

    /**
     * Воркер отдаёт результат VLM-анализа (http-режим очереди — колбэк).
     * Аутентификация: X-Worker-Token = WORKER_API_TOKEN (как nj605).
     */
    public function action_worker_result()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Токен воркера не совпал'], 403);
        }

        $run_id = (int) ($this->_body['run_id'] ?? 0);

        if ($run_id <= 0)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Жду run_id'], 422);
        }

        $res = (isset($this->_body['result']) AND is_array($this->_body['result']))
            ? $this->_body['result'] : $this->_body;

        if (is_string($res['result'] ?? NULL))
        {
            $decoded = json_decode($res['result'], TRUE);
            if (is_array($decoded)) $res = $decoded;
        }

        Service_Nj639_Qc::apply_result($run_id, $res);

        return $this->_respond(['ok' => TRUE]);
    }

    // ------------------------------------------------------------ служебное

    protected function _token_ok()
    {
        $token = trim((string) getenv('WORKER_API_TOKEN'));

        if ($token === '')
        {
            return Kohana::$environment === Kohana::DEVELOPMENT;
        }

        return hash_equals($token, trim((string) $this->request->headers('x-worker-token')));
    }

    protected function _rate_ok()
    {
        return class_exists('Service_Nj639_Qc')
            ? Service_Nj639_Qc::rate_ok(Service_Nj639_Qc::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много операций с этого адреса. Лимит: '
            .Service_Nj639_Qc::rate_limit_text().' — попробуйте позже.';
    }

    protected function _cfg($key, $default = NULL)
    {
        return Service_Nj639_Qc::cfg($key, $default);
    }

    protected function _db()
    {
        return Database::instance();
    }

    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
