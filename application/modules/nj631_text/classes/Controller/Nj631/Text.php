<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Текст-инструменты» (модуль NJ-631): /text
 */
class Controller_Nj631_Text extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Инструменты для текста онлайн: регистр, статистика, дубликаты строк');
        $this->set_description(
            'Посчитать символы и слова, сменить регистр, убрать дубликаты и отсортировать строки — быстрые операции с текстом без регистрации.');
        $this->set_keywords('инструменты текста онлайн регистр статистика символы слова счётчик дубликаты сортировка строк');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $tool  = isset($query['t']) ? (string) $query['t'] : 'stats';

        $this->template->content = View::factory('631/index', [
            'tool'  => $tool,
            'tools' => Service_Nj631_Text::tools(),
            'text'  => isset($query['q']) ? (string) $query['q'] : '',
        ]);
    }
}
