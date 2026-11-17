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

    }

    /** GET /services — каталог по группам + палитра поиска. */
    public function action_index()
    {
        $groups = [];
        $total  = 0;

        if (class_exists('Service_Prx110_Catalog'))
        {
            $ctx = Service_Prx110_Catalog::user_context();
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

        $this->set_title('Каталог сервисов PRX — '.$total.' инструментов для работы и расчётов');
        $this->set_description('Все сервисы PRX.ru по разделам: финансы и расчёты, инструменты разработчика, конвертеры, продажи, документы и ИИ-конвейеры. Бесплатно, работают в браузере.');

        // На этой странице — своя навигация (группы в теле); чипы раздела в шапку не кладём.
        $this->set_module_nav([]);

        $this->template->content = View::factory('prx110/index', [
            'groups' => $groups,
            'total'  => $total,
        ]);
    }

    /** GET /services/<slug> — хаб группы сервисов. */
    public function action_group()
    {
        $slug = (string) $this->request->param('slug');

        $title = '';
        $items = [];

        if (class_exists('Service_Prx110_Catalog'))
        {
            foreach (Service_Prx110_Catalog::groups_summary() as $info)
            {
                if ((string) $info['slug'] === $slug)
                {
                    $title = (string) $info['title'];
                    $items = Service_Prx110_Catalog::group_modules($slug);
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
        ]);
    }
}
