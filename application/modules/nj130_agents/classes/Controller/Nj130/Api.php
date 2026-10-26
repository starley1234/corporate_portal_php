<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JSON API LLM-агентов (модуль NJ-130). Доступ — только авторизованным.
 *
 *   GET  /nj130/api/agents          — список агентов
 *   POST /nj130/api/run             {"agent":"support","input":{"message":"..."}} → {"ok":true,"run_id":N}
 *   GET  /nj130/api/run/<id>        — статус + диалог (заодно применяет результат задачи)
 *   POST /nj130/api/continue/<id>   {"message":"..."} — продолжить диалог
 */
class Controller_Nj130_Api extends Controller_Base {

    /** @var bool API не рендерит шаблон */
    public $auto_render = FALSE;

    /** @var bool запрос отклонён в before() */
    protected $_halt = FALSE;

    /** @var array тело запроса (JSON) */
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

        if ( ! Auth::instance()->get_user())
        {
            $this->_halt = TRUE;
            $this->_respond(['ok' => FALSE, 'error' => 'Требуется авторизация'], 403);
        }
    }

    /**
     * Список агентов.
     */
    public function action_agents()
    {
        if ($this->_halt) return;

        return $this->_respond(['ok' => TRUE, 'agents' => Service_Nj130_Agents::list_agents()]);
    }

    /**
     * Запустить агента.
     */
    public function action_run()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $slug  = isset($this->_body['agent']) ? (string) $this->_body['agent'] : '';
        $input = isset($this->_body['input']) && is_array($this->_body['input']) ? $this->_body['input'] : [];

        if ($slug === '' OR empty($input))
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужны agent и input'], 400);
        }

        try
        {
            $user = Auth::instance()->get_user();
            $run_id = Service_Nj130_Agents::start_run($slug, $input, $user ? $user->id : NULL);
            return $this->_respond(['ok' => TRUE, 'run_id' => $run_id]);
        }
        catch (Exception $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Статус запуска + диалог (применяет результат задачи, если готов).
     */
    public function action_run_status()
    {
        if ($this->_halt) return;

        // Роут /nj130/api/run/<id>: Kohana выбирает action по имени, поэтому
        // GET /nj130/api/run/5 попадает в action_run без id... см. init.php:
        // отдельный роут run_status. Здесь — обработка по <id>.
        return $this->_run_status((int) $this->request->param('id'));
    }

    /**
     * Статус запуска (явный роут /nj130/api/status/<id>).
     */
    public function action_status()
    {
        if ($this->_halt) return;

        return $this->_run_status((int) $this->request->param('id'));
    }

    /**
     * Продолжить диалог.
     */
    public function action_continue()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
            return $this->_respond(['ok' => FALSE, 'error' => 'Только POST'], 405);

        $run_id  = (int) $this->request->param('id');
        $message = isset($this->_body['message']) ? (string) $this->_body['message'] : '';

        if (trim($message) === '')
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужно message'], 400);
        }

        try
        {
            Service_Nj130_Agents::continue_run($run_id, $message);
            return $this->_respond(['ok' => TRUE, 'run_id' => $run_id]);
        }
        catch (Exception $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 400);
        }
    }

    // ---------------------------------------------------------------------

    protected function _run_status($run_id)
    {
        if ($run_id <= 0)
        {
            return $this->_respond(['ok' => FALSE, 'error' => 'Нужен id запуска'], 400);
        }

        try
        {
            $run = Service_Nj130_Agents::sync_run($run_id);

            if ($run === NULL)
            {
                return $this->_respond(['ok' => FALSE, 'error' => 'Запуск не найден'], 404);
            }

            return $this->_respond([
                'ok'         => TRUE,
                'run'        => $run,
                'transcript' => Service_Nj130_Agents::transcript($run_id),
            ]);
        }
        catch (Exception $e)
        {
            return $this->_respond(['ok' => FALSE, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Записать JSON в ответ (выполнение продолжится — используйте с $_halt).
     */
    protected function _respond(array $data, $status = 200)
    {
        $this->response->status((int) $status);
        $this->response->body(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
