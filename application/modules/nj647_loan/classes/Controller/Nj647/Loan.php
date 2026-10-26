<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Кредит и ипотека» (модуль NJ-647): /loan
 *
 * Контроллер тонкий: прочитал параметры адреса → позвал Service_Nj647_Loan →
 * отдал вьюхе. Расчёт целиком серверный, поэтому страница работает без
 * JavaScript, а ссылка с параметрами открывает тот же график у коллеги.
 */
class Controller_Nj647_Loan extends Controller_Erp100_Base {

    /** Значения формы по умолчанию — обычная ипотека на сегодняшнем рынке. */
    protected static $_default = [
        'sum'        => '5 000 000',
        'rate'       => '18',
        'term'       => '20',
        'term_unit'  => 'years',
        'type'       => 'annuity',
        'extra'      => '',
        'extra_mode' => 'term',
    ];

    public function before()
    {
        parent::before();

        $this->set_title('Кредитный калькулятор: ипотека, график платежей и досрочное погашение');
        $this->set_description(
            'Рассчитайте ежемесячный платёж по кредиту или ипотеке: аннуитетный и дифференцированный график, переплата, досрочные погашения с сокращением срока или платежа. Без регистрации.');
        $this->set_keywords(
            'кредитный калькулятор ипотека график платежей аннуитетный дифференцированный досрочное погашение переплата');
    }

    public function action_index()
    {
        $query = (array) $this->request->query();

        $form = [];

        foreach (self::$_default as $key => $default)
        {
            $form[$key] = isset($query[$key]) ? trim((string) $query[$key]) : $default;
        }

        $form['start'] = isset($query['start']) ? trim((string) $query['start']) : date('Y-m-d');

        $this->template->content = View::factory('647/index', [
            'form'   => $form,
            'result' => Service_Nj647_Loan::calculate($form),
            'types'  => Service_Nj647_Loan::types(),
            'modes'  => Service_Nj647_Loan::extra_modes(),
            // Полный график длинный: по умолчанию раскрыт только текущий год
            'full'   => ! empty($query['full']),
        ]);
    }
}
