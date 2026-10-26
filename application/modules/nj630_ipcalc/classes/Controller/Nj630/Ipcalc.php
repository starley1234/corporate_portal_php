<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «IP и подсети» (модуль NJ-630): /ipcalc
 */
class Controller_Nj630_Ipcalc extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('IP-калькулятор подсетей: CIDR, маска, диапазон адресов онлайн');
        $this->set_description(
            'Посчитать подсеть IPv4: адрес сети, broadcast, маску и диапазон хостов по записи вида 192.168.1.0/24. Без регистрации.');
        $this->set_keywords('ip калькулятор подсеть cidr маска диапазон адресов network broadcast хосты ipv4 онлайн');

    }

    public function action_index()
    {
        $query  = $this->request->query();
        $value  = isset($query['v']) ? (string) $query['v'] : '';
        $report = Service_Nj630_Ipcalc::report($value !== '' ? $value : '192.168.1.0/24');

        $this->template->content = View::factory('630/index', [
            'value'  => $value !== '' ? $value : '192.168.1.0/24',
            'report' => $report,
        ]);
    }
}
