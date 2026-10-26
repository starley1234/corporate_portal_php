<?php defined('SYSPATH') or die('No direct script access.');
/** Технический кабинет аккаунта. HR-профиль сотрудника — будущий prl300. */
class Controller_Prl100_Dashboard extends Controller_Base {
    public function action_index()
    {
        $this->set_title('Мой аккаунт · PRL');
        $this->template->content = View::factory('prl100/dashboard', [
            'me' => $this->_current_user,
            'is_admin' => $this->can('admin.access'),
        ]);
    }
}
