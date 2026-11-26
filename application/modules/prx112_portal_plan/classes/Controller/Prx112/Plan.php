<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Prx112_Plan extends Controller_Base
{
    public function before()
    {
        $action = $this->request->action();
        if ($action === 'approve' && $this->request->method() !== Request::POST)
        {
            throw (HTTP_Exception::factory(405))->allowed('POST');
        }
        if ($action === 'index' && ! Auth::instance()->logged_in())
        {
            HTTP::redirect('/auth/login?return_url=%2Fportal-plan');
        }
        parent::before();
        $this->actor()->require('portal.access');
        $this->response->headers('Cache-Control', 'no-store');
    }

    public function action_index()
    {
        $state = self::filter_state($this->request->query('state'));
        $domains = Service_Prx112_Plan::domains($this->actor());
        $counts = Service_Prx112_Plan::counts($domains);

        $this->set_title('План и архив');
        $this->template->content = View::factory('prx112/index', [
            'domains' => Service_Prx112_Plan::filter($domains, $state),
            'counts' => $counts,
            'state' => $state,
            'publication' => Session::instance()->get_once('prx112_publication'),
        ]);
    }

    public function action_approve()
    {
        $actor = $this->actor();
        $actor->require('prx112.approve');
        $name = $this->request->post('module');
        if ( ! is_string($name) || ! preg_match('/\Aprx[0-9]{3}_[a-z0-9_]+\z/', $name))
        {
            throw HTTP_Exception::factory(400, 'Некорректный модуль');
        }
        $state = $this->request->post('state');
        if ( ! in_array($state, Service_Prx110_Publication::STATES, TRUE))
        {
            throw HTTP_Exception::factory(400, 'Некорректное состояние публикации');
        }
        $note = $this->request->post('note') ?? '';
        if ( ! is_string($note) || strlen($note) > 4000 || strpos($note, "\0") !== FALSE)
        {
            throw HTTP_Exception::factory(400, 'Некорректный комментарий');
        }
        Service_Prx110_Publication::set_state($name, $state, $actor, $note);
        Session::instance()->set('prx112_publication', $state);
        $return_state = self::filter_state($this->request->post('return_state'));
        HTTP::redirect('/portal-plan'.($return_state === 'all' ? '' : '?state='.$return_state), 303);
    }

    private static function filter_state(mixed $state): string
    {
        return in_array($state, Service_Prx112_Plan::STATES, TRUE) ? $state : 'all';
    }
}
