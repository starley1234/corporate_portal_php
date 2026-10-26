<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Калькулятор процентов» (модуль NJ-518): /percent
 *
 * Состояние — в адресе: ?m=of&a=15&b=2000 (ссылкой можно поделиться).
 * Сервер рендерит начальный результат, дальше считает JS-зеркало.
 */
class Controller_Nj518_Percent extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Калькулятор процентов онлайн: процент от числа, прирост, наценка, скидка');
        $this->set_description(
            'Посчитать процент от числа, узнать, сколько процентов составляет число, прирост и убыль, наценку, маржу и скидку. Формула с подстановкой чисел. Бесплатно, без регистрации.');
        $this->set_keywords('калькулятор процентов процент от числа сколько процентов прирост убыль наценка маржа скидка онлайн');
    }

    public function action_index()
    {
        $q     = $this->request->query();
        $modes = Service_Nj518_Percent::modes();
        $mode  = (isset($q['m']) AND is_string($q['m']) AND isset($modes[$q['m']])) ? $q['m'] : 'of';

        $a = isset($q['a']) && is_scalar($q['a']) ? (string) $q['a'] : '';
        $b = isset($q['b']) && is_scalar($q['b']) ? (string) $q['b'] : '';

        $result = ($a !== '' AND $b !== '') ? Service_Nj518_Percent::calculate($mode, $a, $b) : NULL;

        $this->template->content = View::factory('518/index', [
            'modes'  => $modes,
            'mode'   => $mode,
            'a'      => $a,
            'b'      => $b,
            'result' => $result,
        ]);
    }
}
