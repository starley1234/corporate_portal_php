<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сокращатель ссылок (NJ-530): /jump — форма и статистика, POST — создать.
 */
class Controller_Nj530_Jump extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Сократить ссылку онлайн — Need 2 Jump');
        $this->set_description(
            'Бесплатный сокращатель ссылок: длинный URL превращается в короткий /x/код, счётчик переходов и статистика. Создание — по аккаунту, переходы открыты всем.');

    }
    public function action_index()
    {
        $created = NULL;
        $error = NULL;
        $guest = ! Auth::instance()->logged_in();

        if ($this->request->method() === Request::POST)
        {
            if ($guest)
            {
                // создание — только для зарегистрированных (решение владельца
                // 06.10.2026: анонимный сокращатель — спам-вектор на домене);
                // после входа login_redirect вернёт сюда
                HTTP::redirect('/auth/login', 302);
            }

            if ( ! Security::check(Arr::get($_POST, 'csrf', '')))
            {
                throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
            }

            $user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
            $result = Service_Nj530_Jump::create(Arr::get($_POST, 'url', ''), $user_id, Service_Nj530_Jump::client_ip());

            if ($result === NULL)
            {
                $error = 'Нужна корректная ссылка (http:// или https://…, либо путь этого сайта вида /vat).';
            }
            else
            {
                $created = $result;
            }
        }

        // ?c=<code> — показать карточку статистики по коду
        if ($created === NULL AND $code = Arr::get($_GET, 'c'))
        {
            $row = Service_Nj530_Jump::resolve($code);

            if ($row)
            {
                $created = ['code' => $row['code'], 'url' => $row['url'], 'existing' => TRUE, 'row' => $row];
            }
        }

        $my = $this->_current_user
            ? Service_Nj530_Jump::my((int) $this->_current_user->id)
            : [];

        $this->template->content = View::factory('530/index', [
            'created'   => $created,
            'error'     => $error,
            'my'        => $my,
            'guest'     => $guest,
            'csrf'      => Security::token(),
        ]);
    }

    /**
     * GET /x/<code>/qr — страница ссылки: короткий адрес, цель, переходы и QR-код.
     * Не увеличивает счётчик переходов (это не клик по ссылке).
     */
    public function action_qr()
    {
        $row = Service_Nj530_Jump::resolve($this->request->param('code'));

        if ($row === NULL)
        {
            throw HTTP_Exception::factory(404, 'Короткая ссылка не найдена');
        }

        $this->set_title('Ссылка /x/'.$row['code'].' — QR-код — N2J.ru');

        $this->template->content = View::factory('530/qr', [
            'link'      => $row,
            'short_url' => URL::base(TRUE).'x/'.$row['code'],
        ]);
    }
}
