<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Контрольные цифры» (модуль NJ-628): /checksum
 */
class Controller_Nj628_Checksum extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Проверка контрольных сумм онлайн: ИНН, ОГРН, СНИЛС, карты, штрихкоды, CRC32');
        $this->set_description(
            'Проверить контрольные цифры ИНН, ОГРН, СНИЛСа, банковской карты (алгоритм Луна) и штрихкодов, а также посчитать CRC32 строки. Без регистрации.');
        $this->set_keywords('проверка контрольной цифры инн огрн снилс луна карта штрихкод ean upc crc32 онлайн');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $algo  = isset($query['a']) ? (string) $query['a'] : 'luhn';

        $this->template->content = View::factory('628/index', [
            'algo'   => $algo,
            'algos'  => Service_Nj628_Checksum::algorithms(),
            'value'  => isset($query['v']) ? (string) $query['v'] : '',
        ]);
    }
}
