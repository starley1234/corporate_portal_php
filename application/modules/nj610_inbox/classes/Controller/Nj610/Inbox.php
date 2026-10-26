<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj610_inbox: /inbox — лента входящих с черновиками (владелец/управление).
 */
class Controller_Nj610_Inbox extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для владельца');
        }

        $this->set_title('Входящие под контролем');
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST
            AND Security::check((string) $this->request->post('csrf')))
        {
            $flash = Service_Nj610_Inbox::set_status((int) $this->request->post('id'),
                (string) $this->request->post('st')) ? 'Готово' : 'Не получилось';
        }

        // Живой статус-ряд почтового прогона (эталон /trends): видно, что воркер
        // действительно читает ящик, а после завершения остаётся плашка итога —
        // вместо тишины («по прежнему нет готово»).
        $live = Service_Nj610_Inbox::live_now();
        $last = NULL;

        if ($live AND ! in_array((string) $live['status'], ['queued', 'running'], TRUE))
        {
            $last = Service_Nj610_Inbox::last_run();
            $live = NULL;
        }

        $this->template->content = View::factory('610/index', [
            'feed'  => Service_Nj610_Inbox::feed(),
            'stats' => Service_Nj610_Inbox::stats(),
            'live'  => $live,
            'last'  => $last,
            'flash' => $flash,
            'csrf'  => Security::token(),
            'configured' => Service_Nj610_Inbox::configured(),
        ]);
    }
}
