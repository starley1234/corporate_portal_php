<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prl110_catalog: страницы каталога — /services и /services/<group-slug>.
 *
 * SSR (server-side render): полный каталог опубликованных сервисов виден
 * поисковикам и без JS. Источник — Service_Prl110_Catalog (те же манифесты,
 * что собирают сайдбар/шапку). Лендинги /s (prl611) остаются отдельным
 * «входом по задаче» и с каталогом не конкурируют.
 */
class Controller_Prl110_Catalog extends Controller_Base {

    public function before()
    {
        parent::before();

    }

    /** GET /services — каталог по группам + палитра поиска. */
    public function action_index()
    {
        $groups = [];
        $total  = 0;

        if (class_exists('Service_Prl110_Catalog'))
        {
            $ctx = Service_Prl110_Catalog::user_context();
            $summary = Service_Prl110_Catalog::groups_summary($ctx);

            foreach ($summary as $info)
            {
                $groups[] = [
                    'title' => (string) $info['title'],
                    'slug'  => (string) $info['slug'],
                    'icon'  => (string) $info['icon'],
                    'count' => (int) $info['count'],
                    'items' => Service_Prl110_Catalog::group_modules((string) $info['slug'], $ctx),
                ];
                $total += (int) $info['count'];
            }
        }

        $this->set_title('Каталог сервисов PRL — '.$total.' инструментов для работы и расчётов');
        $this->set_description('Все сервисы PRL.ru по разделам: финансы и расчёты, инструменты разработчика, конвертеры, продажи, документы и ИИ-конвейеры. Бесплатно, работают в браузере.');

        // На этой странице — своя навигация (группы в теле); чипы раздела в шапку не кладём.
        $this->set_module_nav([]);

        $this->template->content = View::factory('prl110/index', [
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

        if (class_exists('Service_Prl110_Catalog'))
        {
            foreach (Service_Prl110_Catalog::groups_summary() as $info)
            {
                if ((string) $info['slug'] === $slug)
                {
                    $title = (string) $info['title'];
                    $items = Service_Prl110_Catalog::group_modules($slug);
                    break;
                }
            }
        }

        if ($title === '' OR empty($items))
        {
            throw HTTP_Exception::factory(404, 'Такого раздела нет — смотрите каталог сервисов');
        }

        $this->set_title('Сервисы PRL — '.$title.' ('.count($items).')');
        $this->set_description('Сервисы раздела «'.$title.'» на PRL.ru: '.implode(', ', array_slice(array_map(function ($m) { return (string) $m['title']; }, $items), 0, 8)));

        $this->set_module_nav([]);

        $this->template->content = View::factory('prl110/group', [
            'title'     => $title,
            'gslug'     => $slug,
            'items'     => $items,
            'group_cnt' => count($items),
        ]);
    }
}
