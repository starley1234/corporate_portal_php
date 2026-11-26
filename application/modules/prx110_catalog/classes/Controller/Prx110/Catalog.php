<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx110_catalog: страницы каталога — /services и /services/<group-slug>.
 *
 * SSR (server-side render): полный каталог опубликованных сервисов виден
 * поисковикам и без JS. Источник — Service_Prx110_Catalog (те же манифесты,
 * что собирают сайдбар/шапку). Лендинги /s (prx611) остаются отдельным
 * «входом по задаче» и с каталогом не конкурируют.
 */
class Controller_Prx110_Catalog extends Controller_Base {

    public function before()
    {
        parent::before();
        $this->response->headers('Cache-Control', 'private, no-store');
    }

    /** GET /services — каталог по группам + палитра поиска. */
    public function action_index()
    {
        $groups = [];
        $total  = 0;

        if (class_exists('Service_Prx110_Catalog'))
        {
            $ctx = Service_Prx110_Catalog::user_context();
            $ctx['preview'] = FALSE; // Предпросмотр остаётся в API каталога, не в /services.
            $summary = Service_Prx110_Catalog::groups_summary($ctx);

            foreach ($summary as $info)
            {
                $groups[] = [
                    'title' => (string) $info['title'],
                    'slug'  => (string) $info['slug'],
                    'icon'  => (string) $info['icon'],
                    'count' => (int) $info['count'],
                    'items' => Service_Prx110_Catalog::group_modules((string) $info['slug'], $ctx),
                ];
                $total += (int) $info['count'];
            }
        }

        $this->set_title('Каталог сервисов');
        $this->set_description('Сервисы, одобренные к работе: документы, ИИ, сотрудники, аккаунт и управление платформой. Разделы показываются с учётом прав доступа.');

        // На этой странице — своя навигация (группы в теле); чипы раздела в шапку не кладём.
        $this->set_module_nav([]);

        // Карточка «Меню «Сервисы»» видна только администратору (prx110.menu).
        $can_manage_menu = FALSE;
        try { $can_manage_menu = $this->actor()->can('prx110.menu'); } catch (Throwable $e) { /* гость */ }

        $this->template->content = View::factory('prx110/index', [
            'groups'  => $groups,
            'total'   => $total,
            'menu_custom' => $can_manage_menu ? Service_Prx110_Catalog::custom_menu_items() : [],
            'can_manage_menu' => $can_manage_menu,
            'menu_flash' => (string) Session::instance()->get_once('prx110_menu_flash', ''),
        ]);
    }

    /** POST /services/menu/add — пункт категории «Сервисы» для всех сотрудников. */
    public function action_menu_add()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw (HTTP_Exception::factory(405))->allowed('POST');
        }
        $this->actor()->require('prx110.menu');

        $flash = 'Сессия устарела — попробуйте ещё раз';
        if (Security::check((string) $this->request->post('csrf')))
        {
            $result = Service_Prx110_Catalog::add_menu_item(
                $this->actor()->id(),
                (string) $this->request->post('title'),
                (string) $this->request->post('href'),
                (string) $this->request->post('icon'));
            $flash = ! empty($result['ok'])
                ? 'Пункт «'.$result['title'].'» добавлен в меню «Сервисы»'
                : $result['error'];
        }

        Session::instance()->set('prx110_menu_flash', $flash);
        HTTP::redirect('/services#menu-items', 303);
    }

    /** POST /services/menu/delete/<id> — убрать пункт из категории «Сервисы». */
    public function action_menu_delete()
    {
        if ($this->request->method() !== Request::POST)
        {
            throw (HTTP_Exception::factory(405))->allowed('POST');
        }
        $this->actor()->require('prx110.menu');

        $flash = 'Сессия устарела — попробуйте ещё раз';
        if (Security::check((string) $this->request->post('csrf')))
        {
            $flash = Service_Prx110_Catalog::delete_menu_item((int) $this->request->param('id'))
                ? 'Пункт удалён из меню'
                : 'Пункт не найден — возможно, уже удалён';
        }

        Session::instance()->set('prx110_menu_flash', $flash);
        HTTP::redirect('/services#menu-items', 303);
    }

    /** GET /services/<slug> — хаб группы сервисов. */
    public function action_group()
    {
        $slug = (string) $this->request->param('slug');

        $title = '';
        $items = [];

        if (class_exists('Service_Prx110_Catalog'))
        {
            $ctx = Service_Prx110_Catalog::user_context();
            $ctx['preview'] = FALSE; // Предпросмотр остаётся в API каталога, не в /services.

            foreach (Service_Prx110_Catalog::groups_summary($ctx) as $info)
            {
                if ((string) $info['slug'] === $slug)
                {
                    $title = (string) $info['title'];
                    $items = Service_Prx110_Catalog::group_modules($slug, $ctx);
                    break;
                }
            }
        }

        if ($title === '' OR empty($items))
        {
            throw HTTP_Exception::factory(404, 'Такого раздела нет — смотрите каталог сервисов');
        }

        $this->set_title('Сервисы PRX — '.$title.' ('.count($items).')');
        $this->set_description('Сервисы раздела «'.$title.'» на PRX.ru: '.implode(', ', array_slice(array_map(function ($m) { return (string) $m['title']; }, $items), 0, 8)));

        $this->set_module_nav([]);

        $this->template->content = View::factory('prx110/group', [
            'title'     => $title,
            'gslug'     => $slug,
            'items'     => $items,
            'group_cnt' => count($items),
            'preview'   => FALSE, // совпадает с ctx выше; view флага не читает
        ]);
    }
}
