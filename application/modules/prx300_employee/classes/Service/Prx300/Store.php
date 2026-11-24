<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Внутренняя основа сервисов prx300: доступ к своим таблицам, валидация и
 * вычисление организационного контекста.
 *
 * Сервисы принимают Prx_Actor; ACL и границы организации — Policy_Prx300_Employee.
 */
abstract class Service_Prx300_Store
{
    protected static $_db;

    protected static function _db()
    {
        return self::$_db !== NULL ? self::$_db : self::$_db = Database::instance();
    }

    protected static function _config($path, $default = NULL)
    {
        $value = Kohana::$config->load('prx300_employee')->get($path);

        return $value === NULL ? $default : $value;
    }

    protected static function _row($sql)
    {
        $row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    protected static function _all($sql)
    {
        $rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();

        return array_map(function ($row) { return (array) $row; }, $rows);
    }

    protected static function _execute($sql)
    {
        return self::_db()->query(Database::SELECT, $sql, FALSE);
    }

    protected static function _q($value)
    {
        return self::_db()->quote((string) $value);
    }

    protected static function _qn($value)
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? 'NULL' : self::_q($value);
    }

    protected static function _qi($value)
    {
        return ($value === NULL OR $value === '' OR (int) $value <= 0) ? 'NULL' : (string) (int) $value;
    }

    protected static function _qbool($value)
    {
        return ! empty($value) ? 'TRUE' : 'FALSE';
    }

    protected static function _qjson(array $value)
    {
        return self::_q(json_encode($value, JSON_UNESCAPED_UNICODE)).'::jsonb';
    }

    protected static function _in(array $values)
    {
        if ( ! $values)
        {
            return "''";
        }

        return implode(', ', array_map(function ($value) { return self::_q($value); }, $values));
    }

    protected static function _text($value, $label, $max, $required = FALSE)
    {
        $value = trim((string) ($value ?? ''));
        $value = preg_replace('/\s+/u', ' ', $value);

        if ($required AND $value === '')
        {
            throw new InvalidArgumentException($label.': обязательно для заполнения');
        }
        if (mb_strlen($value) > $max)
        {
            throw new InvalidArgumentException($label.': не длиннее '.$max.' символов');
        }

        return $value;
    }

    protected static function _code($value, $label)
    {
        $value = mb_strtolower(trim((string) ($value ?? '')));

        if ( ! preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $value) OR mb_strlen($value) > 64)
        {
            throw new InvalidArgumentException($label.': допустимы a-z, 0-9, точка, дефис и подчёркивание');
        }

        return $value;
    }

    protected static function _date($value, $label)
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '')
        {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === FALSE OR (is_array($errors) AND ($errors['warning_count'] > 0 OR $errors['error_count'] > 0)))
        {
            throw new InvalidArgumentException($label.': нужна дата в формате ГГГГ-ММ-ДД');
        }

        return $date->format('Y-m-d');
    }

    protected static function _datetime($value, $label)
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '')
        {
            return '';
        }

        try
        {
            $date = new DateTimeImmutable($value);
        }
        catch (Throwable $e)
        {
            throw new InvalidArgumentException($label.': некорректная дата и время');
        }

        return $date->format('Y-m-d H:i:sP');
    }

    protected static function _employee_select()
    {
        return "SELECT e.*, o.code AS organization_code, o.name AS organization_name,
                       d.code AS department_code, d.name AS department_name,
                       s.code AS site_code, s.name AS site_name, s.city AS site_city,
                       m.last_name AS manager_last_name, m.first_name AS manager_first_name,
                       m.middle_name AS manager_middle_name, m.position_title AS manager_position_title
                FROM prx_300_employees e
                JOIN prx_300_organizations o ON o.id = e.organization_id
                LEFT JOIN prx_300_departments d ON d.id = e.department_id
                LEFT JOIN prx_300_sites s ON s.id = e.site_id
                LEFT JOIN prx_300_employees m ON m.id = e.manager_employee_id";
    }

    protected static function _employee_for_user($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0)
        {
            return NULL;
        }

        $row = self::_row(self::_employee_select()."
            JOIN prx_300_user_links l ON l.employee_id = e.id
             AND l.status = 'active'
            WHERE l.user_id = ".$uid.' LIMIT 1');

        return $row ? self::_format_employee($row, TRUE) : NULL;
    }

    protected static function _employee_or_fail($employee_id, ?Prx_Actor $actor = NULL)
    {
        $row = self::_row(self::_employee_select().' WHERE e.id = '.(int) $employee_id.' LIMIT 1');
        if ($row === NULL)
        {
            throw new DomainException('Сотрудник не найден');
        }
        if ($actor !== NULL)
        {
            Policy_Prx300_Employee::assertOrganization($actor, (int) $row['organization_id']);
        }

        return $row;
    }

    public static function status_label($status)
    {
        $labels = [
            'active' => 'Работает',
            'on_leave' => 'В отпуске / отсутствует',
            'suspended' => 'Временно приостановлен',
            'terminated' => 'Уволен',
        ];

        return $labels[(string) $status] ?? (string) $status;
    }

    public static function full_name(array $row, $prefix = '')
    {
        $parts = [];
        foreach (['last_name', 'first_name', 'middle_name'] as $field)
        {
            $key = $prefix.$field;
            if ( ! empty($row[$key]))
            {
                $parts[] = trim((string) $row[$key]);
            }
        }

        return implode(' ', $parts);
    }

    protected static function _format_employee(array $row, $detailed = FALSE)
    {
        $out = [
            'id' => (int) $row['id'],
            'organization_id' => (int) $row['organization_id'],
            'organization_code' => (string) ($row['organization_code'] ?? ''),
            'organization_name' => (string) ($row['organization_name'] ?? ''),
            'department_id' => isset($row['department_id']) ? (int) $row['department_id'] ?: NULL : NULL,
            'department_code' => (string) ($row['department_code'] ?? ''),
            'department_name' => (string) ($row['department_name'] ?? ''),
            'site_id' => isset($row['site_id']) ? (int) $row['site_id'] ?: NULL : NULL,
            'site_code' => (string) ($row['site_code'] ?? ''),
            'site_name' => (string) ($row['site_name'] ?? ''),
            'site_city' => (string) ($row['site_city'] ?? ''),
            'manager_employee_id' => isset($row['manager_employee_id']) ? (int) $row['manager_employee_id'] ?: NULL : NULL,
            'manager_name' => self::full_name($row, 'manager_'),
            'manager_position_title' => (string) ($row['manager_position_title'] ?? ''),
            'last_name' => (string) $row['last_name'],
            'first_name' => (string) $row['first_name'],
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'full_name' => self::full_name($row),
            'position_title' => (string) ($row['position_title'] ?? ''),
            'work_email' => (string) ($row['work_email'] ?? ''),
            'work_phone' => (string) ($row['work_phone'] ?? ''),
            'employment_status' => (string) $row['employment_status'],
            'employment_status_label' => self::status_label($row['employment_status']),
        ];

        if ($detailed)
        {
            $out += [
                'personnel_number' => (string) ($row['personnel_number'] ?? ''),
                'hired_on' => (string) ($row['hired_on'] ?? ''),
                'terminated_on' => (string) ($row['terminated_on'] ?? ''),
                'source_system' => (string) ($row['source_system'] ?? ''),
                'source_updated_at' => (string) ($row['source_updated_at'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $out;
    }

    protected static function _audit(Prx_Actor $actor, $organization_id, $entity_type, $entity_id, $action, array $details = [])
    {
        $actor_id = $actor->id();
        self::_execute(
            'INSERT INTO prx_300_audit_log (actor_user_id, organization_id, entity_type, entity_id, action, details)
             VALUES ('.($actor_id > 0 ? $actor_id : 'NULL').', '.self::_qi($organization_id).', '
                .self::_q($entity_type).', '.self::_qi($entity_id).', '.self::_q($action).', '.self::_qjson($details).')'
        );
    }

    protected static function _event($name, array $payload, Prx_Actor $actor)
    {
        // Событие содержит только ID и счётчики: подписчик обязан заново
        // прочитать объект через публичный сервис и проверить свои права.
        Event::publish($name, $payload, $actor->id() ?: NULL);
    }

    protected static function _escape_like($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $value));
    }
}
