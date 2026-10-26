<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление «Профиля компании»: профили всех пользователей.
 */
class Controller_Nj591_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Профиль · управление — профили компаний');

        $this->set_module_nav([]); // чипы раздела даёт header_nav (дубли убраны)

    }

    public function action_index()
    {
        $this->_render_admin();
    }

    /** POST /nj591/admin/delete/<id> — удалить профиль (mode=soft|restore). */
    public function action_delete()
    {
        $mode = (string) $this->request->post('mode');
        $id   = (int) $this->request->param('id');

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            $this->_render_admin('Сессия устарела — действие не выполнено');

            return;
        }

        if ($mode === 'restore')
        {
            Database::instance()->query(Database::UPDATE,
                'UPDATE nj_591_profiles SET deleted_at = NULL, updated_at = now() WHERE id = '.$id);
            $this->_render_admin('Профиль №'.$id.' восстановлен');

            return;
        }

        Service_Nj591_Company::remove($id);
        $this->_render_admin('Профиль №'.$id.' удалён');
    }

    protected function _render_admin($flash = '')
    {
        $this->template->content = View::factory('591/admin', [
            'profiles' => Service_Nj591_Company::list_all(),
            'csrf'     => Security::token(),
            'flash'    => (string) $flash,
        ]);
    }
}
