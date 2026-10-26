<?php defined('SYSPATH') or die('No direct script access.');

/** Публичная страница /qr. Контроллер не принимает содержимое кодов и файлы. */
class Controller_Nj510_Qr extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Генератор QR-кодов — с логотипом, массово, PNG и SVG');
        $this->set_description('Бесплатный генератор QR-кодов: ссылки, Wi-Fi, визитки и текст. Свои цвета, формы и логотип по центру. До 200 кодов в ZIP-архиве. Всё в браузере, без регистрации.');
    }

    public function action_index()
    {
        $state = Service_Nj510_Qr::initial_state(
            $this->request->query('mode'), $this->request->query('type'));

        $this->template->content = View::factory('510/index', [
            'settings' => Service_Nj510_Qr::settings(),
            'state'    => $state,
        ]);
    }
}
