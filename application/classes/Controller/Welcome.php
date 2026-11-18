<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Welcome extends Controller_Base {
    public function before()
    {
        if (! Auth::instance()->logged_in())
        {
            $return = $this->request->action() === 'portal_plan' ? '/portal-plan' : '/';
            HTTP::redirect('/auth/login?return_url='.rawurlencode($return));
        }
        parent::before();
        $this->set_menu(Service_Prx110_Catalog::menu_items());
    }

    public function action_index()
    {
        $title = 'Сервисы-пайплайны предприятия';
        $this->set_title($title);
        $this->template->content = View::factory('welcome/index', [
            'title' => $title,
            'can_test' => $this->can('prx395.ui'),
            'docflow_enabled' => isset(Kohana::modules()['prx390_intake']) && $this->can('admin.access'),
            'phases' => (array) Kohana::$config->load('enterprise_plan')->get('phases'),
        ]);
    }

    public function action_portal_plan()
    {
        $title = 'Корпоративный портал: отложенный план и архив';
        $this->set_title($title);
        $this->template->content = View::factory('welcome/portal_plan', [
            'title' => $title,
            'domains' => (array) Kohana::$config->load('portal')->get('domains'),
        ]);
    }
}
