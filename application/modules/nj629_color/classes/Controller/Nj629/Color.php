<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Конвертер цветов» (модуль NJ-629): /color
 */
class Controller_Nj629_Color extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Конвертер цветов онлайн: HEX, RGB, HSL и оттенки');
        $this->set_description(
            'Перевести цвет между HEX, RGB и HSL, посмотреть оттенки и комплементарный цвет — для CSS и палитр. Без регистрации.');
        $this->set_keywords('конвертер цвета hex rgb hsl оттенки палитра css онлайн пипетка');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $value = isset($query['c']) ? (string) $query['c'] : '';
        $rgb   = Service_Nj629_Color::parse($value === '' ? '3b82f6' : $value);

        $this->template->content = View::factory('629/index', [
            'value'  => $value === '' ? '#3B82F6' : $value,
            'report' => $rgb !== NULL ? Service_Nj629_Color::report($rgb) : NULL,
        ]);
    }
}
