<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj601_billing: страница /billing — баланс кредитов, пакеты, история.
 * Работает и для анона (субъект g:<ubid>), и для пользователя (u:<id>).
 */
class Controller_Nj601_Balance extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Баланс кредитов — N2J.ru');
        $this->set_description('Кредиты — единая валюта AI-операций N2J.ru: профили компаний, письма, досье, карты отрасли. Пакеты от 500 ₽.');
    }

    public function action_home()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $flash = $this->_buy();
        }

        $subject = Service_Nj601_Billing::subject_me();
        $state   = Service_Nj601_Billing::state($subject);

        $this->template->content = View::factory('601/balance', [
            'state'    => $state,
            'packs'    => (array) Kohana::$config->load('nj601_billing.packs'),
            'costs'    => (array) Kohana::$config->load('nj601_billing.cost'),
            'history'  => Service_Nj601_Billing::history($subject, 15),
            'flash'    => $flash ?: (string) Session::instance()->get_once('nj601_flash'),
            'csrf'     => Security::token(),
            'test_mode' => Service_Nj601_Billing::is_test(),
        ]);
    }

    /** Покупка пакета: счёт + redirect в Robokassa (или flash с ошибкой). */
    protected function _buy()
    {
        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            return 'Сессия устарела — попробуйте ещё раз';
        }

        $res = Service_Nj601_Billing::buy_pack((string) $this->request->post('pack'));

        if (isset($res['error']))
        {
            return (string) $res['error'];
        }

        HTTP::redirect($res['pay_url'], 302);
        exit;
    }
}
