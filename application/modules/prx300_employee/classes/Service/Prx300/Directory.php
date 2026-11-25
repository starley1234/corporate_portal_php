<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный read-контракт кадрового справочника для следующих модулей.
 *
 * Другие модули не читают prx_300_* напрямую. Методы возвращают allowlist
 * рабочих полей и сами проверяют организационный контекст.
 */
class Service_Prx300_Directory extends Service_Prx300_Store
{
    /** Контекст сотрудника по Auth user_id из серверной сессии. */
    public static function context_for_user($user_id)
    {
        $employee = self::_employee_for_user((int) $user_id);
        if ($employee === NULL)
        {
            return NULL;
        }

        return [
            'user_id' => (int) $user_id,
            'employee_id' => (int) $employee['id'],
            'organization_id' => (int) $employee['organization_id'],
            'department_id' => $employee['department_id'],
            'site_id' => $employee['site_id'],
            'manager_employee_id' => $employee['manager_employee_id'],
            'employment_status' => $employee['employment_status'],
            'is_directory_active' => in_array($employee['employment_status'], Policy_Prx300_Employee::directoryStatuses(), TRUE),
        ];
    }

    /** Собственный профиль; чужой ID сюда не принимается. */
    public static function profile_for_user($user_id)
    {
        return self::_employee_for_user((int) $user_id);
    }

    public static function directory(Prx_Actor $actor, array $filters = [])
    {
        $organization_id = Policy_Prx300_Employee::directoryOrganization($actor, $filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per_page = max(1, min(100, (int) ($filters['per_page'] ?? self::_config('directory.page_size', 50))));
        $offset = ($page - 1) * $per_page;
        $statuses = Policy_Prx300_Employee::directoryStatuses();
        $params = [];
        $status_list = [];
        foreach ($statuses as $i => $status)
        {
            $params[':status'.$i] = (string) $status;
            $status_list[] = ':status'.$i;
        }

        $where = ['e.employment_status IN ('.implode(', ', $status_list).')'];
        if ($organization_id !== NULL)
        {
            $where[] = 'e.organization_id = '.(int) $organization_id;
        }
        if ( ! empty($filters['department_id']))
        {
            $where[] = 'e.department_id = '.(int) $filters['department_id'];
        }
        if ( ! empty($filters['site_id']))
        {
            $where[] = 'e.site_id = '.(int) $filters['site_id'];
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '')
        {
            $params[':like'] = '%'.mb_strtolower(self::_escape_like($q)).'%';
            $where[] = "(lower(e.last_name || ' ' || e.first_name || ' ' || e.middle_name) LIKE :like
                      OR lower(e.work_email) LIKE :like
                      OR lower(e.position_title) LIKE :like
                      OR lower(COALESCE(d.name, '')) LIKE :like
                      OR lower(COALESCE(s.name, '')) LIKE :like)";
        }

        $where_sql = implode(' AND ', $where);
        $from = ' FROM prx_300_employees e
                  JOIN prx_300_organizations o ON o.id = e.organization_id
                  LEFT JOIN prx_300_departments d ON d.id = e.department_id
                  LEFT JOIN prx_300_sites s ON s.id = e.site_id
                  LEFT JOIN prx_300_employees m ON m.id = e.manager_employee_id ';
        $total = (int) (self::_row('SELECT count(*) AS c'.$from.' WHERE '.$where_sql, $params)['c'] ?? 0);
        $rows = self::_all(self::_employee_select().' WHERE '.$where_sql
            .' ORDER BY e.last_name, e.first_name, e.middle_name LIMIT '.$per_page.' OFFSET '.$offset, $params);

        return [
            'rows' => array_map(function ($row) { return self::_format_employee($row, FALSE); }, $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => max(1, (int) ceil($total / $per_page)),
            'organization_id' => $organization_id,
        ];
    }

    public static function colleague_card(Prx_Actor $actor, $employee_id)
    {
        // Сначала определяем допустимую организацию, а уже потом ищем запись:
        // отсутствие карточки и чужая организация снаружи неразличимы.
        $organization_id = Policy_Prx300_Employee::directoryOrganization($actor, []);
        $params = [];
        $status_list = [];
        foreach (Policy_Prx300_Employee::directoryStatuses() as $i => $status)
        {
            $params[':status'.$i] = (string) $status;
            $status_list[] = ':status'.$i;
        }
        $where = 'e.id = '.(int) $employee_id.' AND e.employment_status IN ('.implode(', ', $status_list).')';
        if ($organization_id !== NULL)
        {
            $where .= ' AND e.organization_id = '.(int) $organization_id;
        }

        $row = self::_row(self::_employee_select().' WHERE '.$where.' LIMIT 1', $params);

        return $row ? self::_format_employee($row, FALSE) : NULL;
    }

    public static function filter_options(Prx_Actor $actor, array $filters = [])
    {
        $organization_id = Policy_Prx300_Employee::directoryOrganization($actor, $filters);
        $organizations = [];
        if ($organization_id === NULL)
        {
            foreach (self::_all("SELECT * FROM prx_300_organizations WHERE status = 'active' ORDER BY name") as $row)
            {
                $organizations[] = [
                    'id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name'],
                ];
            }
            $org_where = ! empty($filters['organization_id']) ? 'organization_id = '.(int) $filters['organization_id'] : 'TRUE';
        }
        else
        {
            $org = self::_row('SELECT * FROM prx_300_organizations WHERE id = '.(int) $organization_id);
            if ($org)
            {
                $organizations[] = [
                    'id' => (int) $org['id'], 'code' => (string) $org['code'], 'name' => (string) $org['name'],
                ];
            }
            $org_where = 'organization_id = '.(int) $organization_id;
        }

        $map = function ($rows) {
            return array_map(function ($row) {
                return [
                    'id' => (int) $row['id'],
                    'organization_id' => (int) $row['organization_id'],
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                ];
            }, $rows);
        };

        return [
            'organizations' => $organizations,
            'departments' => $map(self::_all(
                'SELECT * FROM prx_300_departments WHERE '.$org_where." AND status = 'active' ORDER BY sort_order, name"
            )),
            'sites' => $map(self::_all(
                'SELECT * FROM prx_300_sites WHERE '.$org_where." AND status = 'active' ORDER BY name"
            )),
            'organization_id' => $organization_id,
        ];
    }

    /**
     * Организации для внешних модулей: ключи API, интеграции.
     *
     * @return array<int, array{id:int,code:string,name:string}>
     */
    public static function organization_options()
    {
        return array_map(static function ($row) {
            return ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'name' => (string) $row['name']];
        }, self::_all("SELECT id, code, name FROM prx_300_organizations WHERE status = 'active' ORDER BY name"));
    }

    /** Существует ли активная организация — для ссылок без FK из других модулей. */
    public static function organization_exists($organization_id)
    {
        return (bool) self::_row("SELECT 1 FROM prx_300_organizations WHERE id = ".(int) $organization_id." AND status = 'active'");
    }

    /** Рабочая карточка по внутреннему ID для серверных модулей с actor-контекстом. */
    public static function employee_card(Prx_Actor $actor, $employee_id)
    {
        return self::colleague_card($actor, $employee_id);
    }
}
