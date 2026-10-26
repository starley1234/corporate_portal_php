<?php defined('SYSPATH') or die('No direct script access.');

/** Страница конвертера единиц и бытовых мер: /units. */
class Controller_Nj506_Units extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Конвертер единиц онлайн: метры, килограммы, сотки, пуды и другие меры');
        $this->set_description('Перевод единиц длины, массы, объёма, площади, скорости, температуры и давления. Метрические и английские меры, а также русские бытовые единицы: сотка, пуд, сажень, верста и ведро.');
        $this->set_keywords('конвертер единиц перевод величин метры сантиметры кг граммы гектар сотка пуд сажень верста ведро онлайн');
    }

    public function action_index()
    {
        $catalog = Service_Nj506_Units::catalog();
        $query = $this->request->query();
        $category = $this->_string($query, 'c', 'length', 32);
        if ( ! isset($catalog[$category])) $category = 'length';

        $definition = $catalog[$category];
        $from = $this->_string($query, 'from', $definition['default_from'], 32);
        $to = $this->_string($query, 'to', $definition['default_to'], 32);
        if ( ! isset($definition['units'][$from])) $from = $definition['default_from'];
        if ( ! isset($definition['units'][$to])) $to = $definition['default_to'];
        $value = $this->_string($query, 'v', '', 40);
        $result = $value !== '' ? Service_Nj506_Units::calculate($category, $value, $from, $to) : NULL;
        if ($result === NULL AND $value !== '') $value = '';

        $this->template->content = View::factory('506/index', [
            'catalog' => $catalog,
            'category' => $category,
            'value' => $value,
            'from' => $from,
            'to' => $to,
            'result' => $result,
            'data_note' => Service_Nj506_Units::data_note(),
        ]);
    }

    protected function _string(array $query, $key, $default, $limit)
    {
        return (isset($query[$key]) && is_scalar($query[$key]))
            ? mb_substr((string) $query[$key], 0, $limit)
            : $default;
    }
}
