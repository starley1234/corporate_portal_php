<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx113: страница управления Избранным (/favorites).
 *
 * Читает и пишет ТОЛЬКО через публичный Service_Prx110_Favorites — владелец
 * таблицы prx_110_favorites. Форма отправляет параллельные массивы
 * href[]/title[]/icon[]/category[]/note[] в DOM-порядке: перетаскивание
 * двигает узлы, порядок в POST — порядок на странице.
 */
class Controller_Prx113_Favorites extends Controller_Base {

    public function before()
    {
        parent::before();
        $this->actor()->require('prx113.view');
        $this->response->headers('Cache-Control', 'private, no-store');
    }

    public function action_index()
    {
        $items = Service_Prx110_Favorites::items($this->actor()->id());
        $categories = [];
        foreach ($items as $item)
        {
            $category = trim((string) ($item['category'] ?? ''));
            if ($category !== '') $categories[$category] = TRUE;
        }

        $this->set_title('Избранное — управление');
        $this->set_module_nav([
            ['title' => 'Все сервисы', 'href' => '/services', 'icon' => 'fas fa-grip'],
        ]);
        $this->template->content = View::factory('prx113/index', [
            'items'       => $items,
            'categories'  => array_keys($categories),
            'flash'       => (string) Session::instance()->get_once('prx113_flash', ''),
        ]);
    }

    /** POST /favorites/save — порядок, названия, категории, заметки. */
    public function action_save()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw (HTTP_Exception::factory(405))->allowed('POST');
        }
        $this->actor()->require('prx113.view');

        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            Session::instance()->set('prx113_flash', 'Сессия устарела — попробуйте ещё раз');
            HTTP::redirect('/favorites', 303);
            return;
        }

        $hrefs = (array) $this->request->post('href');
        $titles = (array) $this->request->post('title');
        $icons = (array) $this->request->post('icon');
        $categories = (array) $this->request->post('category');
        $notes = (array) $this->request->post('note');

        $list = [];
        foreach ($hrefs as $i => $href)
        {
            if ( ! is_string($href)) continue;
            $list[] = [
                'href' => $href,
                'title' => isset($titles[$i]) && is_string($titles[$i]) ? $titles[$i] : '',
                'icon' => isset($icons[$i]) && is_string($icons[$i]) ? $icons[$i] : '',
                'category' => isset($categories[$i]) && is_string($categories[$i]) ? $categories[$i] : '',
                'note' => isset($notes[$i]) && is_string($notes[$i]) ? $notes[$i] : '',
            ];
        }

        $error = count($list) === 0 ? NULL : Service_Prx110_Favorites::reorder($this->actor()->id(), $list);
        Session::instance()->set('prx113_flash', $error === NULL
            ? 'Избранное сохранено: порядок и подписки обновлены'
            : 'Не удалось сохранить: '.$error);

        HTTP::redirect('/favorites', 303);
    }
}
