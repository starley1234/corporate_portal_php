<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj601: оплата — /billing/pay (POST, редирект в Robokassa), оповещение
 * /billing/result (Result URL, ответ «OK$InvId» plain-text), возвраты
 * /billing/success и /billing/fail.
 */
class Controller_Nj601_Billing extends Controller {

    public function before()
    {
        parent::before();
        $this->auto_render = FALSE;
    }

    /** POST /billing/pay — создать счёт и увести плательщика в Robokassa. */
    public function action_pay()
    {
        if ($this->request->method() !== Request::POST)
        {
            HTTP::redirect('/', 302);
        }

        $back = '/';

        try
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                throw new Exception('Сессия устарела — попробуйте ещё раз');
            }

            $amount_kop  = (int) round(((float) str_replace(',', '.', (string) $this->request->post('amount'))) * 100);
            $description = (string) $this->request->post('description');
            $service     = (string) $this->request->post('service');
            $email       = (string) $this->request->post('email');

            $res = Service_Nj601_Billing::create_invoice($amount_kop, $description, $service, $email);

            if (isset($res['error']))
            {
                throw new Exception($res['error']);
            }

            HTTP::redirect($res['pay_url'], 302);
        }
        catch (Throwable $e)
        {
            Session::instance()->set('nj601_flash', $e->getMessage());
            HTTP::redirect($back, 302);
        }
    }

    /** GET/POST /billing/result — оповещение Robokassa. Ответ: «OK$InvId». */
    public function action_result()
    {
        $out = (string) $this->request->post('OutSum') ?: (string) $this->request->query('OutSum');
        $inv = (string) $this->request->post('InvId') ?: (string) $this->request->query('InvId');
        $sig = (string) $this->request->post('SignatureValue') ?: (string) $this->request->query('SignatureValue');

        $raw = [
            'out_sum' => $out,
            'fee'     => (string) $this->request->post('Fee') ?: (string) $this->request->query('Fee'),
            'email'   => (string) $this->request->post('EMail') ?: (string) $this->request->query('EMail'),
            'method'  => $this->request->method(),
        ];

        $res = Service_Nj601_Billing::process_result($out, $inv, $sig, $raw);

        $this->response->headers('Content-Type', 'text/plain; charset=utf-8');
        $this->response->body(isset($res['error']) ? "bad sign\n" : "OK{$inv}\n");
    }

    /** GET /billing/success — возврат плательщика (подпись Password1). */
    public function action_success()
    {
        $out = (string) $this->request->query('OutSum');
        $inv = (int) $this->request->query('InvId');
        $sig = (string) $this->request->query('SignatureValue');

        $ok    = Service_Nj601_Billing::verify_success($out, $inv, $sig);
        $invio = $ok ? Service_Nj601_Billing::invoice($inv) : NULL;

        $view = View::factory('601/finish', [
            'ok'        => $ok,
            'title'     => 'Платёж принят',
            'inv'       => $invio,
            'icon'      => 'fa-circle-check',
            'color'     => 'teal',
            'text'      => $ok
                ? 'Платёж подтверждён. Спасибо! Если что-то не активировалось автоматически — напишите нам, счёт №'.$inv.' найдётся мгновенно.'
                : 'Не удалось подтвердить подпись возврата. Не переживайте: оплату фиксирует серверное оповещение (Result URL) — статус счёта обновится автоматически.',
            'is_test'   => Service_Nj601_Billing::is_test(),
        ]);

        $this->response->body($view);
    }

    /** GET /billing/fail — плательщик отказался/не оплатил. */
    public function action_fail()
    {
        $inv = (int) $this->request->query('InvId');

        $view = View::factory('601/finish', [
            'ok'    => FALSE,
            'title' => 'Платёж не завершён',
            'inv'   => $inv ? Service_Nj601_Billing::invoice($inv) : NULL,
            'icon'  => 'fa-circle-xmark',
            'color' => 'gray',
            'text'  => 'Оплата не проведена (её можно просто повторить). Если счёт был создан — №'.$inv.', деньги с него не списаны.',
            'is_test' => Service_Nj601_Billing::is_test(),
        ]);

        $this->response->body($view);
    }
}
