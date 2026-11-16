<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница управления базы знаний (PRL-560): статистика, комбинированный поиск
 * (текстовый + семантический), очистка и удаление записей.
 * Доступ — только роль admin. Действия — строго POST + CSRF.
 */
class Controller_Prl560_Knowledge extends Controller_Prl100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('База знаний — наработки воркеров');
    }

    public function action_index()
    {
        $q        = trim((string) $this->request->query('q'));
        $semantic = ! empty($_GET['semantic']);

        $result = ($q !== '')
            ? Service_Prl560_Knowledge::search($q, ['limit' => 20, 'semantic' => $semantic])
            : NULL;

        $this->template->content = View::factory('prl560/knowledge', [
            'stats'    => Service_Prl560_Knowledge::stats(),
            'recent'   => Service_Prl560_Knowledge::recent(30),
            'q'        => $q,
            'semantic' => $semantic,
            'result'   => $result,
            'semantic_available' => class_exists('Service_Prl115_Semantic'),
            'csrf'     => Security::token(),
        ]);
    }

    /** POST /prl560/delete/<id>. */
    public function action_delete()
    {
        $this->_post('delete', (int) $this->request->param('id'), 'Запись удалена');
    }


    /** POST /prl560/cleanup — удалить записи старше retention_days. */
    public function action_cleanup()
    {
        $this->_post('cleanup', NULL, 'Очистка выполнена');
    }

    protected function _post($method, $id, $ok_message)
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        Service_Prl560_Knowledge::{$method}($id);

        HTTP::redirect('/prl560?flash='.urlencode($ok_message), 303);
    }
}
