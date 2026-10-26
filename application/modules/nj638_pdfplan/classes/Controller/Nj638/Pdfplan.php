<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Конструктор А4» (nj638_pdfplan): /pdf-plan
 */
class Controller_Nj638_Pdfplan extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Конструктор А4: собрать документ онлайн, шапка, 2 колонки, нумерация');
        $this->set_description(
            'Сгенерируйте аккуратный документ А4 онлайн: шапка и дата, одна или две колонки, подвал с нумерацией страниц. Кириллица встроена корректно, без установки программ.');
        $this->set_keywords('документ а4 конструктор генератор одна колонка две колонки нумерация страниц подвал шапка онлайн');

    }

    public function action_index()
    {
        $recent = class_exists('Service_Nj638_Pdfplan')
            ? Service_Nj638_Pdfplan::recent_runs(6)
            : [];

        $this->template->content = View::factory('638/index', [
            'recent'    => $recent,
            'rate_text' => class_exists('Service_Nj638_Pdfplan')
                ? Service_Nj638_Pdfplan::rate_limit_text()
                : '5 операций в минуту',
        ]);
    }
}
