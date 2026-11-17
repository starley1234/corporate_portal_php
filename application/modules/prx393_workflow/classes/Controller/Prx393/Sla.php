<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx393_Sla extends Controller_Prx39x_Base
{
    public function action_index()
    {
        $this->_require('prx393.view');
        $this->set_title('Сроки и маршруты');
        $this->set_description('Правила маршрутизации, производственный календарь и эскалации');
        $this->_nav('/sla');

        try
        {
            Service_Prx393_Workflow::maybe_escalate();
        }
        catch (Exception $e)
        {
            Log::instance()->add(Log::WARNING, 'prx393: эскалации не проверены: :e', [':e' => $e->getMessage()]);
        }

        $this->template->content = View::factory('393/index', [
            'flash' => $this->_flash(),
            'csrf' => $this->_csrf(),
            'stats' => Service_Prx393_Workflow::stats(),
            'deadlines' => Service_Prx393_Workflow::deadlines(60, TRUE),
            'rules' => Service_Prx393_Workflow::rules(FALSE),
            'escalations' => Service_Prx393_Workflow::escalations(20),
            'can_manage' => $this->can('prx393.manage'),
        ]);
    }

    public function action_route()
    {
        $this->_require('prx393.manage');
        $document_id = (int) $this->request->param('id');

        $this->_post(function () use ($document_id) {
            $deadline = Service_Prx393_Workflow::route($document_id, $this->_actor());

            return $deadline
                ? 'Маршрут: '.$deadline['department'].', срок '.date('d.m.Y H:i', strtotime($deadline['due_at']))
                : 'Маршрут пересчитан';
        }, '/documents/'.$document_id);
    }

    public function action_recalc()
    {
        $this->_require('prx393.manage');

        $this->_post(function () {
            $n = Service_Prx393_Workflow::recalculate_all(200);

            return 'Пересчитано документов: '.$n;
        }, '/sla');
    }
}
