<?php defined('SYSPATH') or die('No direct script access.');

/**
 * /architecture — собранная из YAML документация архитектуры портала.
 *
 * Страница отдаётся как есть: у неё собственная оболочка, поиск и схема.
 * Доступ — только авторизованным (prl101.view), потому что модель описывает
 * внутренние границы, очереди и правила доступа.
 */
class Controller_Prl101_Architecture extends Controller_Base
{
    public function action_index()
    {
        if ( ! $this->can('prl101.view'))
        {
            throw HTTP_Exception::factory(403, 'Недостаточно прав: prl101.view');
        }

        $html = Service_Prl101_Architecture::html();

        if ($html === NULL)
        {
            $this->set_title('Архитектура портала');
            $this->set_description('Страница ещё не собрана из architecture/arch');
            $this->template->content = View::factory('101/missing');

            return;
        }

        $this->auto_render = FALSE;
        $this->response
            ->headers('Content-Type', 'text/html; charset=utf-8')
            ->headers('Cache-Control', 'private, max-age=300')
            ->body($html);
    }

    /**
     * Машиночитаемая модель для агентов и скриптов.
     */
    public function action_data()
    {
        if ( ! $this->can('prl101.view'))
        {
            throw HTTP_Exception::factory(403, 'Недостаточно прав: prl101.view');
        }

        $json = Service_Prl101_Architecture::json();

        if ($json === NULL)
        {
            throw HTTP_Exception::factory(404, 'Модель ещё не собрана: make architecture');
        }

        $this->auto_render = FALSE;
        $this->response
            ->headers('Content-Type', 'application/json; charset=utf-8')
            ->headers('Cache-Control', 'private, max-age=300')
            ->body($json);
    }
}
