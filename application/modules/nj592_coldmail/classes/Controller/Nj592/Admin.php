<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Управление «Холодного письма»: прогоны всех пользователей.
 */
class Controller_Nj592_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Письмо · управление — прогоны');

        $this->set_module_nav([]); // чипы раздела даёт header_nav (дубли убраны)

    }

    public function action_index()
    {
        $this->_render_admin();
    }

    /** POST /nj592/admin/delete/<id> — удалить прогон (mode=soft|restore). */
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
                'UPDATE nj_592_letters SET deleted_at = NULL, updated_at = now() WHERE id = '.$id);
            $this->_render_admin('Прогон №'.$id.' восстановлен');

            return;
        }

        Service_Nj592_Coldmail::remove($id);
        $this->_render_admin('Прогон №'.$id.' удалён');
    }

    protected function _render_admin($flash = '')
    {
        $this->template->content = View::factory('592/admin', [
            'letters' => Service_Nj592_Coldmail::list_all(),
            'csrf'    => Security::token(),
            'flash'   => (string) $flash,
        ]);
    }
}
