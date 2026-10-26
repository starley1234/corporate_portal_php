<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «CSV ↔ JSON» (модуль NJ-632): /csv
 */
class Controller_Nj632_Csv extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Конвертер CSV в JSON и обратно онлайн');
        $this->set_description(
            'Превратить CSV-таблицу в JSON (и обратно) с корректным экранированием кавычек и выбором разделителя. Без регистрации.');
        $this->set_keywords('csv json конвертер таблица разделитель запятая точка с запятой онлайн массив');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $mode  = isset($query['m']) && $query['m'] === 'json2csv' ? 'json2csv' : 'csv2json';

        $this->template->content = View::factory('632/index', [
            'mode'        => $mode,
            'delimiters'  => Service_Nj632_Csv::delimiters(),
            'delimiter'   => isset($query['d']) ? (string) $query['d'] : ',',
        ]);
    }
}
