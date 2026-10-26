<?php defined('SYSPATH') or die('No direct script access.');

/** Личный HR-профиль и справочник коллег своей организации. */
class Controller_Prl300_Profile extends Controller_Base
{
    public function before()
    {
        parent::before();
        if ( ! $this->can('prl300.view'))
        {
            throw HTTP_Exception::factory(403, 'Нужна роль login');
        }
        $this->set_title('Мой профиль сотрудника');
        $this->_nav();
    }

    public function action_index()
    {
        $profile = Service_Prl300_Directory::profile_for_user((int) $this->_current_user->id);
        $this->template->content = View::factory('prl300_employee/profile', [
            'profile' => $profile,
            'user' => $this->_current_user,
            'can_admin' => $this->_can_admin(),
        ]);
    }

    public function action_directory()
    {
        if ( ! $this->can('prl300.directory'))
        {
            throw HTTP_Exception::factory(403, 'Справочник сотрудников недоступен');
        }
        $filters = [
            'q' => trim((string) $this->request->query('q')),
            'organization_id' => (int) $this->request->query('organization_id'),
            'department_id' => (int) $this->request->query('department_id'),
            'site_id' => (int) $this->request->query('site_id'),
            'page' => max(1, (int) $this->request->query('page')),
        ];
        try
        {
            $result = Service_Prl300_Directory::directory($this->_actor(), $filters);
            $options = Service_Prl300_Directory::filter_options($this->_actor(), $filters);
        }
        catch (DomainException $e)
        {
            throw HTTP_Exception::factory(403, $e->getMessage());
        }

        $this->set_title('Справочник сотрудников');
        $this->template->content = View::factory('prl300_employee/directory', [
            'result' => $result,
            'options' => $options,
            'filters' => $filters,
            'can_admin' => $this->_can_admin(),
        ]);
    }

    public function action_colleague()
    {
        if ( ! $this->can('prl300.directory'))
        {
            throw HTTP_Exception::factory(403, 'Справочник сотрудников недоступен');
        }
        try
        {
            $colleague = Service_Prl300_Directory::colleague_card($this->_actor(), (int) $this->request->param('id'));
        }
        catch (DomainException $e)
        {
            throw HTTP_Exception::factory(403, $e->getMessage());
        }
        if ($colleague === NULL)
        {
            throw HTTP_Exception::factory(404, 'Сотрудник не найден');
        }

        $this->set_title('Сотрудник: '.$colleague['full_name']);
        $this->template->content = View::factory('prl300_employee/colleague', [
            'colleague' => $colleague,
            'can_admin' => $this->_can_admin(),
        ]);
    }

    protected function _actor()
    {
        return [
            'user_id' => (int) $this->_current_user->id,
            'roles' => $this->_current_user_roles,
            'can_admin_view' => $this->can('prl300.admin.view'),
            'can_manage' => $this->can('prl300.manage'),
            'can_import' => $this->can('prl300.import'),
            'can_identity' => $this->can('prl300.identity'),
            'can_directory' => $this->can('prl300.directory'),
        ];
    }

    protected function _can_admin()
    {
        return $this->can('prl300.admin.view') OR $this->can('prl300.manage')
            OR $this->can('prl300.import') OR $this->can('prl300.identity');
    }

    protected function _nav()
    {
        $items = [
            ['title' => 'Мой профиль', 'href' => '/employee', 'icon' => 'fas fa-id-card', 'acl' => 'prl300.view'],
            ['title' => 'Коллеги', 'href' => '/employee/directory', 'icon' => 'fas fa-users', 'acl' => 'prl300.directory'],
            ['title' => 'Аккаунт и безопасность', 'href' => '/account/security', 'icon' => 'fas fa-shield-halved', 'acl' => 'portal.access'],
        ];
        if ($this->_can_admin())
        {
            $items[] = ['title' => 'Кадровые данные', 'href' => '/prl300/admin', 'icon' => 'fas fa-sitemap'];
        }
        $this->set_module_nav($items);
    }
}
