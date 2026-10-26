<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичные страницы журнала N2J: /content — лента, /content/<slug> — статья.
 */
class Controller_Nj602_Content extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Журнал N2J — статьи для владельцев малого бизнеса');
        $this->set_description('Статьи, которые воркер N2J.ru собирает из свежих источников в интернете: ИИ пишет текст, строит mermaid-диаграмму и граф связей, оставляет ссылки на источники.');
    }

    public function action_index()
    {
        $this->template->content = View::factory('602/index', [
            'items' => Service_Nj602_Content::latest_public(30),
            'csrf'  => Security::token(),
        ]);
    }

    public function action_view()
    {
        $slug = (string) $this->request->param('slug');
        $r    = Service_Nj602_Content::by_slug($slug);

        if ($r === NULL)
        {
            throw HTTP_Exception::factory(404, 'Статья не найдена');
        }

        $this->set_title((string) ($r['title'] !== '' ? $r['title'] : $r['topic']).' — N2J.ru');
        $this->set_description(mb_substr((string) ($r['summary'] !== '' ? $r['summary'] : $r['topic']), 0, 300));

        $this->template->content = View::factory('602/view', [
            'r'        => $r,
            'csrf'     => Security::token(),
            // Модераторская карточка — только тем, кто вправе модерировать
            'is_admin' => $this->_current_user !== NULL AND $this->can('nj602.admin'),
        ]);
    }
}
