<?php defined('SYSPATH') or die('No direct script access.');

/** Локальный браузерный конвертер кириллицы и латиницы. */
class Controller_Nj514_Translit extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();
        $this->set_title('Транслитерация русского текста: загранпаспорт, ГОСТ и ICAO');
        $this->set_description('Транслитерация русского текста в латиницу и обратно по таблице МВД для загранпаспорта, ГОСТ 7.79-2000 система Б и упрощённой таблице ICAO. Текст не сохраняется.');
        $this->set_keywords('транслитерация онлайн русский английский латиница загранпаспорт ГОСТ 7.79 ICAO имя фамилия');
    }

    public function action_index()
    {
        $standards = Service_Nj514_Translit::standards();
        $this->template->content = View::factory('514/index', [
            'standards' => $standards,
            'privacy_note' => Service_Nj514_Translit::privacy_note(),
            'max_chars' => Service_Nj514_Translit::max_chars(),
        ]);
    }
}
