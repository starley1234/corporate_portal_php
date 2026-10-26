<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Единицы информации» (модуль NJ-625): /bytes
 */
class Controller_Nj625_Bytes extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Конвертер единиц информации: биты, байты, КиБ/МиБ и КБ/МБ — точный перевод');
        $this->set_description(
            'Перевести гигабайты в байты, мебибайты в мегабайты и наоборот: точная таблица по двоичной (1024) и десятичной (1000) шкале. Без регистрации.');
        $this->set_keywords('перевод единиц информации байты биты килобайт мегабайт гигабайт кибибайт мебибайт конвертер онлайн');

    }

    public function action_index()
    {
        $query  = $this->request->query();
        $value  = isset($query['v']) ? (string) $query['v'] : '';
        $parsed = Service_Nj625_Bytes::parse($value === '' ? '1 гиб' : $value);

        $this->template->content = View::factory('625/index', [
            'value'  => $value,
            'units'  => Service_Nj625_Bytes::units(),
            'table'  => $parsed !== NULL ? Service_Nj625_Bytes::table($parsed['bytes']) : [],
            'human'  => $parsed !== NULL ? Service_Nj625_Bytes::human($parsed['bytes']) : '',
            'unit'   => $parsed['unit'] ?? 'GiB',
        ]);
    }
}
