<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Welcome extends Controller_Base {
    public function before()
    {
        if (! Auth::instance()->logged_in())
        {
            $return = $this->request->action() === 'portal_plan' ? '/portal-plan' : '/';
            HTTP::redirect('/auth/login?return_url='.rawurlencode($return));
        }
        parent::before();
        $this->set_menu(Service_Prx110_Catalog::menu_items());
    }

    public function action_index()
    {
        $title = 'Рабочий стол';
        $this->set_title($title);

        $visible = array_column(Service_Prx110_Catalog::plan_modules(), NULL, 'module_name');
        $modules = Kohana::modules();
        $work = [['title' => 'Все сервисы', 'href' => '/services']];
        $stand = [];
        foreach (['prx395_document_extract', 'prx597_notifications', 'prx390_intake',
            'prx391_documents', 'prx392_analysis', 'prx393_workflow'] as $name)
        {
            $module = $visible[$name] ?? NULL;
            if (empty($module['acl']) || ! $this->can($module['acl']))
            {
                continue;
            }

            $link = ['title' => $module['title'], 'href' => $module['href']];
            if ($name === 'prx395_document_extract' || $name === 'prx597_notifications')
            {
                $work[] = $link;
            }
            else
            {
                $stand[] = $link;
            }
        }

        if (isset($modules['prx599_account']))
        {
            $work[] = ['title' => 'Мой аккаунт', 'href' => '/account/security'];
        }

        $this->template->content = View::factory('welcome/index', [
            'title' => $title,
            'work' => $work,
            'stand' => $stand,
            'can_monitor' => isset($modules['prx120_jobs']) && $this->can('prx120.monitor'),
        ]);
    }

    public function action_portal_plan()
    {
        $title = 'План и архив портала';
        $this->set_title($title);

        $domains = (array) Kohana::$config->load('portal')->get('domains');
        $catalog = class_exists('Service_Prx110_Catalog');
        $ctx = $catalog ? Service_Prx110_Catalog::user_context() : [];
        $planned = [];
        $plan = [];

        foreach ($domains as $domain)
        {
            $services = [];

            foreach ((array) ($domain['services'] ?? []) as $service)
            {
                $module_name = (string) ($service['module'] ?? '');
                $module = ($catalog && $module_name !== '')
                    ? Service_Prx110_Catalog::get($module_name)
                    : NULL;

                if ($module)
                {
                    // Реализованный сервис: заголовок, состояние и адрес берём
                    // из манифеста — план не дублирует статус и не расходится с ним.
                    $state = Service_Prx110_Catalog::resolve_state($module);
                    $planned[$module_name] = TRUE;
                    $services[] = [
                        'title'   => (string) $module['title'],
                        'state'   => $state,
                        'label'   => Service_Prx110_Catalog::state_label($state),
                        'href'    => ($catalog && Service_Prx110_Catalog::can_see($module, $ctx))
                            ? (string) $module['href']
                            : NULL,
                    ];
                    continue;
                }

                // Модуль не подключён: единственный источник — отложенный план.
                $state = (($service['status'] ?? '') === 'archived') ? 'archived' : 'planned';
                $services[] = [
                    'title' => (string) ($service['title'] ?? $module_name),
                    'state' => $state,
                    'label' => $catalog ? Service_Prx110_Catalog::state_label($state) : $state,
                    'href'  => NULL,
                ];
            }

            $plan[] = [
                'title'    => (string) ($domain['title'] ?? ''),
                'services' => $services,
            ];
        }

        // Реализованные сервисы вне отложенного плана: «одобренные» и «в работе».
        // Платформенные и административные экраны сюда не попадают — они живут
        // в /services (для админа) и в /admin.
        $other = [];

        if ($catalog)
        {
            foreach (Service_Prx110_Catalog::plan_modules($ctx) as $m)
            {
                if (isset($planned[$m['module_name']]))
                {
                    continue;
                }

                if (($m['kind'] ?? 'module') === 'platform')
                {
                    continue;
                }

                if (Service_Prx110_Catalog::resolve_audience($m) === 'admin')
                {
                    continue;
                }

                if ( ! in_array($m['state'], ['approved', 'wip', 'unpublished'], TRUE))
                {
                    continue;
                }

                // Сам каталог и главная — точки входа, а не пункты плана.
                if (in_array((string) $m['href'], ['/', '/services'], TRUE))
                {
                    continue;
                }

                $other[] = [
                    'title' => (string) $m['title'],
                    'group' => (string) $m['group'],
                    'state' => (string) $m['state'],
                    'label' => Service_Prx110_Catalog::state_label($m['state']),
                    'href'  => Service_Prx110_Catalog::can_see($m, $ctx)
                        ? (string) $m['href']
                        : NULL,
                ];
            }
        }

        $this->template->content = View::factory('welcome/portal_plan', [
            'title'  => $title,
            'phases' => (array) Kohana::$config->load('enterprise_plan')->get('phases'),
            'plan'   => $plan,
            'other'  => $other,
        ]);
    }
}
