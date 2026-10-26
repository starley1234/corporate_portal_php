<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj611: «Каталог сервисов» — лендинги групп по задачам пользователя.
 *
 * Вся логика страниц — в Service_Nj611_Landing (конфиг групп + манифесты
 * nj110_catalog); контроллер только выбирает группу и рисует вид.
 * SEO-заголовки задаются в config/nj611_landing.php целиком.
 */
class Controller_Nj611_Landing extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

    }

    public function action_index()
    {
        // /s — индекс групп
        $groups = Service_Nj611_Landing::groups();

        $this->set_title('Сервисы N2J по задачам — найти клиентов, проверить компанию, наполнить блог');
        $this->set_description('Подборки инструментов N2J.ru под задачу: поиск клиентов и заказов, проверка контрагентов, автоконтент. Быстрый старт прямо со страницы — без регистрации.');

        // Группы показываются карточками в теле страницы; чипы в шапку не
        // кладём — много чипов портят шапку и дают горизонтальную прокрутку.
        $this->set_module_nav([]);

        $this->template->content = View::factory('611/index', [
            'groups' => $groups,
            'extras' => Service_Nj611_Landing::extras(),
        ]);
    }

    public function action_group()
    {
        // /s/<slug> — лендинг группы
        $group = Service_Nj611_Landing::group((string) $this->request->param('slug'));

        if ($group === NULL)
        {
            throw HTTP_Exception::factory(404, 'Такой подборки нет — смотрите каталог сервисов');
        }

        $this->set_title((string) ($group['seo_title'] ?? $group['h1']));
        $this->set_description((string) ($group['seo_description'] ?? $group['promise']));

        // Другие группы — ссылками внутри страницы (flex-wrap), а не чипами в
        // шапке: несколько чипов в шапке дают уродливую горизонтальную прокрутку.
        $this->set_module_nav([]);

        $this->template->content = View::factory('611/landing', [
            'g'            => $group,
            'other_groups' => self::_other_groups($group['href']),
        ]);
    }

    /** Прочие группы для навигации внутри лендинга (текущая помечена active). */
    protected static function _other_groups(string $active_href): array
    {
        $active_href = rtrim($active_href, '/');
        $out = [];

        foreach (Service_Nj611_Landing::groups() as $g)
        {
            $out[] = [
                'title'  => $g['title'],
                'href'   => $g['href'],
                'icon'   => $g['icon'],
                'active' => rtrim($g['href'], '/') === $active_href,
            ];
        }

        return $out;
    }

}
