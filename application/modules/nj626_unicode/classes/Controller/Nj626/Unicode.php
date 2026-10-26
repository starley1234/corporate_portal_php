<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Unicode-анализатор» (модуль NJ-626): /unicode
 */
class Controller_Nj626_Unicode extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Unicode онлайн — коды символов, UTF-8 и escape-последовательности');
        $this->set_description(
            'Узнать кодовую точку символа, его UTF-8 байты и escape-записи \\uXXXX / \\u{...}, а также раскодировать их обратно в текст. Без регистрации.');
        $this->set_keywords('unicode код символа онлайн u+ utf-8 utf-16 escape юникод декодирование эмодзи');

    }

    public function action_index()
    {
        $query = $this->request->query();
        $text  = isset($query['t']) ? (string) $query['t'] : '';

        $this->template->content = View::factory('626/index', [
            'text'  => $text,
            'items' => $text === '' ? [] : Service_Nj626_Unicode::analyze($text),
        ]);
    }
}
