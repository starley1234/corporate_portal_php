<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Часть управления «Работа 2 Оффер» (NJ-550): последние поиски и управление.
 * Доступ — только роль admin. Действия — строго POST + CSRF.
 */
class Controller_Nj550_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Поиск вакансий — управление');
    }

    public function action_index()
    {
        $status = $this->request->query('status');
        $page   = max(1, (int) $this->request->query('page'));
        $q      = trim((string) $this->request->query('q'));
        $per    = 50;

        $this->template->content = View::factory('550/admin', [
            'rows'   => Service_Nj550_Jobs::admin_list($per, $status, ($page - 1) * $per, $q),
            'status' => in_array($status, ['new', 'done', 'error'], TRUE) ? $status : NULL,
            'page'   => $page,
            'q'      => $q,
            'csrf'   => Security::token(),
        ]);
    }

    /** POST /nj550/admin/requeue/<id> — перезапустить поиск. */
    public function action_requeue()
    {
        $this->_post_action('requeue', 'Поиск перезапущен');
    }

    /** POST /nj550/admin/delete/<id> — удалить поиск и результаты. */
    public function action_delete()
    {
        $this->_post_action('delete', 'Поиск удалён');
    }

    protected function _post_action($method, $ok_message)
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        $result = Service_Nj550_Jobs::{'admin_'.$method}((int) $this->request->param('id'));

        $flash = isset($result['error'])
            ? '?flash='.urlencode($result['error'])
            : '?flash='.urlencode($ok_message);

        HTTP::redirect('/nj550/admin'.$flash, 303);
    }
}
