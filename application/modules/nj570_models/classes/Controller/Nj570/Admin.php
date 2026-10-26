<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление 3D-конструктора: модерация моделей и учёт ресурсов воркеров.
 * Действия — строго POST + CSRF.
 */
class Controller_Nj570_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('3D · управление — модерация и ресурсы');
    }

    public function action_index()
    {
        $this->template->content = View::factory('570/admin', [
            'pending' => Service_Nj570_Models::pending_list(50),
            'usage'   => Service_Nj570_Models::usage_summary(),
            'recent'  => Service_Nj570_Models::usage_recent(30),
            'csrf'    => Security::token(),
        ]);
    }

    /** POST /nj570/admin/moderate/<code> — approve|reject. */
    public function action_moderate()
    {
        if ($this->request->method() !== Request::POST OR ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела');
        }

        $approve = $this->request->post('decision') === 'approve';
        Service_Nj570_Models::moderate((string) $this->request->param('id'), $approve);

        HTTP::redirect('/nj570/admin?flash='.urlencode($approve ? 'Опубликовано' : 'Отклонено'), 303);
    }
}
