<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница «Markdown-инструмент» (модуль NJ-615): /markdown
 *
 * Markdown-lite → HTML с живым предпросмотром (клиентское зеркало в
 * views/615/script.php). Серверная версия — Service_Nj615_Markdown для API.
 */
class Controller_Nj615_Markdown extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Markdown-инструмент: предпросмотр и HTML онлайн');
        $this->set_description(
            'Бесплатный Markdown-инструмент: живой предпросмотр, конвертация в HTML, копирование результата. Без отправки на сервер; JSON API — для интеграций и ИИ-агентов.');
        $this->set_keywords('markdown редактор предпросмотр html конвертер онлайн mdown разметка');

    }

    public function action_index()
    {
        $query  = $this->request->query();
        $input  = isset($query['q']) ? $query['q'] : '';
        $result = $input !== '' ? Service_Nj615_Markdown::render($input) : NULL;

        $this->template->content = View::factory('615/index', [
            'input'  => (string) $input,
            'result' => $result,
        ]);
    }
}
