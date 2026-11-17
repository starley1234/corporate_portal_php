<?php defined('SYSPATH') or die('No direct script access.');

/**
 * API prx392 для воркера `doc.analyze`.
 *
 *   GET  /prx392/api/input/<run_id>   — задание для модели
 *   POST /prx392/api/result/<run_id>  — {result: {...}, tokens, duration_ms}
 */
class Controller_Prx392_Api extends Controller_Prx39x_Base
{
    protected $auth_required = FALSE;
    protected $public_actions = ['health', 'input', 'result'];
    protected $_halt = FALSE;

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;

        $token = '';
        foreach (['PRX392_SITE_TOKEN', 'WORKER_SITE_TOKEN', 'WORKER_API_TOKEN'] as $key)
        {
            $token = trim((string) getenv($key));
            if ($token !== '')
            {
                break;
            }
        }

        if ($token === '')
        {
            if (Kohana::$environment === Kohana::DEVELOPMENT)
            {
                return;
            }
            $this->_halt = TRUE;

            return $this->_json(['ok' => FALSE, 'error' => 'PRX392_SITE_TOKEN не задан — API закрыт'], 403);
        }

        $given = (string) $this->request->headers('x-worker-token');
        if ( ! hash_equals($token, $given))
        {
            $this->_halt = TRUE;

            return $this->_json(['ok' => FALSE, 'error' => 'Недействительный токен воркера'], 403);
        }
    }

    public function action_health()
    {
        if ($this->_halt) return;

        return $this->_json(['ok' => TRUE, 'module' => 'prx392', 'stats' => Service_Prx392_Analysis::stats()]);
    }

    public function action_input()
    {
        if ($this->_halt) return;

        try
        {
            $input = Service_Prx392_Analysis::input_for_worker((int) $this->request->param('id'));

            return $this->_json(['ok' => TRUE] + $input);
        }
        catch (Exception $e)
        {
            return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 400);
        }
    }

    public function action_result()
    {
        if ($this->_halt) return;

        if ($this->request->method() !== Request::POST)
        {
            return $this->_json(['ok' => FALSE, 'error' => 'Только POST'], 405);
        }

        $raw = $this->request->body();
        $body = $raw ? json_decode($raw, TRUE) : NULL;
        if ( ! is_array($body))
        {
            $body = (array) $this->request->post();
        }

        try
        {
            $suggestion = Service_Prx392_Analysis::store_result((int) $this->request->param('id'), $body);

            return $this->_json(['ok' => TRUE, 'suggestion_id' => $suggestion['id'] ?? NULL]);
        }
        catch (Exception $e)
        {
            return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 422);
        }
    }
}
