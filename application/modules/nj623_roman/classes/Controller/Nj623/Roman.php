<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Римские числа» (модуль NJ-623): /roman
 */
class Controller_Nj623_Roman extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Римские числа онлайн — перевод арабских в римские и обратно');
        $this->set_description(
            'Перевести число в римскую запись или прочитать римскую цифру: онлайн-конвертер с примерами. Для дат, оглавлений, титульных листов — без регистрации.');
        $this->set_keywords('римские числа онлайн конвертер перевод арабские римские цифры');

    }

    public function action_index()
    {
        $query  = $this->request->query();
        $sample = isset($query['v']) ? (string) $query['v'] : '';

        $this->template->content = View::factory('623/index', [
            'sample'  => $sample,
            'samples' => Service_Nj623_Roman::convert($sample),
        ]);
    }
}
