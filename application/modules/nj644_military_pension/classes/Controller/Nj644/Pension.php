<?php defined('SYSPATH') or die('No direct script access.');

/** Страница справочного калькулятора военной пенсии за выслугу лет. */
class Controller_Nj644_Pension extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Калькулятор военной пенсии за выслугу лет — обычный и смешанный стаж');
        $this->set_description('Оцените военную пенсию за выслугу лет по статьям 13, 14 и 43 Закона РФ №4468-1: оклады, учитываемые надбавки, понижающая доля 2026 года, увеличения и смешанный стаж.');
        $this->set_keywords('калькулятор военной пенсии 2026 по выслуге лет смешанный стаж 20 лет 25 лет 93,59% денежное довольствие надбавки районный коэффициент');
    }

    public function action_index()
    {
        $this->template->content = View::factory('644/index', [
            'settings' => Service_Nj644_Pension::settings(),
            'today' => date('Y-m-d'),
        ]);
    }
}
