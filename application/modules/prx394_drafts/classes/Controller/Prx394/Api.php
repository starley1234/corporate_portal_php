<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx394_Api extends Controller_Prx39x_Base
{
    protected $auth_required = FALSE;
    protected $public_actions = ['health', 'input', 'result'];
    protected $_halt = FALSE;

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;

        $token = '';
        foreach (['PRX394_SITE_TOKEN', 'WORKER_SITE_TOKEN', 'WORKER_API_TOKEN'] as $key)
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

            return $this->_json(['ok' => FALSE, 'error' => 'PRX394_SITE_TOKEN не задан — API закрыт'], 403);
        }
        if ( ! hash_equals($token, (string) $this->request->headers('x-worker-token')))
        {
            $this->_halt = TRUE;

            return $this->_json(['ok' => FALSE, 'error' => 'Недействительный токен воркера'], 403);
        }
    }

    public function action_health()
    {
        if ($this->_halt) return;

        return $this->_json(['ok' => TRUE, 'module' => 'prx394', 'stats' => Service_Prx394_Drafts::stats()]);
    }

    public function action_input()
    {
        if ($this->_halt) return;

        try
        {
            return $this->_json(['ok' => TRUE] + Service_Prx394_Drafts::input_for_worker((int) $this->request->param('id')));
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
            $draft = Service_Prx394_Drafts::store_result((int) $this->request->param('id'), $body);

            return $this->_json(['ok' => TRUE, 'draft_id' => $draft['id'], 'status' => $draft['status']]);
        }
        catch (Exception $e)
        {
            return $this->_json(['ok' => FALSE, 'error' => $e->getMessage()], 422);
        }
    }
}
