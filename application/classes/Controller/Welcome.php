<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Welcome extends Controller_Base {
    public function before()
    {
        if (! Auth::instance()->logged_in())
        {
            HTTP::redirect('/auth/login?return_url=%2F');
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
}
