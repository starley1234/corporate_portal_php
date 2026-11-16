<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prl393_Api extends Controller_Prl39x_Base
{
    protected $auth_required = FALSE;
    protected $public_actions = ['health'];
    protected $_halt = FALSE;

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;

        $token = '';
        foreach (['PRL393_SITE_TOKEN', 'WORKER_SITE_TOKEN', 'WORKER_API_TOKEN'] as $key)
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

            return $this->_json(['ok' => FALSE, 'error' => 'PRL393_SITE_TOKEN не задан — API закрыт'], 403);
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

        return $this->_json(['ok' => TRUE, 'module' => 'prl393', 'stats' => Service_Prl393_Workflow::stats()]);
    }
}
