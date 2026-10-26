<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Системы счисления» (модуль NJ-624): /base
 */
class Controller_Nj624_Base extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Системы счисления онлайн — 2, 8, 10, 16 и битовые операции');
        $this->set_description(
            'Перевести число между двоичной, восьмеричной, десятичной и шестнадцатеричной системами, а также посчитать побитовые И, ИЛИ, XOR и сдвиги. Без регистрации.');
        $this->set_keywords('системы счисления онлайн перевод двоичная шестнадцатеричная битовые операции and or xor сдвиг');

    }

    public function action_index()
    {
        $this->template->content = View::factory('624/index', [
            'bases' => Service_Nj624_Base::bases(),
        ]);
    }
}
