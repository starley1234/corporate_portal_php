<?php defined('SYSPATH') or die('No direct script access.');

/** Публичная страница проверки ИНН: /inn. */
class Controller_Nj517_Inn extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Проверить ИНН онлайн — контрольные цифры и сведения ФНС');
        $this->set_description('Проверьте формат и контрольные цифры ИНН прямо в браузере. Для 10-значного ИНН организации можно отдельно запросить открытые сведения ФНС; номер не попадает в адрес страницы.');
        $this->set_keywords('проверить ИНН онлайн контрольная цифра проверить ИНН организации физлица ФНС');
    }

    public function action_index()
    {
        $this->template->content = View::factory('517/index', [
            'csrf' => Security::token(),
        ]);
    }
}
