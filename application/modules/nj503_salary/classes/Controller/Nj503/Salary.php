<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Калькулятор зарплаты» (модуль NJ-503): /salary
 *
 * Состояние — в адресе (ссылкой можно поделиться):
 *   ?m=gross|net&s=100000&y=2026&c=2&d=0&single=1&nr=1&sme=1&i=0.2
 * Первый расчёт делает сервер (результат сразу в HTML/JSON страницы),
 * дальше пересчитывает JS через API.
 */
class Controller_Nj503_Salary extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Калькулятор зарплаты: на руки и до вычета налога (НДФЛ 13–22 %) онлайн');
        $this->set_description(
            'Зарплата на руки из оклада и оклад из суммы на руки: прогрессивный НДФЛ 13–22 %, вычеты на детей, страховые взносы работодателя и полная стоимость сотрудника. Помесячная таблица года.');
        $this->set_keywords('калькулятор зарплаты на руки ндфл 2026 оклад до вычета налогов вычет на детей страховые взносы работодателя прогрессивная шкала');
    }

    public function action_index()
    {
        $q = $this->request->query();
        $s = function ($key, $default = '') use ($q) {
            return isset($q[$key]) && is_scalar($q[$key]) ? mb_substr((string) $q[$key], 0, 16) : $default;
        };

        $mode = $s('m') === 'net' ? 'net' : 'gross';
        $year = (int) $s('y', (string) Service_Nj503_Salary::default_year());

        $form = [
            'mode'     => $mode,
            'amount'   => $s('s', '100000'),
            'year'     => isset(Service_Nj503_Salary::all_params()[$year]) ? $year : Service_Nj503_Salary::default_year(),
            'children' => max(0, min(10, (int) $s('c', '0'))),
            'disabled' => max(0, min(10, (int) $s('d', '0'))),
            'single'   => $s('single') === '1',
            'resident' => $s('nr') !== '1',
            'sme'      => $s('sme') === '1',
            'injury'   => $s('i', '0.2'),
        ];

        $init = Service_Nj503_Salary::calculate($form['amount'], $mode, [
            'year' => $form['year'], 'resident' => $form['resident'], 'children' => $form['children'],
            'disabled' => $form['disabled'], 'single' => $form['single'], 'sme' => $form['sme'], 'injury' => $form['injury'],
        ]);

        $this->template->content = View::factory('503/index', [
            'form'   => $form,
            'init'   => $init,
            'years'  => Service_Nj503_Salary::years(),
            'params' => Service_Nj503_Salary::all_params(),
        ]);
    }
}
