<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Подписи и реквизиты PDF» (nj636_pdfsign): /pdf-sign
 */
class Controller_Nj636_Pdfsign extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Подписи и реквизиты PDF: должность, ФИО, дата — онлайн');
        $this->set_description(
            'Подготовьте лист подписи в PDF: добавьте должность, ФИО, дату и другие реквизиты поверх документа, посчитайте существующие подписи, очистите визуальные аннотации. Без установки программ.');
        $this->set_keywords('подпись пдф реквизиты документа должность фио дата лист подписи печать виза онлайн');

    }

    public function action_index()
    {
        $recent = class_exists('Service_Nj636_Pdfsign')
            ? Service_Nj636_Pdfsign::recent_runs(6)
            : [];

        $this->template->content = View::factory('636/index', [
            'recent'    => $recent,
            'rate_text' => class_exists('Service_Nj636_Pdfsign')
                ? Service_Nj636_Pdfsign::rate_limit_text()
                : '5 операций в минуту',
        ]);
    }
}
