<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj160_mcp: хаб — подключение ИИ-агентов. Открыт всем: пользователь
 * выпускает персональные ключи, управление видит все токены.
 */
class Controller_Nj160_Hub extends Controller_Erp100_Base {

    protected $_user_id = NULL;

    public function before()
    {
        parent::before();

        $this->set_title('MCP-хаб — сервисы N2J как инструменты ИИ-агента');
        $this->set_description('Подключите N2J к Claude, Cursor или своему агенту: организации, профили компаний, письма, досье, закупки — персональный ключ и один эндпоинт.');

        $this->_user_id = $this->_current_user ? (int) $this->_current_user->id : NULL;
    }

    public function action_index()
    {
        $flash = '';

        // self-service: создание и отзыв своих ключей (POST)
        if ($this->request->method() === Request::POST AND $this->_user_id !== NULL)
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ((string) $this->request->post('act') === 'create')
            {
                $res = Service_Nj160_Tokens::create($this->_user_id, (string) $this->request->post('label'));
                Session::instance()->set('nj160_new_token', (string) ($res['token'] ?? ''));
            }
            elseif ((string) $this->request->post('act') === 'revoke')
            {
                Service_Nj160_Tokens::revoke((int) $this->request->post('id'), $this->_user_id);
            }
            elseif ((string) $this->request->post('act') === 'hub_allow' AND Auth::instance()->logged_in('admin'))
            {
                // категории из чекбоксов; пусто = без ограничений
                $cats = (array) $this->request->post('cats');
                Service_Nj160_HubConfig::set(Service_Nj160_HubConfig::K_ALLOWED, $cats === [] ? NULL : $cats);
            }
            elseif ((string) $this->request->post('act') === 'hub_reset' AND Auth::instance()->logged_in('admin'))
            {
                Service_Nj160_HubConfig::set(Service_Nj160_HubConfig::K_ALLOWED, NULL);
                Service_Nj160_HubConfig::set(Service_Nj160_HubConfig::K_DISABLED, NULL);
            }
            else
            {
                $flash = 'Неизвестное действие';
            }

            if ($flash === '')
            {
                HTTP::redirect('/mcp', 303);
            }
        }

        $this->template->content = View::factory('nj160/hub', [
            'flash'      => $flash,
            'me'         => $this->_user_id,
            'tokens'     => $this->_user_id ? Service_Nj160_Tokens::of_user($this->_user_id) : [],
            'all_tokens' => Auth::instance()->logged_in('admin') ? $this->_all_tokens() : [],
            'base_url'   => URL::base('https'),
            'tools'      => Service_Nj160_Registry::mcp_list(Auth::instance()->logged_in('admin')),
            'hub_conf'   => Auth::instance()->logged_in('admin') && class_exists('Service_Nj160_HubConfig')
                ? Service_Nj160_HubConfig::snapshot(TRUE) : NULL,
        ]);
    }

    /** Все токены платформы (только управление): без хэшей, статистика вызовов. */
    protected function _all_tokens()
    {
        try
        {
            return Database::instance()->query(Database::SELECT,
                'SELECT t.id, t.label, t.calls, t.last_call_at, t.created_at, t.revoked_at, u.email
                 FROM nj_160_tokens t JOIN users u ON u.id = t.user_id
                 ORDER BY t.id DESC LIMIT 100', FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }
}
