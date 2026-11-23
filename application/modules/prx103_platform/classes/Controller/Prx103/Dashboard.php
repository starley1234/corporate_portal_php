<?php defined('SYSPATH') or die('No direct script access.');
/** Технический кабинет аккаунта. HR-профиль сотрудника — будущий prx300. */
class Controller_Prx103_Dashboard extends Controller_Base {
    public function action_index()
    {
        $this->set_title('Мой аккаунт · PRX');
        $this->template->content = View::factory('prx103/dashboard', [
            'me' => $this->_current_user,
            'is_admin' => $this->can('admin.access'),
        ]);
    }
}
