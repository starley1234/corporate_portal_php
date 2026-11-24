<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx112_Plan extends Controller_Base
{
    public function before()
    {
        if ($this->request->action() === 'index' && ! Auth::instance()->logged_in())
        {
            HTTP::redirect('/auth/login?return_url=%2Fportal-plan');
        }
        parent::before();
        $this->actor()->require('portal.access');
        $this->response->headers('Cache-Control', 'no-store');
    }

    public function action_index()
    {
        $this->set_title('План и архив портала');
        $this->template->content = View::factory('prx112/index', [
            'domains' => Service_Prx112_Plan::domains($this->actor()),
            'publication' => Session::instance()->get_once('prx112_publication'),
        ]);
    }

    public function action_approve()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw (HTTP_Exception::factory(405))->allowed('POST');
        }
        $actor = $this->actor();
        $actor->require('prx112.approve');
        $name = $this->request->post('module');
        if ( ! is_string($name) || ! preg_match('/\Aprx[0-9]{3}_[a-z0-9_]+\z/', $name))
        {
            throw HTTP_Exception::factory(400, 'Некорректный модуль');
        }
        $state = $this->request->post('state') ?? 'approved';
        if ( ! is_string($state))
        {
            throw HTTP_Exception::factory(400, 'Некорректное состояние публикации');
        }
        Service_Prx110_Publication::set_state($name, $state, $actor);
        Session::instance()->set('prx112_publication', $state);
        HTTP::redirect('/portal-plan', 303);
    }
}
