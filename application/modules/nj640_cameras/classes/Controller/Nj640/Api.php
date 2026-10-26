<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API «Камеры цеха» (nj640_cameras).
 *
 *   POST /cameras/api/run              — ручная проверка кадра по сценарию
 *   GET  /cameras/api/result/<id>      — статус/результат прогона (reconcile nj120)
 *   GET  /cameras/api/runs             — последние прогоны
 *   GET  /cameras/api/scenes           — сценарии
 *   POST /cameras/api/scenes           — сохранить/перезаписать сценарий
 *   GET  /cameras/api/scene/<id>       — один сценарий (для формы)
 *   POST /cameras/api/camera           — создать камеру
 *   GET  /cameras/api/cameras          — камеры
 *   POST /cameras/api/camera-fire      — ручной тест захвата камеры
 *   POST /cameras/api/camera-update    — включить/выключить камеру
 *   POST /cameras/api/confirm          — подтверждение/ложная тревога события
 *   POST /cameras/api/worker-result    — приём результата от воркера (X-Worker-Token)
 *   POST /cameras/api/cron-poll        — тик созревших камер (X-Worker-Token)
 */
class Controller_Nj640_Api extends Controller {

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

        // Замок: камеры цеха работают только по бесплатному аккаунту
        // (владелец, 08.10.2026) — кадр занимает vision-модель. Воркерские
        // точки (приём вердикта, тик камер) ходят по X-Worker-Token и
        // проверяются сами; вебхук-триггер — отдельный контроллер со своим
        // токеном в адресе.
        if ( ! Gate::logged_in() AND ! Gate::worker_action($this->request->action()))
        {
            throw HTTP_Exception::factory(401, 'Камеры цеха работают только по бесплатному аккаунту');
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

    // ------------------------------------------------------------ ручная проверка

    public function action_run()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $max = Service_Nj640_Cameras::max_image_bytes();

        if (isset($_FILES['file']) AND is_array($_FILES['file']) AND (int) ($_FILES['file']['size'] ?? 0) > $max)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Кадр больше лимита '.$this->_cfg('max_image_mb', 15).' МБ.'], 413);
        }

        $res = Service_Nj640_Cameras::submit($this->_body);

        if ( ! $res['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error'] ?? 'Не удалось поставить проверку.'], 400);
        }

        return $this->_respond([
            'ok'      => TRUE,
            'run_id'  => (int) $res['run_id'],
            'status'  => $res['status'],
            'poll'    => '/cameras/api/result/'.(int) $res['run_id'],
        ]);
    }

    public function action_result()
    {
        $run_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));

        $run = Service_Nj640_Cameras::run_by_id((string) Visitor::uid(), $run_id);

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
            'runs' => Service_Nj640_Cameras::recent_runs((string) Visitor::uid(), 20),
        ]);
    }

    // ---------------------------------------------------------------- сценарии

    public function action_scenes()
    {
        return $this->_respond([
            'ok'     => TRUE,
            'scenes' => Service_Nj640_Cameras::scenes_of((string) Visitor::uid()),
        ]);
    }

    /** Один сценарий (для формы при выборе). */
    public function action_scene()
    {
        $scene_id = (int) ($this->request->param('id') ?: ($this->_body['id'] ?? 0));

        $scene = Service_Nj640_Cameras::scene_form_data((string) Visitor::uid(), $scene_id);

        if ($scene === NULL)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Сценарий не найден.'], 404);
        }

        return $this->_respond(['ok' => TRUE] + $scene);
    }

    /** Создать/перезаписать сценарий (POST, с возможным scene_id — правка). */
    public function action_scene_save()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $res = Service_Nj640_Cameras::save_scene(
            (string) Visitor::uid(),
            (string) ($this->_body['topic'] ?? 'discipline'),
            (string) ($this->_body['name'] ?? ''),
            (string) ($this->_body['rule_text'] ?? ''),
            (array) ($this->_body['variants'] ?? []),
            (string) ($this->_body['schema_json'] ?? ''),
            (int) ($this->_body['scene_id'] ?? 0)
        );

        if ( ! $res['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error']], 400);
        }

        return $this->_respond(['ok' => TRUE, 'scene_id' => (int) $res['scene_id'], 'name' => $res['name']]);
    }

    // ------------------------------------------------------------------ камеры

    public function action_camera()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $res = Service_Nj640_Cameras::create_camera((string) Visitor::uid(), $this->_body);

        if ( ! $res['ok'])
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error']], 400);
        }

        $out = ['ok' => TRUE, 'camera_id' => (int) $res['camera_id']];

        if (isset($res['webhook_url']))
        {
            $out['webhook_url'] = $res['webhook_url'];
        }

        return $this->_respond($out);
    }

    public function action_cameras()
    {
        return $this->_respond([
            'ok'      => TRUE,
            'cameras' => Service_Nj640_Cameras::cameras_of((string) Visitor::uid()),
        ]);
    }

    /** Ручной тест захвата камеры. */
    public function action_camera_fire()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $code = (string) ($this->_body['code'] ?? '');

        $cam = Service_Nj640_Cameras::camera_by_code((string) Visitor::uid(), $code);

        if ( ! $cam)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Камера не найдена.'], 404);
        }

        $res = Service_Nj640_Cameras::camera_fire([
            'camera_id' => (int) $cam['id'],
            'trigger'   => 'manual',
            'capture'   => (array) ($this->_body['capture'] ?? []),
        ]);

        if ( ! ($res['ok'] ?? FALSE))
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error'] ?? 'Не удалось запустить камеру.'], 400);
        }

        return $this->_respond([
            'ok'     => TRUE,
            'run_id' => (int) $res['run_id'],
            'poll'   => '/cameras/api/result/'.(int) $res['run_id'],
        ]);
    }

    /** Включить/выключить камеру. */
    public function action_camera_update()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $camera_id = (int) ($this->_body['camera_id'] ?? 0);
        $cam = Service_Nj640_Cameras::camera_by_id($camera_id);

        if ( ! $cam)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Камера не найдена.'], 404);
        }

        if ((string) $cam['visitor_uid'] !== (string) Visitor::uid())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Это не ваша камера.'], 403);
        }

        $enabled = array_key_exists('enabled', $this->_body) ? (bool) $this->_body['enabled'] : NULL;
        $interval = array_key_exists('interval_sec', $this->_body) ? (int) $this->_body['interval_sec'] : NULL;
        $cooldown = array_key_exists('cooldown_sec', $this->_body) ? (int) $this->_body['cooldown_sec'] : NULL;

        $sets = [];

        if ($enabled !== NULL)
        {
            $sets[] = 'enabled = '.($enabled ? 'TRUE' : 'FALSE');
        }

        if ($interval !== NULL AND $interval >= 5 AND $interval <= 3600)
        {
            $sets[] = 'interval_sec = '.$interval;
        }

        if ($cooldown !== NULL AND $cooldown >= 0 AND $cooldown <= 86400)
        {
            $sets[] = 'cooldown_sec = '.$cooldown;
        }

        if ($sets)
        {
            self::_db()->query(Database::UPDATE,
                'UPDATE nj_640_cameras SET '.implode(', ', $sets).' WHERE id = '.(int) $camera_id, FALSE);
        }

        return $this->_respond(['ok' => TRUE]);
    }

    // ---------------------------------------------------------- подтверждения

    /** Подтверждение события (confirmed / false_alarm / ignored). */
    public function action_confirm()
    {
        if ( ! $this->_rate_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => $this->_rate_error()], 429);
        }

        $res = Service_Nj640_Cameras::confirm_event(
            (string) Visitor::uid(),
            (int) ($this->_body['event_id'] ?? 0),
            (string) ($this->_body['verdict'] ?? 'false_alarm'),
            (string) ($this->_body['note'] ?? '')
        );

        if ( ! ($res['ok'] ?? FALSE))
        {
            return $this->_respond(['ok' => FALSE, 'error' => $res['error'] ?? 'Событие не найдено.'], 404);
        }

        return $this->_respond(['ok' => TRUE] + $res);
    }

    // -------------------------------------------------------- приём от воркера

    /**
     * Расписание «cameras» пиналки nj603 вызывает cron_poll() напрямую (сервисом):
     * сайт ставит задачи захвата созревшим камерам (паттерн nj605 collect).
     */
    public function action_cron_poll()
    {
        if ( ! $this->_token_ok())
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Токен воркера не совпал'], 403);
        }

        $res = Service_Nj640_Cameras::cron_poll();

        return $this->_respond(['ok' => TRUE, 'queued' => (int) $res['queued'], 'skipped_hours' => (int) ($res['skipped_hours'] ?? 0)]);
    }

    /** Воркер отдаёт результат VLM-анализа (колбэк http-режима очереди). */
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

        Service_Nj640_Cameras::apply_result($run_id, $res);

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
        return class_exists('Service_Nj640_Cameras')
            ? Service_Nj640_Cameras::rate_ok(Service_Nj640_Cameras::client_ip())
            : TRUE;
    }

    protected function _rate_error()
    {
        return 'Слишком много операций с этого адреса. Лимит: '
            .Service_Nj640_Cameras::rate_limit_text().' — попробуйте позже.';
    }

    protected function _cfg($key, $default = NULL)
    {
        return Service_Nj640_Cameras::cfg($key, $default);
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
