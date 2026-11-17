<?php defined('SYSPATH') OR die('No direct script access.');

class Controller_Pages extends Controller_Base {

    // страницы раздела открыты гостям: legal-страницы обязаны быть доступны
    // без входа, а эталон дизайна оценивают внешние нейромодели
    protected $public_actions = [
        'about', 'terms', 'privacy',

    ];

    public function before()
    {
        parent::before();

        // меню раздела — в левую панель «Навигация» (группа «Страницы»);
        // чипы шапки для раздела считает Service_Prx110_Catalog::header_nav()
        $items = class_exists('Service_Prx110_Catalog')
            ? Service_Prx110_Catalog::menu_items()
            : [];

        if (class_exists('Service_Prx110_Catalog'))
        {
            foreach (Service_Prx110_Catalog::pages_items() as $pg)
            {
                $pg['group'] = 'Страницы';
                $items[] = $pg;
            }
        }

        $this->set_menu($items);
    }

    public function action_terms() {
        $this->template->content = View::factory('pages/terms');
        $this->_meta['title'] = "Условия использования";
    }
    public function action_privacy() {
        $this->template->content = View::factory('pages/privacy');
        $this->_meta['title'] = "Политика конфиденциальности";
    }

    public function action_about() {
        $this->template->content = View::factory('pages/about');
        $this->_meta['title'] = "О сервисе";
    }

    // Эталон дизайна v6 (docs/DESIGN-SYSTEM.md). Старые макеты удалены 20.10.2026.
    // ?scale=125 — тот же эталон в крупном режиме .prx-ds.is-125 (как ProcessHub).
    public function action_required_design_sample() {
        $scale = $this->request->query('scale') === '125' ? 1.125 : 1.0;
        $this->template->content = View::factory('pages/required_design_sample')
            ->set('scale', $scale);
        $this->_meta['title'] = "Дизайн сайта — эталонный образец модуля";
    }

}
