<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница управления опросов (NJ-520): список опросов с голосами,
 * подробности по опросу (варианты, кто голосовал), закрытие и удаление.
 *
 * Доступ — роль admin, действия — строго POST + CSRF, ответ на действие —
 * редирект 303 (GET ничего не меняет). Сообщение едет через СЕССИЮ, а не
 * через `?flash=`: иначе любой желающий открыл бы админку со своей надписью.
 * Подробности опроса — адресом `/nj520/admin/poll/<code>`, без GET-параметров.
 */
class Controller_Nj520_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Опросы · управление — N2J.ru');
        $this->set_module_nav([
            ['title' => 'Быстрый опрос', 'href' => '/poll', 'active_paths' => ['/p'], 'icon' => 'fas fa-chart-simple'],
            ['title' => 'Управление опросами', 'href' => '/nj520/admin', 'icon' => 'fas fa-shield-halved', 'roles' => ['admin']],
        ]);
    }

    /** GET /nj520/admin — список опросов. */
    public function action_index()
    {
        $this->_render(NULL);
    }

    /** GET /nj520/admin/poll/<code> — список + подробности одного опроса. */
    public function action_poll()
    {
        $code = trim((string) $this->request->param('id'));

        $this->_render($code !== '' ? Service_Nj520_Poll::admin_poll($code) : NULL);
    }

    /** POST /nj520/admin/delete/<id>. */
    public function action_delete()
    {
        $this->_guard_post();

        Service_Nj520_Poll::admin_delete((int) $this->request->param('id'));

        $this->_flash('Опрос удалён');

        HTTP::redirect('/nj520/admin', 303);
    }

    /**
     * POST /nj520/admin/close/<id> — закрыть опрос (голосование прекращается);
     * с полем open=1 — открыть снова. Результаты остаются доступны.
     */
    public function action_close()
    {
        $this->_guard_post();

        $reopen = Arr::get($_POST, 'open', '') === '1';

        Service_Nj520_Poll::admin_set_closed((int) $this->request->param('id'), ! $reopen);

        $this->_flash($reopen ? 'Опрос снова открыт' : 'Опрос закрыт — голосование прекращено');

        HTTP::redirect('/nj520/admin', 303);
    }

    /** Общая отрисовка страницы (список всегда, подробности — когда выбран опрос). */
    protected function _render($detail)
    {
        $this->template->content = View::factory('520/admin', [
            'polls'  => Service_Nj520_Poll::admin_list(50),
            'detail' => $detail,
            'flash'  => (string) Session::instance()->get_once('nj520_admin_flash', ''),
            'csrf'   => Security::token(),
        ]);
    }

    /** Действие меняет данные: только POST и только со свежим токеном. */
    protected function _guard_post()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }

    protected function _flash($message)
    {
        Session::instance()->set('nj520_admin_flash', (string) $message);
    }
}
