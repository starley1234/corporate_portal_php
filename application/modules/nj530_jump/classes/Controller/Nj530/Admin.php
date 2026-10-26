<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Часть управления «Need 2 Jump» (NJ-530): ссылки, переходы, идентификаторы.
 * Доступ — только роль admin. Действия — строго POST + CSRF.
 */
class Controller_Nj530_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Короткие ссылки — управление');
    }

    public function action_index()
    {
        $q = trim((string) $this->request->query('q'));

        $this->template->content = View::factory('530/admin', [
            'links'  => Service_Nj530_Jump::admin_list(50, $q),
            'clicks' => Service_Nj530_Jump::admin_recent_clicks(20),
            'q'      => $q,
            'csrf'   => Security::token(),
        ]);
    }

    /** POST /nj530/admin/delete/<id> — удалить ссылку. */
    public function action_delete()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        Service_Nj530_Jump::admin_delete((int) $this->request->param('id'));

        HTTP::redirect('/nj530/admin?flash='.urlencode('Ссылка удалена'), 303);
    }
}
