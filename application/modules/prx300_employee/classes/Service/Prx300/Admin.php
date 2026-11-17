<?php defined('SYSPATH') or die('No direct script access.');

/**
 * HR-администрирование prx300: организации, площадки, подразделения и
 * рабочие профили сотрудников. Удаления нет: записи архивируются или
 * переводятся в кадровый статус terminated.
 */
class Service_Prx300_Admin extends Service_Prx300_Store
{
    public static function dashboard(array $actor)
    {
        self::_require_admin_read($actor);
        $scope = self::_assert_scope($actor);
        $where = $scope['global'] ? 'TRUE' : 'organization_id = '.(int) $scope['organization_id'];
        $org_count = $scope['global']
            ? (int) (self::_row('SELECT count(*) AS c FROM prx_300_organizations')['c'] ?? 0)
            : 1;
        $count = function ($table, $extra = 'TRUE') use ($where) {
            return (int) (self::_row('SELECT count(*) AS c FROM '.$table.' WHERE '.$where.' AND '.$extra)['c'] ?? 0);
        };

        $unlinked_where = $scope['global'] ? 'TRUE' : 'e.organization_id = '.(int) $scope['organization_id'];
        $unlinked = (int) (self::_row("SELECT count(*) AS c FROM prx_300_employees e
            LEFT JOIN prx_300_user_links l ON l.employee_id = e.id AND l.status = 'active'
            WHERE {$unlinked_where} AND e.employment_status <> 'terminated' AND l.id IS NULL")['c'] ?? 0);
        $without_external = (int) (self::_row("SELECT count(*) AS c FROM prx_300_employees e
            LEFT JOIN prx_300_external_identities x ON x.employee_id = e.id AND x.status = 'active'
            WHERE {$unlinked_where} AND e.employment_status <> 'terminated' AND x.id IS NULL")['c'] ?? 0);

        return [
            'scope' => $scope,
            'organizations' => $org_count,
            'sites' => $count('prx_300_sites'),
            'departments' => $count('prx_300_departments'),
            'employees' => $count('prx_300_employees'),
            'employees_active' => $count('prx_300_employees', "employment_status IN ('active', 'on_leave')"),
            'employees_terminated' => $count('prx_300_employees', "employment_status = 'terminated'"),
            'employees_unlinked' => $unlinked,
            'employees_without_external_id' => $without_external,
            'recent_audit' => self::recent_audit($actor, 12),
        ];
    }

    public static function structure(array $actor)
    {
        self::_require_admin_read($actor);
        $scope = self::_assert_scope($actor);
        $org_where = $scope['global'] ? 'TRUE' : 'id = '.(int) $scope['organization_id'];
        $site_where = $scope['global'] ? 'TRUE' : 's.organization_id = '.(int) $scope['organization_id'];
        $department_where = $scope['global'] ? 'TRUE' : 'd.organization_id = '.(int) $scope['organization_id'];

        return [
            'scope' => $scope,
            'organizations' => self::_all('SELECT * FROM prx_300_organizations WHERE '.$org_where.' ORDER BY name'),
            'sites' => self::_all('SELECT s.*, o.name AS organization_name,
                    (SELECT count(*) FROM prx_300_employees e WHERE e.site_id = s.id) AS employee_count
                FROM prx_300_sites s JOIN prx_300_organizations o ON o.id = s.organization_id
                WHERE '.$site_where.' ORDER BY o.name, s.name'),
            'departments' => self::_all('SELECT d.*, o.name AS organization_name,
                    p.name AS parent_name, s.name AS site_name,
                    head.last_name AS manager_last_name, head.first_name AS manager_first_name,
                    head.middle_name AS manager_middle_name,
                    (SELECT count(*) FROM prx_300_employees e WHERE e.department_id = d.id) AS employee_count
                FROM prx_300_departments d
                JOIN prx_300_organizations o ON o.id = d.organization_id
                LEFT JOIN prx_300_departments p ON p.id = d.parent_id
                LEFT JOIN prx_300_sites s ON s.id = d.site_id
                LEFT JOIN prx_300_employees head ON head.id = d.manager_employee_id
                WHERE '.$department_where.' ORDER BY o.name, d.sort_order, d.name'),
        ];
    }

    public static function form_options(array $actor)
    {
        $structure = self::structure($actor);
        $scope = $structure['scope'];
        $where = $scope['global'] ? 'TRUE' : 'e.organization_id = '.(int) $scope['organization_id'];
        $employees = [];
        foreach (self::_all(self::_employee_select().' WHERE '.$where.' ORDER BY e.last_name, e.first_name LIMIT 5000') as $row)
        {
            $employees[] = self::_format_employee($row, TRUE);
        }

        return $structure + ['employees' => $employees];
    }

    public static function save_organization(array $actor, array $data, $id = NULL)
    {
        self::_require_can($actor, 'can_manage', 'Нужна роль hr_admin для изменения структуры');
        if ($id)
        {
            self::_assert_scope($actor, (int) $id);
        }
        elseif ( ! self::_is_admin($actor))
        {
            throw new DomainException('Новую организацию может создать только администратор портала');
        }

        $code = self::_code($data['code'] ?? '', 'Код организации');
        $name = self::_text($data['name'] ?? '', 'Название организации', 255, TRUE);
        $legal = self::_text($data['legal_name'] ?? '', 'Юридическое наименование', 512);
        $status = self::_entity_status($data['status'] ?? 'active');

        $duplicate = self::_row('SELECT id FROM prx_300_organizations WHERE lower(code) = '.self::_q($code)
            .($id ? ' AND id <> '.(int) $id : '').' LIMIT 1');
        if ($duplicate)
        {
            throw new DomainException('Организация с таким кодом уже существует');
        }

        if ($id)
        {
            self::_execute('UPDATE prx_300_organizations SET code = '.self::_q($code)
                .', name = '.self::_q($name).', legal_name = '.self::_q($legal)
                .', status = '.self::_q($status).' WHERE id = '.(int) $id);
        }
        else
        {
            $row = self::_row('INSERT INTO prx_300_organizations (code, name, legal_name, status)
                               VALUES ('.self::_q($code).', '.self::_q($name).', '.self::_q($legal).', '.self::_q($status).')
                               RETURNING id');
            $id = (int) $row['id'];
        }

        self::_audit($actor, (int) $id, 'organization', (int) $id, 'organization.saved', ['code' => $code, 'status' => $status]);

        return (int) $id;
    }

    public static function save_site(array $actor, array $data, $id = NULL)
    {
        self::_require_can($actor, 'can_manage', 'Нужна роль hr_admin для изменения структуры');
        $existing_org = $id ? self::_org_of('prx_300_sites', (int) $id) : NULL;
        $org_id = (int) ($data['organization_id'] ?? $existing_org ?? 0);
        self::_assert_scope($actor, $org_id);
        if ($existing_org !== NULL)
        {
            self::_assert_scope($actor, $existing_org);
            if ($org_id !== $existing_org)
            {
                throw new DomainException('Организация площадки не меняется обычным редактированием: создайте площадку в новой организации и заархивируйте старую');
            }
        }
        self::_organization_or_fail($org_id);

        $code = self::_code($data['code'] ?? '', 'Код площадки');
        $name = self::_text($data['name'] ?? '', 'Название площадки', 255, TRUE);
        $city = self::_text($data['city'] ?? '', 'Город', 120);
        $address = self::_text($data['address'] ?? '', 'Адрес', 2000);
        $timezone = self::_text($data['timezone'] ?? 'UTC', 'Часовой пояс', 64, TRUE);
        if ( ! in_array($timezone, timezone_identifiers_list(), TRUE))
        {
            throw new InvalidArgumentException('Часовой пояс: укажите имя IANA, например Europe/Samara');
        }
        $status = self::_entity_status($data['status'] ?? 'active');
        $duplicate = self::_row('SELECT id FROM prx_300_sites WHERE organization_id = '.$org_id
            .' AND lower(code) = '.self::_q($code).($id ? ' AND id <> '.(int) $id : '').' LIMIT 1');
        if ($duplicate)
        {
            throw new DomainException('Площадка с таким кодом уже есть в организации');
        }

        if ($id)
        {
            self::_execute('UPDATE prx_300_sites SET organization_id = '.$org_id.', code = '.self::_q($code)
                .', name = '.self::_q($name).', city = '.self::_q($city).', address = '.self::_q($address)
                .', timezone = '.self::_q($timezone).', status = '.self::_q($status).' WHERE id = '.(int) $id);
        }
        else
        {
            $row = self::_row('INSERT INTO prx_300_sites (organization_id, code, name, city, address, timezone, status)
                               VALUES ('.$org_id.', '.self::_q($code).', '.self::_q($name).', '.self::_q($city).', '
                                   .self::_q($address).', '.self::_q($timezone).', '.self::_q($status).') RETURNING id');
            $id = (int) $row['id'];
        }

        self::_audit($actor, $org_id, 'site', (int) $id, 'site.saved', ['code' => $code, 'status' => $status]);

        return (int) $id;
    }

    public static function save_department(array $actor, array $data, $id = NULL)
    {
        self::_require_can($actor, 'can_manage', 'Нужна роль hr_admin для изменения структуры');
        $existing_org = $id ? self::_org_of('prx_300_departments', (int) $id) : NULL;
        $org_id = (int) ($data['organization_id'] ?? $existing_org ?? 0);
        self::_assert_scope($actor, $org_id);
        if ($existing_org !== NULL)
        {
            self::_assert_scope($actor, $existing_org);
            if ($org_id !== $existing_org)
            {
                throw new DomainException('Организация подразделения не меняется обычным редактированием: создайте подразделение в новой организации и заархивируйте старое');
            }
        }
        self::_organization_or_fail($org_id);

        $parent_id = ! empty($data['parent_id']) ? (int) $data['parent_id'] : NULL;
        $site_id = ! empty($data['site_id']) ? (int) $data['site_id'] : NULL;
        $head_id = ! empty($data['manager_employee_id']) ? (int) $data['manager_employee_id'] : NULL;
        if ($parent_id)
        {
            self::_assert_child_org('prx_300_departments', $parent_id, $org_id, 'Родительское подразделение');
            if ($id AND self::_department_would_cycle((int) $id, $parent_id))
            {
                throw new DomainException('Родитель создаст цикл в структуре подразделений');
            }
        }
        if ($site_id)
        {
            self::_assert_child_org('prx_300_sites', $site_id, $org_id, 'Площадка подразделения');
        }
        if ($head_id)
        {
            self::_assert_child_org('prx_300_employees', $head_id, $org_id, 'Руководитель подразделения');
        }

        $code = self::_code($data['code'] ?? '', 'Код подразделения');
        $name = self::_text($data['name'] ?? '', 'Название подразделения', 255, TRUE);
        $sort = max(0, min(10000, (int) ($data['sort_order'] ?? 100)));
        $status = self::_entity_status($data['status'] ?? 'active');
        $duplicate = self::_row('SELECT id FROM prx_300_departments WHERE organization_id = '.$org_id
            .' AND lower(code) = '.self::_q($code).($id ? ' AND id <> '.(int) $id : '').' LIMIT 1');
        if ($duplicate)
        {
            throw new DomainException('Подразделение с таким кодом уже есть в организации');
        }

        if ($id)
        {
            self::_execute('UPDATE prx_300_departments SET organization_id = '.$org_id
                .', parent_id = '.self::_qi($parent_id).', site_id = '.self::_qi($site_id)
                .', manager_employee_id = '.self::_qi($head_id).', code = '.self::_q($code)
                .', name = '.self::_q($name).', sort_order = '.$sort.', status = '.self::_q($status)
                .' WHERE id = '.(int) $id);
        }
        else
        {
            $row = self::_row('INSERT INTO prx_300_departments
                (organization_id, parent_id, site_id, manager_employee_id, code, name, sort_order, status)
                VALUES ('.$org_id.', '.self::_qi($parent_id).', '.self::_qi($site_id).', '.self::_qi($head_id)
                    .', '.self::_q($code).', '.self::_q($name).', '.$sort.', '.self::_q($status).') RETURNING id');
            $id = (int) $row['id'];
        }

        self::_audit($actor, $org_id, 'department', (int) $id, 'department.saved', ['code' => $code, 'status' => $status]);

        return (int) $id;
    }

    public static function employees(array $actor, array $filters = [])
    {
        self::_require_admin_read($actor);
        $scope = self::_assert_scope($actor);
        $where = ['TRUE'];
        if ( ! $scope['global'])
        {
            $where[] = 'e.organization_id = '.(int) $scope['organization_id'];
        }
        elseif ( ! empty($filters['organization_id']))
        {
            $where[] = 'e.organization_id = '.(int) $filters['organization_id'];
        }
        if ( ! empty($filters['department_id']))
        {
            $where[] = 'e.department_id = '.(int) $filters['department_id'];
        }
        if ( ! empty($filters['site_id']))
        {
            $where[] = 'e.site_id = '.(int) $filters['site_id'];
        }
        if ( ! empty($filters['status']))
        {
            $status = (string) $filters['status'];
            if ( ! in_array($status, ['active', 'on_leave', 'suspended', 'terminated'], TRUE))
            {
                throw new InvalidArgumentException('Неизвестный кадровый статус');
            }
            $where[] = 'e.employment_status = '.self::_q($status);
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '')
        {
            $like = self::_q('%'.mb_strtolower(self::_escape_like($q)).'%');
            $where[] = "(lower(e.last_name || ' ' || e.first_name || ' ' || e.middle_name) LIKE {$like}
                      OR lower(e.work_email) LIKE {$like}
                      OR lower(e.personnel_number) LIKE {$like}
                      OR lower(e.position_title) LIKE {$like})";
        }
        if ( ! empty($filters['unlinked']))
        {
            $where[] = 'l.id IS NULL';
        }
        if ( ! empty($filters['without_external']))
        {
            $where[] = 'x.id IS NULL';
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $per_page = max(1, min(100, (int) ($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $per_page;
        $from = " FROM prx_300_employees e
                  JOIN prx_300_organizations o ON o.id = e.organization_id
                  LEFT JOIN prx_300_departments d ON d.id = e.department_id
                  LEFT JOIN prx_300_sites s ON s.id = e.site_id
                  LEFT JOIN prx_300_employees m ON m.id = e.manager_employee_id
                  LEFT JOIN prx_300_user_links l ON l.employee_id = e.id AND l.status = 'active'
                  LEFT JOIN prx_300_external_identities x ON x.employee_id = e.id AND x.status = 'active' ";
        $where_sql = implode(' AND ', $where);
        $total = (int) (self::_row('SELECT count(DISTINCT e.id) AS c'.$from.' WHERE '.$where_sql)['c'] ?? 0);
        $rows = self::_all('SELECT e.*, o.code AS organization_code, o.name AS organization_name,
                    d.code AS department_code, d.name AS department_name,
                    s.code AS site_code, s.name AS site_name, s.city AS site_city,
                    m.last_name AS manager_last_name, m.first_name AS manager_first_name,
                    m.middle_name AS manager_middle_name, m.position_title AS manager_position_title,
                    max(l.user_id) AS linked_user_id, count(DISTINCT x.id) AS external_identity_count'
                .$from.' WHERE '.$where_sql
                .' GROUP BY e.id, o.id, d.id, s.id, m.id ORDER BY e.last_name, e.first_name LIMIT '.$per_page.' OFFSET '.$offset);

        $out = [];
        foreach ($rows as $row)
        {
            $employee = self::_format_employee($row, TRUE);
            $employee['linked_user_id'] = isset($row['linked_user_id']) ? (int) $row['linked_user_id'] ?: NULL : NULL;
            $employee['external_identity_count'] = (int) ($row['external_identity_count'] ?? 0);
            $out[] = $employee;
        }

        return [
            'rows' => $out,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => max(1, (int) ceil($total / $per_page)),
            'scope' => $scope,
        ];
    }

    public static function employee_detail(array $actor, $employee_id)
    {
        self::_require_admin_read($actor);
        $row = self::_employee_or_fail((int) $employee_id, $actor);

        return self::_format_employee($row, TRUE);
    }

    public static function save_employee(array $actor, array $data, $id = NULL)
    {
        self::_require_can($actor, 'can_manage', 'Нужна роль hr_admin для изменения сотрудников');
        $existing_org = $id ? self::_org_of('prx_300_employees', (int) $id) : NULL;
        $org_id = (int) ($data['organization_id'] ?? $existing_org ?? 0);
        self::_assert_scope($actor, $org_id);
        if ($existing_org !== NULL)
        {
            self::_assert_scope($actor, $existing_org);
            if ($org_id !== $existing_org)
            {
                throw new DomainException('Перевод сотрудника между организациями требует отдельной согласованной процедуры и не выполняется обычным редактированием');
            }
        }
        self::_organization_or_fail($org_id);

        $department_id = ! empty($data['department_id']) ? (int) $data['department_id'] : NULL;
        $site_id = ! empty($data['site_id']) ? (int) $data['site_id'] : NULL;
        $manager_id = ! empty($data['manager_employee_id']) ? (int) $data['manager_employee_id'] : NULL;
        if ($department_id)
        {
            self::_assert_child_org('prx_300_departments', $department_id, $org_id, 'Подразделение сотрудника');
        }
        if ($site_id)
        {
            self::_assert_child_org('prx_300_sites', $site_id, $org_id, 'Площадка сотрудника');
        }
        if ($manager_id)
        {
            self::_assert_child_org('prx_300_employees', $manager_id, $org_id, 'Руководитель сотрудника');
            if ($id AND self::_manager_would_cycle((int) $id, $manager_id))
            {
                throw new DomainException('Руководитель создаст цикл в линии управления');
            }
        }

        $values = [
            'personnel_number' => self::_text($data['personnel_number'] ?? '', 'Табельный номер', 64),
            'last_name' => self::_text($data['last_name'] ?? '', 'Фамилия', 120, TRUE),
            'first_name' => self::_text($data['first_name'] ?? '', 'Имя', 120, TRUE),
            'middle_name' => self::_text($data['middle_name'] ?? '', 'Отчество', 120),
            'position_title' => self::_text($data['position_title'] ?? '', 'Должность', 255),
            'work_email' => mb_strtolower(self::_text($data['work_email'] ?? '', 'Рабочий email', 254)),
            'work_phone' => self::_text($data['work_phone'] ?? '', 'Рабочий телефон', 64),
        ];
        if ($values['work_email'] !== '' AND ! filter_var($values['work_email'], FILTER_VALIDATE_EMAIL))
        {
            throw new InvalidArgumentException('Рабочий email выглядит некорректно');
        }
        $status = (string) ($data['employment_status'] ?? 'active');
        if ( ! in_array($status, ['active', 'on_leave', 'suspended', 'terminated'], TRUE))
        {
            throw new InvalidArgumentException('Неизвестный кадровый статус');
        }
        $hired = self::_date($data['hired_on'] ?? '', 'Дата приёма');
        $terminated = self::_date($data['terminated_on'] ?? '', 'Дата увольнения');
        if ($terminated !== '' AND $status !== 'terminated')
        {
            throw new InvalidArgumentException('Дата увольнения допустима только со статусом «Уволен»');
        }
        if ($hired !== '' AND $terminated !== '' AND $terminated < $hired)
        {
            throw new InvalidArgumentException('Дата увольнения раньше даты приёма');
        }

        if ($values['personnel_number'] !== '')
        {
            $dup = self::_row('SELECT id FROM prx_300_employees WHERE organization_id = '.$org_id
                .' AND lower(personnel_number) = '.self::_q(mb_strtolower($values['personnel_number']))
                .($id ? ' AND id <> '.(int) $id : '').' LIMIT 1');
            if ($dup)
            {
                throw new DomainException('Табельный номер уже используется в этой организации');
            }
        }
        if ($values['work_email'] !== '')
        {
            $dup = self::_row('SELECT id FROM prx_300_employees WHERE organization_id = '.$org_id
                .' AND lower(work_email) = '.self::_q($values['work_email'])
                .($id ? ' AND id <> '.(int) $id : '').' LIMIT 1');
            if ($dup)
            {
                throw new DomainException('Рабочий email уже используется другим сотрудником организации');
            }
        }

        $source_system = $id
            ? (string) (self::_row('SELECT source_system FROM prx_300_employees WHERE id = '.(int) $id)['source_system'] ?? 'manual')
            : 'manual';
        if ($id)
        {
            self::_execute('UPDATE prx_300_employees SET organization_id = '.$org_id
                .', department_id = '.self::_qi($department_id).', site_id = '.self::_qi($site_id)
                .', manager_employee_id = '.self::_qi($manager_id)
                .', personnel_number = '.self::_q($values['personnel_number'])
                .', last_name = '.self::_q($values['last_name']).', first_name = '.self::_q($values['first_name'])
                .', middle_name = '.self::_q($values['middle_name']).', position_title = '.self::_q($values['position_title'])
                .', work_email = '.self::_q($values['work_email']).', work_phone = '.self::_q($values['work_phone'])
                .', employment_status = '.self::_q($status).', hired_on = '.self::_qn($hired)
                .', terminated_on = '.self::_qn($terminated).', source_system = '.self::_q($source_system)
                .', source_updated_at = now() WHERE id = '.(int) $id);
        }
        else
        {
            $row = self::_row('INSERT INTO prx_300_employees
                (organization_id, department_id, site_id, manager_employee_id, personnel_number,
                 last_name, first_name, middle_name, position_title, work_email, work_phone,
                 employment_status, hired_on, terminated_on, source_system, source_updated_at)
                VALUES ('.$org_id.', '.self::_qi($department_id).', '.self::_qi($site_id).', '.self::_qi($manager_id)
                    .', '.self::_q($values['personnel_number']).', '.self::_q($values['last_name'])
                    .', '.self::_q($values['first_name']).', '.self::_q($values['middle_name'])
                    .', '.self::_q($values['position_title']).', '.self::_q($values['work_email'])
                    .', '.self::_q($values['work_phone']).', '.self::_q($status).', '.self::_qn($hired)
                    .', '.self::_qn($terminated).", 'manual', now()) RETURNING id");
            $id = (int) $row['id'];
        }

        self::_audit($actor, $org_id, 'employee', (int) $id, 'employee.saved', [
            'employment_status' => $status,
            'department_id' => $department_id,
            'site_id' => $site_id,
        ]);

        return (int) $id;
    }

    public static function recent_audit(array $actor, $limit = 30)
    {
        self::_require_admin_read($actor);
        $scope = self::_assert_scope($actor);
        $where = $scope['global'] ? 'TRUE' : 'organization_id = '.(int) $scope['organization_id'];
        $rows = self::_all('SELECT * FROM prx_300_audit_log WHERE '.$where
            .' ORDER BY created_at DESC LIMIT '.max(1, min(100, (int) $limit)));
        foreach ($rows as &$row)
        {
            $decoded = json_decode((string) ($row['details'] ?? ''), TRUE);
            $row['details'] = is_array($decoded) ? $decoded : [];
            $row['id'] = (int) $row['id'];
            $row['actor_user_id'] = isset($row['actor_user_id']) ? (int) $row['actor_user_id'] ?: NULL : NULL;
            $row['organization_id'] = isset($row['organization_id']) ? (int) $row['organization_id'] ?: NULL : NULL;
            $row['entity_id'] = isset($row['entity_id']) ? (int) $row['entity_id'] ?: NULL : NULL;
        }
        unset($row);

        return $rows;
    }

    protected static function _require_admin_read(array $actor)
    {
        if ( ! self::_can($actor, 'can_admin_view') AND ! self::_can($actor, 'can_manage')
            AND ! self::_can($actor, 'can_import') AND ! self::_can($actor, 'can_identity'))
        {
            throw new DomainException('Нужен HR-доступ к кадровым данным');
        }
    }

    protected static function _entity_status($status)
    {
        $status = (string) $status;
        if ( ! in_array($status, ['active', 'archived'], TRUE))
        {
            throw new InvalidArgumentException('Статус: active или archived');
        }

        return $status;
    }

    protected static function _organization_or_fail($org_id)
    {
        if ((int) $org_id <= 0 OR ! self::_row('SELECT id FROM prx_300_organizations WHERE id = '.(int) $org_id))
        {
            throw new DomainException('Организация не найдена');
        }
    }

    protected static function _org_of($table, $id)
    {
        $row = self::_row('SELECT organization_id FROM '.$table.' WHERE id = '.(int) $id);
        if ( ! $row)
        {
            throw new DomainException('Запись не найдена');
        }

        return (int) $row['organization_id'];
    }

    protected static function _assert_child_org($table, $id, $org_id, $label)
    {
        $row = self::_row('SELECT organization_id FROM '.$table.' WHERE id = '.(int) $id);
        if ( ! $row OR (int) $row['organization_id'] !== (int) $org_id)
        {
            throw new DomainException($label.': запись не найдена в этой организации');
        }
    }

    protected static function _department_would_cycle($department_id, $new_parent_id)
    {
        $current = (int) $new_parent_id;
        $seen = [];
        while ($current > 0)
        {
            if ($current === (int) $department_id OR isset($seen[$current]))
            {
                return TRUE;
            }
            $seen[$current] = TRUE;
            $row = self::_row('SELECT parent_id FROM prx_300_departments WHERE id = '.$current);
            $current = $row ? (int) ($row['parent_id'] ?? 0) : 0;
        }

        return FALSE;
    }

    protected static function _manager_would_cycle($employee_id, $new_manager_id)
    {
        $current = (int) $new_manager_id;
        $seen = [];
        while ($current > 0)
        {
            if ($current === (int) $employee_id OR isset($seen[$current]))
            {
                return TRUE;
            }
            $seen[$current] = TRUE;
            $row = self::_row('SELECT manager_employee_id FROM prx_300_employees WHERE id = '.$current);
            $current = $row ? (int) ($row['manager_employee_id'] ?? 0) : 0;
        }

        return FALSE;
    }
}
