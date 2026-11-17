<?php defined('SYSPATH') or die('No direct script access.');

/**
 * HR-администрирование prx300: структура, профили, сопоставления и импорт.
 * Все изменения — POST + CSRF; объектную и организационную policy проверяет
 * сервис, контроллер не доверяет organization_id из формы.
 */
class Controller_Prx300_Admin extends Controller_Base
{
    protected $_actor_cache;

    public function before()
    {
        parent::before();
        if ( ! $this->_can_admin())
        {
            throw HTTP_Exception::factory(403, 'Нужен HR-доступ к кадровым данным');
        }
        $this->set_title('Кадровые данные и структура холдинга');
        $this->set_module_nav([
            ['title' => 'Структура', 'href' => '/prx300/admin', 'icon' => 'fas fa-sitemap'],
            ['title' => 'Сотрудники', 'href' => '/prx300/admin/employees', 'icon' => 'fas fa-users'],
            ['title' => 'Импорт', 'href' => '/prx300/admin/import', 'icon' => 'fas fa-file-import'],
            ['title' => 'Мой профиль', 'href' => '/employee', 'icon' => 'fas fa-id-card'],
            ['title' => 'Пользователи Auth', 'href' => '/prx595/admin', 'icon' => 'fas fa-user-gear', 'acl' => 'prx595.users.admin'],
        ]);
    }

    public function action_index()
    {
        try
        {
            $dashboard = Service_Prx300_Admin::dashboard($this->_actor());
            $options = Service_Prx300_Admin::form_options($this->_actor());
        }
        catch (DomainException $e)
        {
            throw HTTP_Exception::factory(403, $e->getMessage());
        }

        $this->template->content = View::factory('prx300_employee/admin', [
            'dashboard' => $dashboard,
            'organizations' => $options['organizations'],
            'sites' => $options['sites'],
            'departments' => $options['departments'],
            'employees' => $options['employees'],
            'selected_organization' => $this->_selected($options['organizations'], (int) $this->request->query('organization_id')),
            'selected_site' => $this->_selected($options['sites'], (int) $this->request->query('site_id')),
            'selected_department' => $this->_selected($options['departments'], (int) $this->request->query('department_id')),
            'can_manage' => $this->can('prx300.manage'),
            'can_import' => $this->can('prx300.import'),
            'is_portal_admin' => $this->has_role('admin'),
            'csrf' => Security::token(),
            'flash' => $this->_flash(),
        ]);
    }

    public function action_organization()
    {
        $this->_post(function () {
            $id = (int) $this->request->post('id');
            Service_Prx300_Admin::save_organization($this->_actor(), $this->request->post(), $id ?: NULL);
            return 'Организация сохранена';
        }, '/prx300/admin');
    }

    public function action_site()
    {
        $this->_post(function () {
            $id = (int) $this->request->post('id');
            Service_Prx300_Admin::save_site($this->_actor(), $this->request->post(), $id ?: NULL);
            return 'Площадка сохранена';
        }, '/prx300/admin');
    }

    public function action_department()
    {
        $this->_post(function () {
            $id = (int) $this->request->post('id');
            Service_Prx300_Admin::save_department($this->_actor(), $this->request->post(), $id ?: NULL);
            return 'Подразделение сохранено';
        }, '/prx300/admin');
    }

    public function action_employees()
    {
        $filters = [
            'q' => trim((string) $this->request->query('q')),
            'organization_id' => (int) $this->request->query('organization_id'),
            'department_id' => (int) $this->request->query('department_id'),
            'site_id' => (int) $this->request->query('site_id'),
            'status' => trim((string) $this->request->query('status')),
            'unlinked' => ! empty($this->request->query('unlinked')),
            'without_external' => ! empty($this->request->query('without_external')),
            'page' => max(1, (int) $this->request->query('page')),
        ];
        try
        {
            $result = Service_Prx300_Admin::employees($this->_actor(), $filters);
            $options = Service_Prx300_Admin::form_options($this->_actor());
        }
        catch (DomainException $e)
        {
            throw HTTP_Exception::factory(403, $e->getMessage());
        }
        catch (InvalidArgumentException $e)
        {
            throw HTTP_Exception::factory(400, $e->getMessage());
        }

        $this->set_title('Сотрудники · кадровые данные');
        $this->template->content = View::factory('prx300_employee/employees', [
            'result' => $result,
            'options' => $options,
            'filters' => $filters,
            'can_manage' => $this->can('prx300.manage'),
            'csrf' => Security::token(),
            'flash' => $this->_flash(),
        ]);
    }

    public function action_employee()
    {
        $id = (int) $this->request->param('id');
        try
        {
            $options = Service_Prx300_Admin::form_options($this->_actor());
            $employee = $id ? Service_Prx300_Admin::employee_detail($this->_actor(), $id) : $this->_blank_employee($options);
            $identities = $id ? Service_Prx300_Identity::identities_for_employee($this->_actor(), $id) : [];
            $active_link = $id ? Service_Prx300_Identity::active_link($this->_actor(), $id) : NULL;
            $link_history = $id ? Service_Prx300_Identity::link_history($this->_actor(), $id) : [];
            $selected_identity = $this->_selected($identities, (int) $this->request->query('identity_id'));
        }
        catch (DomainException $e)
        {
            throw HTTP_Exception::factory(403, $e->getMessage());
        }

        $this->set_title($id ? 'Сотрудник: '.$employee['full_name'] : 'Новый сотрудник');
        $this->template->content = View::factory('prx300_employee/employee', [
            'employee' => $employee,
            'options' => $options,
            'identities' => $identities,
            'selected_identity' => $selected_identity,
            'active_link' => $active_link,
            'link_history' => $link_history,
            'can_manage' => $this->can('prx300.manage'),
            'can_identity' => $this->can('prx300.identity'),
            'csrf' => Security::token(),
            'flash' => $this->_flash(),
        ]);
    }

    public function action_employee_save()
    {
        $id = (int) $this->request->param('id');
        $saved_id = $id;
        $this->_post(function () use ($id, &$saved_id) {
            $saved_id = Service_Prx300_Admin::save_employee($this->_actor(), $this->request->post(), $id ?: NULL);
            return 'Профиль сотрудника сохранён';
        }, '/prx300/admin/employees', function () use (&$saved_id, $id) {
            return '/prx300/admin/employee/'.(int) ($saved_id ?: $id);
        });
    }

    public function action_identity_save()
    {
        $employee_id = (int) $this->request->param('id');
        $this->_post(function () use ($employee_id) {
            $identity_id = (int) $this->request->post('identity_id');
            Service_Prx300_Identity::save_identity(
                $this->_actor(), $employee_id, $this->request->post(), $identity_id ?: NULL
            );
            return 'Внешний идентификатор сохранён';
        }, '/prx300/admin/employee/'.$employee_id);
    }

    public function action_identity_disable()
    {
        $identity_id = (int) $this->request->param('id');
        $employee_id = (int) $this->request->post('employee_id');
        $this->_post(function () use ($identity_id) {
            Service_Prx300_Identity::disable_identity($this->_actor(), $identity_id);
            return 'Внешний идентификатор отключён';
        }, '/prx300/admin/employee/'.$employee_id);
    }

    public function action_link_user()
    {
        $employee_id = (int) $this->request->param('id');
        $this->_post(function () use ($employee_id) {
            Service_Prx300_Identity::link_user(
                $this->_actor(),
                $employee_id,
                (string) $this->request->post('user_identifier'),
                (string) ($this->request->post('linked_via') ?: 'manual'),
                (string) $this->request->post('note')
            );
            return 'Аккаунт сопоставлен с сотрудником';
        }, '/prx300/admin/employee/'.$employee_id);
    }

    public function action_unlink_user()
    {
        $employee_id = (int) $this->request->param('id');
        $this->_post(function () use ($employee_id) {
            Service_Prx300_Identity::unlink_user(
                $this->_actor(), $employee_id, (string) $this->request->post('reason')
            );
            return 'Связь аккаунта и сотрудника отозвана';
        }, '/prx300/admin/employee/'.$employee_id);
    }

    public function action_import()
    {
        // Маршрут /prx300/admin/import/<id> — карточка пакета; без ID — загрузка.
        $id = (int) $this->request->param('id');
        if ($id > 0)
        {
            $this->_import_batch($id);
            return;
        }
        try
        {
            $batches = Service_Prx300_Import::batches($this->_actor(), 30);
        }
        catch (DomainException $e)
        {
            throw HTTP_Exception::factory(403, $e->getMessage());
        }

        $this->set_title('Импорт кадровых данных');
        $this->template->content = View::factory('prx300_employee/import', [
            'batches' => $batches,
            'headers' => Service_Prx300_Import::allowed_headers(),
            'required_headers' => Service_Prx300_Import::required_headers(),
            'can_import' => $this->can('prx300.import'),
            'csrf' => Security::token(),
            'flash' => $this->_flash(),
        ]);
    }

    public function action_import_upload()
    {
        $batch_id = 0;
        $this->_post(function () use (&$batch_id) {
            $batch_id = Service_Prx300_Import::stage_upload(
                $this->_actor(),
                (array) Arr::get($_FILES, 'csv', []),
                (string) ($this->request->post('source_system') ?: 'hris')
            );
            return 'Пакет загружен и проверен. Применение — отдельным действием';
        }, '/prx300/admin/import', function () use (&$batch_id) {
            return '/prx300/admin/import/'.(int) $batch_id;
        });
    }

    public function action_import_apply()
    {
        $id = (int) $this->request->param('id');
        $this->_post(function () use ($id) {
            $batch = Service_Prx300_Import::apply_batch($this->_actor(), $id);
            return 'Пакет применён: создано '.$batch['created_count'].', обновлено '.$batch['updated_count']
                .', пропущено '.$batch['skipped_count'];
        }, '/prx300/admin/import/'.$id);
    }

    public function action_import_template()
    {
        $this->auto_render = FALSE;
        $this->response->headers('Content-Type', 'text/csv; charset=utf-8');
        $this->response->headers('Content-Disposition', 'attachment; filename="prx300-employees-template.csv"');
        $this->response->headers('Cache-Control', 'no-store');
        $this->response->body("\xEF\xBB\xBF".Service_Prx300_Import::template_csv());
    }

    protected function _import_batch($id)
    {
        try
        {
            $batch = Service_Prx300_Import::batch($this->_actor(), (int) $id, TRUE);
        }
        catch (DomainException $e)
        {
            throw HTTP_Exception::factory(403, $e->getMessage());
        }

        $this->set_title('Пакет импорта #'.(int) $id);
        $this->template->content = View::factory('prx300_employee/import_batch', [
            'batch' => $batch,
            'can_import' => $this->can('prx300.import'),
            'csrf' => Security::token(),
            'flash' => $this->_flash(),
        ]);
    }

    protected function _post(callable $operation, $default_redirect, callable $success_redirect = NULL)
    {
        if ($this->request->method() !== Request::POST)
        {
            throw HTTP_Exception::factory(405, 'Нужен POST');
        }
        if ( ! Security::check((string) $this->request->post('csrf')))
        {
            throw HTTP_Exception::factory(403, 'Сессия устарела, обновите страницу');
        }

        try
        {
            $message = (string) $operation();
            Session::instance()->set('prx300_flash', ['type' => 'ok', 'text' => $message]);
            $target = $success_redirect ? (string) $success_redirect() : $default_redirect;
        }
        catch (InvalidArgumentException $e)
        {
            Session::instance()->set('prx300_flash', ['type' => 'err', 'text' => $e->getMessage()]);
            $target = $default_redirect;
        }
        catch (DomainException $e)
        {
            Session::instance()->set('prx300_flash', ['type' => 'err', 'text' => $e->getMessage()]);
            $target = $default_redirect;
        }

        HTTP::redirect($target, 303);
    }

    protected function _flash()
    {
        $flash = Session::instance()->get_once('prx300_flash', []);

        return is_array($flash) ? $flash : [];
    }

    protected function _selected(array $rows, $id)
    {
        foreach ($rows as $row)
        {
            if ((int) ($row['id'] ?? 0) === (int) $id)
            {
                return $row;
            }
        }

        return NULL;
    }

    protected function _blank_employee(array $options)
    {
        $org = $options['organizations'][0] ?? [];

        return [
            'id' => 0,
            'organization_id' => (int) ($org['id'] ?? 0),
            'department_id' => NULL,
            'site_id' => NULL,
            'manager_employee_id' => NULL,
            'personnel_number' => '',
            'last_name' => '',
            'first_name' => '',
            'middle_name' => '',
            'full_name' => '',
            'position_title' => '',
            'work_email' => '',
            'work_phone' => '',
            'employment_status' => 'active',
            'employment_status_label' => 'Работает',
            'hired_on' => '',
            'terminated_on' => '',
            'source_system' => 'manual',
        ];
    }

    protected function _actor()
    {
        if ($this->_actor_cache !== NULL)
        {
            return $this->_actor_cache;
        }

        return $this->_actor_cache = [
            'user_id' => (int) $this->_current_user->id,
            'roles' => $this->_current_user_roles,
            'can_admin_view' => $this->can('prx300.admin.view'),
            'can_manage' => $this->can('prx300.manage'),
            'can_import' => $this->can('prx300.import'),
            'can_identity' => $this->can('prx300.identity'),
            'can_directory' => $this->can('prx300.directory'),
        ];
    }

    protected function _can_admin()
    {
        return $this->can('prx300.admin.view') OR $this->can('prx300.manage')
            OR $this->can('prx300.import') OR $this->can('prx300.identity');
    }
}
