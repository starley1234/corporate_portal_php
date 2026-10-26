<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Калькулятор пеней и неустойки» (модуль NJ-505): /penalty
 *
 * Состояние — в адресе:
 *   ?m=tax|gk395|contract&a=100000&f=2026-08-01&t=2026-09-15&p=person&i=0&mor=0&r=0.1&c=10
 * Расчёт считает API (Service_Nj505_Penalty — единственная истина), JS рисует ответ.
 */
class Controller_Nj505_Penalty extends Controller_Erp100_Base {

    protected static $_modes = ['tax', 'gk395', 'contract'];

    public function before()
    {
        parent::before();

        $this->set_title('Калькулятор пеней: налоги (1/300, 1/150), проценты по ст. 395 ГК, неустойка по договору');
        $this->set_description(
            'Пени по налогам по ст. 75 НК РФ, проценты за пользование чужими деньгами по ст. 395 ГК РФ по ключевой ставке ЦБ и договорная неустойка. Расчёт по дням с таблицей периодов и мораторием 2022 года.');
        $this->set_keywords('калькулятор пеней 1/300 1/150 ключевая ставка проценты по 395 гк неустойка по договору расчёт пени по налогам мораторий 497');
    }

    public function action_index()
    {
        $q = $this->request->query();
        $s = function ($key, $default = '') use ($q) {
            return isset($q[$key]) && is_scalar($q[$key]) ? mb_substr((string) $q[$key], 0, 24) : $default;
        };

        $mode = in_array($s('m'), self::$_modes, TRUE) ? $s('m') : 'tax';

        $this->template->content = View::factory('505/index', [
            'form' => [
                'mode'       => $mode,
                'amount'     => $s('a'),
                'due'        => $s('f'),
                'paid'       => $s('t'),
                'payer'      => $s('p') === 'person' ? 'person' : 'org',
                'incl'       => $s('i') !== '0',
                'moratorium' => $s('mor') !== '0',
                'rate'       => $s('r', '0.1'),
                'cap'        => $s('c'),
            ],
            'last_rate'   => Service_Nj505_Penalty::last_rate(),
            'rates_table' => Service_Nj505_Penalty::rates_table(14),
            'rates_as_of' => Kohana::$config->load('nj505_penalty')->get('as_of'),
        ]);
    }
}
