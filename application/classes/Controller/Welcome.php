<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Welcome extends Controller_Base {
    public function before()
    {
        if (! Auth::instance()->logged_in())
        {
            HTTP::redirect('/auth/login?return_url=%2F');
        }
        parent::before();
    }

    public function action_index()
    {
        $this->set_title('PRX — платформа ИИ-сервисов');
        $this->set_menu(Service_Prx110_Catalog::menu_items());
        $this->template->content = View::factory('welcome/index', [
            'can_test' => $this->can('prx395.ui'),
            'docflow_enabled' => isset(Kohana::modules()['prx390_intake']) && $this->can('admin.access'),
            'domains' => (array) Kohana::$config->load('portal')->get('domains'),
        ]);
    }
}
