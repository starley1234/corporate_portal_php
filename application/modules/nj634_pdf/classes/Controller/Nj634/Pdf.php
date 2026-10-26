<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «PDF-инструменты» (модуль NJ-634): /pdf
 *
 * Серверный слой выполняет все операции с PDF: разбор, извлечение текста,
 * генерацию, вырезание/перекомпоновку страниц. Браузер — только интерфейс.
 */
class Controller_Nj634_Pdf extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('PDF-инструменты: извлечь текст, PDF из текста, вырезать страницы онлайн');
        $this->set_description(
            'Бесплатные PDF-инструменты без установки: извлечение текста из PDF, данные и метаданные файла, создание PDF из текста с кириллицей, вырезание и перекомпоновка страниц. Файлы обрабатываются на сервере, без загрузки программ.');
        $this->set_keywords('pdf инструменты извлечь текст из pdf текст в pdf вырезать страницы объединить страницы метаданные pdf онлайн');

    }

    public function action_index()
    {
        $recent = class_exists('Service_Nj634_Pdf')
            ? Service_Nj634_Pdf::recent_runs(6)
            : [];

        $this->template->content = View::factory('634/index', [
            'recent'    => $recent,
            'rate_text' => class_exists('Service_Nj634_Pdf')
                ? Service_Nj634_Pdf::rate_limit_text()
                : '5 операций в минуту',
        ]);
    }
}
