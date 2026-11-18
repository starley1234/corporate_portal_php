<?php defined('SYSPATH') or die('No direct script access.');

/** API prx393: health для воркера и мониторинга SLA. */
class Controller_Prx393_Api extends Controller_Prx39x_Api
{
    protected $public_actions = ['health'];

    protected function _token_prefix()
    {
        return 'PRX393';
    }

    public function action_health()
    {
        if ($this->_halt) return;

        return $this->_json(['ok' => TRUE, 'module' => 'prx393', 'stats' => Service_Prx393_Workflow::stats()]);
    }
}
