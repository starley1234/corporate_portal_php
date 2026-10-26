<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Админка «Похожих картинок» (NJ-654): /similar/admin.
 *
 * Владельцу сайта нужны ответы на два вопроса: что люди кладут в банк и что
 * они в нём ищут. Поэтому страница — это два журнала рядом плюс кнопки
 * уборки: убрать отмеченное, убрать неудавшиеся элементы, почистить журнал
 * поисков.
 *
 * Доступ — только роль admin, изменения строго POST + CSRF, после действия
 * редирект 303 с сообщением.
 */
class Controller_Nj654_Admin extends Controller_Erp100_Base
{
    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $this->set_title('Похожие картинки · админ');
        // Порядок тот же, что в Controller_Nj654_Similar (решение владельца):
        // ряд чипов один на весь раздел и стоит как в шапке, так и в панели.
        $this->set_module_nav([
            ['title' => 'Похожие картинки', 'href' => '/similar', 'icon' => 'fas fa-magnifying-glass'],
            ['title' => 'Журнал', 'href' => '/similar/admin', 'icon' => 'fas fa-clock-rotate-left', 'roles' => ['admin']],
            ['title' => 'Банк картинок', 'href' => '/similar/collection', 'icon' => 'fas fa-images'],
        ]);
    }

    public function action_index()
    {
        $this->template->content = View::factory('654/admin', [
            'stats'   => Service_Nj654_Bank::stats(),
            'items'   => Service_Nj654_Bank::items([], 40),
            'queries' => Service_Nj654_Bank::queries(40),
            'failed'  => Service_Nj654_Bank::items(['status' => 'error'], 20),
            'flash'   => mb_substr((string) Session::instance()->get_once('nj654_flash', ''), 0, 300),
            'csrf'    => Security::token(),
        ]);
    }

    /** Убрать отмеченные элементы банка (админ может любые). */
    public function action_delete()
    {
        $this->_guard();

        $gone = Service_Nj654_Bank::delete((array) Arr::get($_POST, 'ids', []), '', TRUE);

        $this->_back($gone > 0 ? 'Убрано элементов: '.$gone.'.' : 'Ничего не отмечено.');
    }

    /** Убрать всё, что не посчиталось: это мусор, он только мешает. */
    public function action_drop_failed()
    {
        $this->_guard();

        $ids  = array_column(Service_Nj654_Bank::items(['status' => 'error'], 200), 'id');
        $gone = Service_Nj654_Bank::delete($ids, '', TRUE);

        $this->_back($gone > 0 ? 'Неудавшихся элементов убрано: '.$gone.'.' : 'Неудавшихся нет.');
    }

    /** Очистить журнал поисков. */
    public function action_clear_log()
    {
        $this->_guard();

        $gone = Service_Nj654_Bank::clear_queries();

        $this->_back('Журнал поисков очищен, записей было: '.$gone.'.');
    }

    protected function _guard()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }

        if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }
    }

    /** Сообщение едет в сессии: адресная строка не место для текста. */
    protected function _back($message)
    {
        Session::instance()->set('nj654_flash', (string) $message);

        HTTP::redirect('/similar/admin', 303);
    }
}
