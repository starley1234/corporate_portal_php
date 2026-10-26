<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj601: админка /nj601/admin — счета, сводка, ручной тестовый счёт.
 * Доступ — право nj601.admin (config/acl.php).
 */
class Controller_Nj601_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj601.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }


        Service_Nj601_Billing::ensure_tables();
    }

    public function action_index()
    {
        $flash = Session::instance()->get_once('nj601_flash', '');

        if ($this->request->method() === Request::POST)
        {
            try
            {
                if ( ! Security::check((string) $this->request->post('csrf')))
                {
                    throw new Exception('Сессия устарела');
                }

                $amount_kop = (int) round(((float) str_replace(',', '.', (string) $this->request->post('amount'))) * 100);
                $res = Service_Nj601_Billing::create_invoice(
                    $amount_kop, (string) $this->request->post('description'), 'admin.test');

                if (isset($res['error']))
                {
                    throw new Exception($res['error']);
                }

                Session::instance()->set('nj601_flash', 'Счёт №'.$res['invoice_id'].' создан. Ссылка для оплаты: '.$res['pay_url']);
            }
            catch (Throwable $e)
            {
                Session::instance()->set('nj601_flash', 'Не получилось: '.$e->getMessage());
            }

            HTTP::redirect('/nj601/admin', 303);
        }

        $this->set_title('Счета Robokassa · управление');

        $this->template->content = View::factory('601/admin', [
            'invoices'   => Service_Nj601_Billing::latest(50),
            'stats'      => Service_Nj601_Billing::stats(),
            'configured' => Service_Nj601_Billing::configured(),
            'is_test'    => Service_Nj601_Billing::is_test(),
            'flash'      => $flash,
            'csrf'       => Security::token(),
        ]);
    }
}
