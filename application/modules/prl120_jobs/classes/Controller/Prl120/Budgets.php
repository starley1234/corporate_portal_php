<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /prl120/budgets — бюджеты токенов: умолчания по ролям, кто сегодня ближе к
 * потолку, индивидуальные лимиты (выдать/снять). Право prl120.usage.admin.
 */
class Controller_Prl120_Budgets extends Controller_Prl100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('prl120.usage.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }
    }

    public function action_index()
    {
        $flash = '';
        $key   = trim((string) $this->request->query('key'));

        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — повторите';
            }
            else
            {
                $key = trim((string) $this->request->post('key'));
                $res = Service_Prl120_Budget::save($key,
                    $this->request->post('daily'), $this->request->post('monthly'),
                    $this->request->post('note'), (int) $this->_current_user->id);

                HTTP::redirect('/prl120/budgets?key='.rawurlencode($key).'&flash='.urlencode(
                    $res['ok'] ? 'Бюджет сохранён: '.$key : $res['error']), 303);
            }
        }

        if ($flash === '')
        {
            $flash = (string) $this->request->query('flash');
        }

        $this->set_title('Бюджеты токенов');

        $this->template->content = View::factory('120/budgets', [
            'config'    => Service_Prl120_Budget::config(),
            'current'   => $key !== '' ? Service_Prl120_Budget::state($key) : [],
            'override'  => $key !== '' ? Service_Prl120_Budget::override($key) : NULL,
            'key'       => $key,
            'overrides' => Service_Prl120_Budget::overrides(200),
            'top'       => Service_Prl120_Budget::top_today(20),
            'flash'     => $flash,
            'csrf'      => Security::token(),
        ]);
    }
}
