<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Отпуск» (модуль NJ-504): /vacation
 *
 * Четыре вкладки: выгодные даты (plan), даты отпуска (dates), отпускные (pay),
 * компенсация при увольнении (comp). Состояние — в адресе, расчёт — через API.
 */
class Controller_Nj504_Vacation extends Controller_Erp100_Base {

    protected static $_tabs = ['plan', 'dates', 'pay', 'comp'];

    public function before()
    {
        parent::before();

        $this->set_title('Калькулятор отпуска: отпускные, даты, компенсация и самые выгодные даты отпуска');
        $this->set_description(
            'Отпускные по ст. 139 ТК (29,3), дата окончания отпуска с учётом праздников, компенсация за неиспользованный отпуск при увольнении и подбор самых выгодных дат отпуска в году.');
        $this->set_keywords('калькулятор отпускных 29,3 компенсация за неиспользованный отпуск когда лучше взять отпуск 2026 праздники в отпуске продлевают');
    }

    public function action_index()
    {
        $q = $this->request->query();
        $s = function ($key, $default = '') use ($q) {
            return isset($q[$key]) && is_scalar($q[$key]) ? mb_substr((string) $q[$key], 0, 24) : $default;
        };

        $tab = in_array($s('m'), self::$_tabs, TRUE) ? $s('m') : 'plan';
        $known = Service_Nj508_Calendar::known_years();
        $year = (int) $s('y', (string) date('Y'));

        if ( ! in_array($year, $known, TRUE))
        {
            $year = $known ? (int) (in_array((int) date('Y'), $known, TRUE) ? date('Y') : max($known)) : (int) date('Y');
        }

        $this->template->content = View::factory('504/index', [
            'tab'   => $tab,
            'known' => $known,
            'today' => date('Y-m-d'),
            'q'     => [
                'year'    => $year,
                'days'    => $s('d', '14'),
                'start'   => $s('s'),
                'income'  => $s('a'),
                'months'  => $s('n', '12'),
                'rate'    => $s('r', '13'),
                'hire'    => $s('h'),
                'dismiss' => $s('e'),
                'annual'  => $s('an', '28'),
                'used'    => $s('u', '0'),
            ],
        ]);
    }
}
