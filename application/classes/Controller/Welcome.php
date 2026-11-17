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
        $this->set_title('PRX — корпоративный портал');
        $this->set_menu(Service_Prx110_Catalog::menu_items());
        $this->template->content = View::factory('welcome/index', [
            'domains' => (array) Kohana::$config->load('portal')->get('domains'),
        ]);
    }
}
