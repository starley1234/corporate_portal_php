<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Водяные знаки на PDF» (nj637_watermark): /watermark
 *
 * Серверный слой выполняет наложение (nj634 Annotate); браузер — интерфейс.
 */
class Controller_Nj637_Watermark extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Водяные знаки на PDF: защита, «копия», отпечаток — онлайн');
        $this->set_description(
            'Добавить водяной знак на PDF онлайн: полупрозрачный текст по диагонали каждой страницы или заметный отпечаток. Копии защищаются без установки программ — файл обрабатывается на сервере.');
        $this->set_keywords('водяной знак pdf копия защита документа отпечаток наложение текста на страницы онлайн');

    }

    public function action_index()
    {
        $recent = class_exists('Service_Nj637_Watermark')
            ? Service_Nj637_Watermark::recent_runs(6)
            : [];

        $this->template->content = View::factory('637/index', [
            'recent'    => $recent,
            'rate_text' => class_exists('Service_Nj637_Watermark')
                ? Service_Nj637_Watermark::rate_limit_text()
                : '5 операций в минуту',
        ]);
    }
}
