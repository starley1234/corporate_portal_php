<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Welcome extends Controller_Base {
    public function before()
    {
        if (! Auth::instance()->logged_in())
        {
            HTTP::redirect('/auth/login?return_url=%2F');
        }
        parent::before();
        $this->set_menu(Prx_Hooks::call('nav.catalog_menu') ?: []);
    }

    public function action_index()
    {
        $title = 'Рабочий стол';
        $this->set_title($title);

        // Д2/Д7: слаги модулей приходят из точек расширения — ядро не знает
        // ни плана, ни монитора, ни ссылки на аккаунт (модуль не подключён —
        // хука нет, блока нет).
        $visible = array_column(Prx_Hooks::call('nav.catalog_plan') ?: [], NULL, 'module_name');
        $plan = Prx_Hooks::call('nav.welcome_plan') ?: [];
        $work = array_merge(
            [['title' => 'Все сервисы', 'href' => '/services']],
            $this->_plan_links((array) Arr::get($plan, 'work', []), $visible));
        $stand = $this->_plan_links((array) Arr::get($plan, 'stand', []), $visible);

        $account_link = Prx_Hooks::call('account.security_link');
        if ($account_link !== NULL)
        {
            $work[] = ['title' => 'Мой аккаунт', 'href' => $account_link];
        }

        $monitor_acl = Prx_Hooks::call('nav.monitor_acl');

        $this->template->content = View::factory('welcome/index', [
            'title' => $title,
            'work' => $work,
            'stand' => $stand,
            'can_monitor' => $monitor_acl !== NULL && $this->can($monitor_acl),
        ]);
    }

    /**
     * Ссылки плана на «Рабочем столе»: имена модулей приходят из точки
     * расширения nav.welcome_plan, видимость — из каталога (nav.catalog_plan),
     * доступ — по ACL из манифеста.
     */
    private function _plan_links(array $names, array $visible): array
    {
        $links = [];

        foreach ($names as $name)
        {
            $module = $visible[$name] ?? NULL;
            if (empty($module['acl']) || ! $this->can($module['acl']))
            {
                continue;
            }

            $links[] = ['title' => $module['title'], 'href' => $module['href']];
        }

        return $links;
    }
}
