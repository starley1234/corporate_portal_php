<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Двухэтапный импорт кадровых данных prx300.
 *
 * Этап 1 сохраняет только разрешённые поля CSV в промежуточные строки и
 * валидирует пакет. Этап 2 применяет весь валидный пакет одной транзакцией.
 * Это delta-импорт: отсутствие сотрудника в файле никого не увольняет и не
 * удаляет. Кадровая система остаётся источником истины, а внешний ID
 * сотрудника записывается в prx_300_external_identities, не в Auth.
 */
class Service_Prx300_Import extends Service_Prx300_Store
{
    public static function allowed_headers()
    {
        return [
            'employee_external_id', 'organization_code', 'organization_name', 'organization_legal_name',
            'site_code', 'site_name', 'site_city', 'site_address', 'site_timezone',
            'department_code', 'department_name', 'parent_department_code', 'parent_department_name',
            'personnel_number', 'last_name', 'first_name', 'middle_name', 'position_title',
            'work_email', 'work_phone', 'employment_status', 'hired_on', 'terminated_on',
            'manager_external_id', 'external_login', 'source_updated_at',
        ];
    }

    public static function required_headers()
    {
        return ['employee_external_id', 'organization_code', 'organization_name', 'last_name', 'first_name'];
    }

    public static function template_csv()
    {
        $headers = self::allowed_headers();
        $example = [
            'employee_external_id' => 'HR-0001',
            'organization_code' => 'holding',
            'organization_name' => 'Холдинг',
            'organization_legal_name' => '',
            'site_code' => 'main',
            'site_name' => 'Главная площадка',
            'site_city' => '',
            'site_address' => '',
            'site_timezone' => 'Europe/Samara',
            'department_code' => 'it',
            'department_name' => 'Информационные технологии',
            'parent_department_code' => '',
            'parent_department_name' => '',
            'personnel_number' => '0001',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'middle_name' => '',
            'position_title' => 'Инженер',
            'work_email' => 'ivanov@example.invalid',
            'work_phone' => '',
            'employment_status' => 'active',
            'hired_on' => '2026-01-15',
            'terminated_on' => '',
            'manager_external_id' => '',
            'external_login' => 'ivanov',
            'source_updated_at' => '2026-10-26T12:00:00+04:00',
        ];
        $escape = function ($value) {
            $value = (string) $value;
            return preg_match('/[";\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
        };

        return implode(';', $headers)."\r\n".implode(';', array_map($escape, array_values($example)))."\r\n";
    }

    public static function parse_csv_file($path)
    {
        if ( ! is_file($path) OR ! is_readable($path))
        {
            throw new InvalidArgumentException('Файл импорта не читается');
        }
        $max_rows = (int) self::_config('import.max_rows', 5000);
        $handle = fopen($path, 'rb');
        if ($handle === FALSE)
        {
            throw new InvalidArgumentException('Файл импорта не удалось открыть');
        }

        try
        {
            $first = fgets($handle);
            if ($first === FALSE)
            {
                throw new InvalidArgumentException('Файл импорта пуст');
            }
            $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter, '"', '');
            if ( ! is_array($headers))
            {
                throw new InvalidArgumentException('В файле нет строки заголовков');
            }
            $headers = array_map(function ($header) {
                return mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $header)));
            }, $headers);

            $allowed = self::allowed_headers();
            $unknown = array_values(array_diff($headers, $allowed));
            if ($unknown)
            {
                throw new InvalidArgumentException('Неизвестные столбцы CSV: '.implode(', ', $unknown));
            }
            if (count($headers) !== count(array_unique($headers)))
            {
                throw new InvalidArgumentException('Заголовки CSV повторяются');
            }
            $missing = array_values(array_diff(self::required_headers(), $headers));
            if ($missing)
            {
                throw new InvalidArgumentException('Не хватает обязательных столбцов CSV: '.implode(', ', $missing));
            }

            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== FALSE)
            {
                $line++;
                if ($cells === [NULL] OR trim(implode('', array_map('strval', $cells))) === '')
                {
                    continue;
                }
                if (count($cells) > count($headers))
                {
                    throw new InvalidArgumentException('Строка '.$line.': столбцов больше, чем в заголовке');
                }
                $cells = array_pad($cells, count($headers), '');
                $row = [];
                foreach ($headers as $index => $header)
                {
                    $value = (string) $cells[$index];
                    if ( ! mb_check_encoding($value, 'UTF-8'))
                    {
                        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
                    }
                    $row[$header] = trim($value);
                }
                $row['_row_number'] = $line;
                $rows[] = $row;
                if (count($rows) > $max_rows)
                {
                    throw new InvalidArgumentException('Слишком много строк: максимум '.$max_rows);
                }
            }

            if ( ! $rows)
            {
                throw new InvalidArgumentException('В файле нет строк сотрудников');
            }

            return $rows;
        }
        finally
        {
            fclose($handle);
        }
    }

    public static function stage_upload(Prx_Actor $actor, array $file, $source_system)
    {
        Policy_Prx300_Employee::requireAbility($actor, 'prx300.import', 'Нужна роль hr_admin для импорта кадровых данных');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        {
            throw new InvalidArgumentException('Файл не загрузился. Проверьте размер и повторите отправку');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' OR ! is_uploaded_file($tmp))
        {
            throw new InvalidArgumentException('Загруженный файл недоступен');
        }
        $size = (int) ($file['size'] ?? 0);
        $max_bytes = (int) self::_config('import.max_bytes', 5242880);
        if ($size <= 0 OR $size > $max_bytes)
        {
            throw new InvalidArgumentException('Размер CSV должен быть от 1 байта до '.number_format($max_bytes / 1048576, 1).' МБ');
        }
        $extension = mb_strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ( ! in_array($extension, ['csv', 'txt'], TRUE))
        {
            throw new InvalidArgumentException('Нужен файл .csv или .txt в кодировке UTF-8/Windows-1251');
        }

        $rows = self::parse_csv_file($tmp);

        return self::stage_rows($actor, $source_system, $rows, basename((string) $file['name']), hash_file('sha256', $tmp));
    }

    /** Серверный вход для CLI/тестов и будущей интеграции, не HTTP-контракт. */
    public static function stage_rows(Prx_Actor $actor, $source_system, array $input_rows, $file_name = '', $file_sha256 = '')
    {
        Policy_Prx300_Employee::requireAbility($actor, 'prx300.import', 'Нужна роль hr_admin для импорта кадровых данных');
        $source = self::_code($source_system, 'Код системы-источника');
        $max_rows = (int) self::_config('import.max_rows', 5000);
        if ( ! $input_rows OR count($input_rows) > $max_rows)
        {
            throw new InvalidArgumentException('В пакете должно быть от 1 до '.$max_rows.' строк');
        }

        $rows = [];
        $organization_codes = [];
        $row_numbers = [];
        foreach (array_values($input_rows) as $index => $input)
        {
            $row = self::_normalize_row((array) $input);
            $row_number = (int) ($input['_row_number'] ?? ($index + 2));
            if ($row_number < 2 OR isset($row_numbers[$row_number]))
            {
                throw new InvalidArgumentException('Номера строк пакета должны быть уникальными и начинаться со второй строки');
            }
            $row_numbers[$row_number] = TRUE;
            $row['_row_number'] = $row_number;
            if ($row['organization_code'] !== '')
            {
                $organization_codes[$row['organization_code']] = TRUE;
            }
            $rows[] = $row;
        }
        $organization_codes = array_keys($organization_codes);
        sort($organization_codes);

        if ( ! $actor->isAdmin())
        {
            $scope = Policy_Prx300_Employee::assertOrganization($actor);
            if ($organization_codes !== [(string) $scope['organization_code']])
            {
                throw new DomainException('HR-оператор может импортировать только свою организацию одним пакетом');
            }
        }

        $payload_rows = [];
        foreach ($rows as $row)
        {
            $payload = $row;
            unset($payload['_row_number']);
            $payload_rows[] = $payload;
        }
        $hash = strtolower(trim((string) $file_sha256));
        if ( ! preg_match('/^[a-f0-9]{64}$/D', $hash))
        {
            $hash = hash('sha256', json_encode($payload_rows, JSON_UNESCAPED_UNICODE));
        }

        $existing = self::_row('SELECT * FROM prx_300_import_batches
                                WHERE source_system = :source AND file_sha256 = :hash LIMIT 1',
            [':source' => (string) $source, ':hash' => (string) $hash]);
        if ($existing)
        {
            Policy_Prx300_Employee::assertBatch($actor, $existing);
            return (int) $existing['id'];
        }

        $actor_id = $actor->id();
        return Prx_Db::transaction(function () use ($source, $file_name, $hash, $organization_codes, $rows, $actor_id, $actor) {
            $batch = self::_row('INSERT INTO prx_300_import_batches
                (source_system, file_name, file_sha256, organization_codes, total_rows, created_by_user_id, summary)
                VALUES (:source, :file_name, :hash, :orgcodes::jsonb, '.count($rows)
                    .', '.($actor_id > 0 ? $actor_id : 'NULL').', :staged::jsonb)
                RETURNING id',
                [':source' => (string) $source,
                 ':file_name' => (string) self::_text($file_name, 'Имя файла', 255),
                 ':hash' => (string) $hash,
                 ':orgcodes' => json_encode($organization_codes),
                 ':staged' => json_encode(['staged_at' => date('c')])]);
            $batch_id = (int) $batch['id'];

            foreach ($rows as $row)
            {
                $number = (int) $row['_row_number'];
                unset($row['_row_number']);
                self::_execute('INSERT INTO prx_300_import_rows (batch_id, row_number, payload)
                                VALUES ('.$batch_id.', '.$number.', :payload::jsonb)',
                    [':payload' => json_encode($row)]);
            }

            self::validate_batch($actor, $batch_id);

            return $batch_id;
        });
    }

    public static function validate_batch(Prx_Actor $actor, $batch_id)
    {
        Policy_Prx300_Employee::requireAbility($actor, 'prx300.import', 'Нужна роль hr_admin для импорта кадровых данных');
        $batch = self::_batch_row((int) $batch_id);
        Policy_Prx300_Employee::assertBatch($actor, $batch);
        if ((string) $batch['status'] === 'applied')
        {
            return self::_format_batch($batch);
        }

        $stored = self::_all('SELECT * FROM prx_300_import_rows WHERE batch_id = '.(int) $batch_id.' ORDER BY row_number');
        $rows = [];
        $errors = [];
        $stale = [];
        foreach ($stored as $stored_row)
        {
            $payload = json_decode((string) $stored_row['payload'], TRUE);
            $row = self::_normalize_row(is_array($payload) ? $payload : []);
            $row['_id'] = (int) $stored_row['id'];
            $row['_row_number'] = (int) $stored_row['row_number'];
            $rows[] = $row;
            $errors[(int) $stored_row['id']] = [];
        }

        $add = function ($row, $message) use (&$errors) {
            $errors[(int) $row['_id']][] = $message;
        };
        $check_length = function ($row, $field, $label, $max, $required = FALSE) use ($add) {
            if ($required AND $row[$field] === '')
            {
                $add($row, $label.': обязательно');
            }
            if (mb_strlen($row[$field]) > $max)
            {
                $add($row, $label.': не длиннее '.$max.' символов');
            }
        };
        $check_code = function ($row, $field, $label, $required = FALSE) use ($add) {
            if ($required AND $row[$field] === '')
            {
                $add($row, $label.': обязательно');
            }
            if ($row[$field] !== '' AND ! preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $row[$field]))
            {
                $add($row, $label.': допустимы a-z, 0-9, точка, дефис и подчёркивание');
            }
        };

        foreach ($rows as $row)
        {
            $check_length($row, 'employee_external_id', 'Внешний ID сотрудника', 191, TRUE);
            $check_length($row, 'organization_name', 'Название организации', 255, TRUE);
            $check_length($row, 'last_name', 'Фамилия', 120, TRUE);
            $check_length($row, 'first_name', 'Имя', 120, TRUE);
            $check_length($row, 'middle_name', 'Отчество', 120);
            $check_length($row, 'position_title', 'Должность', 255);
            $check_length($row, 'personnel_number', 'Табельный номер', 64);
            $check_length($row, 'work_phone', 'Рабочий телефон', 64);
            $check_code($row, 'organization_code', 'Код организации', TRUE);
            $check_code($row, 'site_code', 'Код площадки');
            $check_code($row, 'department_code', 'Код подразделения');
            $check_code($row, 'parent_department_code', 'Код родительского подразделения');

            if ($row['parent_department_code'] !== '' AND $row['department_code'] === '')
            {
                $add($row, 'Родительское подразделение указано без подразделения сотрудника');
            }
            if ($row['work_email'] !== '' AND ! filter_var($row['work_email'], FILTER_VALIDATE_EMAIL))
            {
                $add($row, 'Рабочий email выглядит некорректно');
            }
            if ( ! in_array($row['employment_status'], ['active', 'on_leave', 'suspended', 'terminated'], TRUE))
            {
                $add($row, 'Кадровый статус должен быть active, on_leave, suspended или terminated');
            }
            foreach (['hired_on' => 'Дата приёма', 'terminated_on' => 'Дата увольнения'] as $field => $label)
            {
                if ($row[$field] !== '')
                {
                    try { self::_date($row[$field], $label); }
                    catch (InvalidArgumentException $e) { $add($row, $e->getMessage()); }
                }
            }
            if ($row['terminated_on'] !== '' AND $row['employment_status'] !== 'terminated')
            {
                $add($row, 'Дата увольнения допустима только со статусом terminated');
            }
            if ($row['hired_on'] !== '' AND $row['terminated_on'] !== '' AND $row['terminated_on'] < $row['hired_on'])
            {
                $add($row, 'Дата увольнения раньше даты приёма');
            }
            if ($row['source_updated_at'] !== '')
            {
                try { self::_datetime($row['source_updated_at'], 'Время обновления источника'); }
                catch (InvalidArgumentException $e) { $add($row, $e->getMessage()); }
            }
            if ($row['site_timezone'] !== '' AND ! in_array($row['site_timezone'], timezone_identifiers_list(), TRUE))
            {
                $add($row, 'Часовой пояс площадки: укажите имя IANA');
            }
            if ($row['manager_external_id'] !== '' AND $row['manager_external_id'] === $row['employee_external_id'])
            {
                $add($row, 'Сотрудник не может быть руководителем самого себя');
            }
        }

        $duplicate_groups = function ($keyer, $message) use ($rows, $add) {
            $groups = [];
            foreach ($rows as $row)
            {
                $key = $keyer($row);
                if ($key !== '')
                {
                    $groups[$key][] = $row;
                }
            }
            foreach ($groups as $group)
            {
                if (count($group) > 1)
                {
                    foreach ($group as $row) { $add($row, $message); }
                }
            }
        };
        $duplicate_groups(function ($r) { return $r['employee_external_id']; }, 'Внешний ID сотрудника повторяется в файле');
        $duplicate_groups(function ($r) {
            return $r['personnel_number'] !== '' ? $r['organization_code']."\0".mb_strtolower($r['personnel_number']) : '';
        }, 'Табельный номер повторяется в файле');
        $duplicate_groups(function ($r) {
            return $r['work_email'] !== '' ? $r['organization_code']."\0".$r['work_email'] : '';
        }, 'Рабочий email повторяется в файле');
        $duplicate_groups(function ($r) {
            return $r['external_login'] !== '' ? mb_strtolower($r['external_login']) : '';
        }, 'Внешний логин повторяется в файле');

        // Конфликты определений организации/площадки: один код нельзя в одном
        // пакете назвать по-разному, иначе результат зависел бы от порядка строк.
        $definition_conflicts = function ($keyer, array $fields, $label) use ($rows, $add) {
            $groups = [];
            foreach ($rows as $row)
            {
                $key = $keyer($row);
                if ($key !== '')
                {
                    $groups[$key][] = $row;
                }
            }
            foreach ($groups as $group)
            {
                foreach ($fields as $field)
                {
                    $values = [];
                    foreach ($group as $row)
                    {
                        if ($row[$field] !== '') { $values[$row[$field]] = TRUE; }
                    }
                    if (count($values) > 1)
                    {
                        foreach ($group as $row) { $add($row, $label.': противоречивые значения для одного кода'); }
                        break;
                    }
                }
            }
        };
        $definition_conflicts(function ($r) { return $r['organization_code']; },
            ['organization_name', 'organization_legal_name'], 'Организация');
        $definition_conflicts(function ($r) {
            return $r['site_code'] !== '' ? $r['organization_code']."\0".$r['site_code'] : '';
        }, ['site_name', 'site_city', 'site_address', 'site_timezone'], 'Площадка');

        $department_defs = self::_department_definitions($rows);
        foreach ($department_defs as $def)
        {
            if ($def['name_conflict'] OR $def['parent_conflict'])
            {
                foreach ($def['row_ids'] as $row_id)
                {
                    $errors[$row_id][] = 'Подразделение: противоречивое название или родитель для одного кода';
                }
            }
        }
        foreach (self::_department_cycle_keys($department_defs) as $key)
        {
            foreach ($department_defs[$key]['row_ids'] as $row_id)
            {
                $errors[$row_id][] = 'Структура подразделений содержит цикл';
            }
        }
        $manager_cycle_rows = self::_manager_cycle_row_ids($rows);
        foreach ($rows as $row)
        {
            if (isset($manager_cycle_rows[(int) $row['_id']]))
            {
                $add($row, 'Линия руководителей содержит цикл');
            }
        }

        $org_codes = [];
        $external_ids = [];
        $manager_ids = [];
        $logins = [];
        foreach ($rows as $row)
        {
            if ($row['organization_code'] !== '') { $org_codes[$row['organization_code']] = TRUE; }
            if ($row['employee_external_id'] !== '') { $external_ids[$row['employee_external_id']] = TRUE; }
            if ($row['manager_external_id'] !== '') { $manager_ids[$row['manager_external_id']] = TRUE; }
            if ($row['external_login'] !== '') { $logins[$row['external_login']] = TRUE; }
        }
        $org_codes = array_keys($org_codes);
        $org_in = '';
        $org_in_params = [];
        foreach ($org_codes as $i => $org_code)
        {
            $org_in .= ($i ? ', ' : '').':orgc'.$i;
            $org_in_params[':orgc'.$i] = (string) $org_code;
        }

        $orgs = [];
        if ($org_codes)
        {
            foreach (self::_all('SELECT * FROM prx_300_organizations WHERE lower(code) IN ('.$org_in.')', $org_in_params) as $org)
            {
                $orgs[mb_strtolower($org['code'])] = $org;
            }
        }
        $sites = [];
        $departments = [];
        $employees_by_personnel = [];
        $employees_by_email = [];
        if ($org_codes)
        {
            foreach (self::_all('SELECT s.*, lower(o.code) AS org_code FROM prx_300_sites s
                                  JOIN prx_300_organizations o ON o.id = s.organization_id
                                  WHERE lower(o.code) IN ('.$org_in.')', $org_in_params) as $site)
            {
                $sites[$site['org_code']."\0".mb_strtolower($site['code'])] = $site;
            }
            foreach (self::_all('SELECT d.*, lower(o.code) AS org_code FROM prx_300_departments d
                                  JOIN prx_300_organizations o ON o.id = d.organization_id
                                  WHERE lower(o.code) IN ('.$org_in.')', $org_in_params) as $dept)
            {
                $departments[$dept['org_code']."\0".mb_strtolower($dept['code'])] = $dept;
            }
            foreach (self::_all('SELECT e.id, lower(o.code) AS org_code, lower(e.personnel_number) AS personnel_key,
                                        lower(e.work_email) AS email_key
                                  FROM prx_300_employees e JOIN prx_300_organizations o ON o.id = e.organization_id
                                  WHERE lower(o.code) IN ('.$org_in.')', $org_in_params) as $employee)
            {
                if ($employee['personnel_key'] !== '')
                {
                    $employees_by_personnel[$employee['org_code']."\0".$employee['personnel_key']] = (int) $employee['id'];
                }
                if ($employee['email_key'] !== '')
                {
                    $employees_by_email[$employee['org_code']."\0".$employee['email_key']] = (int) $employee['id'];
                }
            }
        }

        $all_external = array_keys($external_ids + $manager_ids);
        $identities = [];
        $identity_logins = [];
        if ($all_external)
        {
            $ext_in = '';
            $ext_params = [];
            foreach ($all_external as $i => $ext)
            {
                $ext_in .= ($i ? ', ' : '').':ext'.$i;
                $ext_params[':ext'.$i] = (string) $ext;
            }
            foreach (self::_all('SELECT x.*, e.source_updated_at AS employee_source_updated_at,
                                        lower(o.code) AS org_code
                                  FROM prx_300_external_identities x
                                  JOIN prx_300_employees e ON e.id = x.employee_id
                                  JOIN prx_300_organizations o ON o.id = e.organization_id
                                  WHERE x.system_code = :system
                                   AND x.external_id IN ('.$ext_in.')',
                [':system' => (string) $batch['source_system']] + $ext_params) as $identity)
            {
                $identities[(string) $identity['external_id']] = $identity;
            }
        }
        if ($logins)
        {
            $login_in = '';
            $login_params = [];
            foreach (array_map('mb_strtolower', array_keys($logins)) as $i => $login)
            {
                $login_in .= ($i ? ', ' : '').':login'.$i;
                $login_params[':login'.$i] = (string) $login;
            }
            foreach (self::_all('SELECT employee_id, lower(external_login) AS login_key
                                  FROM prx_300_external_identities
                                  WHERE system_code = :system
                                   AND lower(external_login) IN ('.$login_in.')
                                  AND status = \'active\'',
                [':system' => (string) $batch['source_system']] + $login_params) as $identity)
            {
                $identity_logins[(string) $identity['login_key']] = (int) $identity['employee_id'];
            }
        }

        $file_employees = [];
        foreach ($rows as $row)
        {
            if ($row['employee_external_id'] !== '')
            {
                $file_employees[$row['employee_external_id']] = $row;
            }
        }

        foreach ($rows as $row)
        {
            $org_code = $row['organization_code'];
            $identity = $identities[$row['employee_external_id']] ?? NULL;
            $existing_employee_id = $identity ? (int) $identity['employee_id'] : NULL;
            if ($identity AND $identity['org_code'] !== $org_code)
            {
                $add($row, 'Внешний ID уже принадлежит сотруднику другой организации; перевод выполняется не delta-импортом');
            }
            if ($row['personnel_number'] !== '')
            {
                $other = $employees_by_personnel[$org_code."\0".mb_strtolower($row['personnel_number'])] ?? NULL;
                if ($other !== NULL AND $other !== $existing_employee_id)
                {
                    $add($row, 'Табельный номер уже принадлежит другому сотруднику');
                }
            }
            if ($row['work_email'] !== '')
            {
                $other = $employees_by_email[$org_code."\0".$row['work_email']] ?? NULL;
                if ($other !== NULL AND $other !== $existing_employee_id)
                {
                    $add($row, 'Рабочий email уже принадлежит другому сотруднику');
                }
            }
            if ($row['external_login'] !== '')
            {
                $other = $identity_logins[mb_strtolower($row['external_login'])] ?? NULL;
                if ($other !== NULL AND $other !== $existing_employee_id)
                {
                    $add($row, 'Внешний логин уже принадлежит другому сотруднику');
                }
            }
            if ($row['site_code'] !== '')
            {
                $key = $org_code."\0".$row['site_code'];
                if ( ! isset($sites[$key]) AND $row['site_name'] === ''
                    AND ! self::_any_row_value($rows, $org_code, 'site_code', $row['site_code'], 'site_name'))
                {
                    $add($row, 'Для новой площадки нужно название');
                }
            }
            if ($row['department_code'] !== '')
            {
                $key = $org_code."\0".$row['department_code'];
                $def = $department_defs[$key] ?? NULL;
                if ( ! isset($departments[$key]) AND ($def === NULL OR $def['name'] === ''))
                {
                    $add($row, 'Для нового подразделения нужно название');
                }
                if ($row['parent_department_code'] !== '')
                {
                    $parent_key = $org_code."\0".$row['parent_department_code'];
                    $parent_def = $department_defs[$parent_key] ?? NULL;
                    if ( ! isset($departments[$parent_key]) AND ($parent_def === NULL OR $parent_def['name'] === ''))
                    {
                        $add($row, 'Родительское подразделение не найдено и не описано в файле');
                    }
                    if ($row['parent_department_code'] === $row['department_code'])
                    {
                        $add($row, 'Подразделение не может быть родителем самого себя');
                    }
                }
            }
            if ($row['manager_external_id'] !== '')
            {
                $manager_external = $row['manager_external_id'];
                if (isset($file_employees[$manager_external]))
                {
                    if ($file_employees[$manager_external]['organization_code'] !== $org_code)
                    {
                        $add($row, 'Руководитель относится к другой организации');
                    }
                }
                elseif (isset($identities[$manager_external]))
                {
                    if ($identities[$manager_external]['org_code'] !== $org_code)
                    {
                        $add($row, 'Руководитель относится к другой организации');
                    }
                }
                else
                {
                    $add($row, 'Руководитель по внешнему ID не найден в файле или платформе');
                }
            }
            if ($identity AND $row['source_updated_at'] !== '' AND ! empty($identity['employee_source_updated_at']))
            {
                $incoming = strtotime($row['source_updated_at']);
                $existing = strtotime((string) $identity['employee_source_updated_at']);
                if ($incoming !== FALSE AND $existing !== FALSE AND $incoming < $existing)
                {
                    $stale[(int) $row['_id']] = TRUE;
                }
            }
        }

        $valid = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($rows as $row)
        {
            $row_id = (int) $row['_id'];
            $messages = array_values(array_unique($errors[$row_id]));
            if ($messages)
            {
                $status = 'error';
                $failed++;
                $message = implode('; ', $messages);
            }
            elseif ( ! empty($stale[$row_id]))
            {
                $status = 'skipped';
                $skipped++;
                $message = 'Пропущено: на платформе уже есть более свежая версия сотрудника';
            }
            else
            {
                $status = 'valid';
                $valid++;
                $message = '';
            }
            self::_execute('UPDATE prx_300_import_rows SET status = :status
                , error = :error WHERE id = '.$row_id,
                [':status' => (string) $status, ':error' => (string) $message]);
        }

        $batch_status = $failed > 0 ? 'rejected' : 'validated';
        $summary = self::_summary($batch);
        $summary['validation'] = [
            'valid' => $valid,
            'errors' => $failed,
            'skipped' => $skipped,
            'validated_at' => date('c'),
        ];
        self::_execute('UPDATE prx_300_import_batches SET status = :batch_status
            , valid_rows = '.$valid.', error_rows = '.$failed.', skipped_count = '.$skipped
            .', summary = :summary::jsonb WHERE id = '.(int) $batch_id,
            [':batch_status' => (string) $batch_status, ':summary' => json_encode($summary)]);

        return self::_format_batch(self::_batch_row((int) $batch_id));
    }

    public static function apply_batch(Prx_Actor $actor, $batch_id)
    {
        Policy_Prx300_Employee::requireAbility($actor, 'prx300.import', 'Нужна роль hr_admin для импорта кадровых данных');
        $batch = self::_batch_row((int) $batch_id);
        Policy_Prx300_Employee::assertBatch($actor, $batch);
        if ((string) $batch['status'] === 'applied')
        {
            return self::_format_batch($batch);
        }

        $validated = self::validate_batch($actor, (int) $batch_id);
        if ($validated['status'] !== 'validated')
        {
            throw new DomainException('Пакет содержит ошибки и не может быть применён');
        }
        $batch = self::_batch_row((int) $batch_id);
        $stored = self::_all('SELECT * FROM prx_300_import_rows WHERE batch_id = '.(int) $batch_id.' ORDER BY row_number');
        $rows = [];
        foreach ($stored as $stored_row)
        {
            if ((string) $stored_row['status'] !== 'valid')
            {
                continue;
            }
            $payload = json_decode((string) $stored_row['payload'], TRUE);
            $row = self::_normalize_row(is_array($payload) ? $payload : []);
            $row['_id'] = (int) $stored_row['id'];
            $rows[] = $row;
        }

        $counts = [
            'organizations_created' => 0, 'organizations_updated' => 0,
            'sites_created' => 0, 'sites_updated' => 0,
            'departments_created' => 0, 'departments_updated' => 0,
            'employees_created' => 0, 'employees_updated' => 0,
            'skipped' => (int) $batch['skipped_count'],
        ];
        try
        {
            $result = Prx_Db::transaction(function () use ($actor, $batch_id, $batch, $counts, $rows) {
                // Двойной клик/параллельный CLI не должен применять пакет дважды:
                // второй процесс подождёт на строке batch и увидит applied.
                $locked_batch = self::_row('SELECT * FROM prx_300_import_batches WHERE id = '.(int) $batch_id.' FOR UPDATE');
                if ((string) $locked_batch['status'] === 'applied')
                {
                    return self::_format_batch($locked_batch);
                }
                if ((string) $locked_batch['status'] !== 'validated')
                {
                    throw new DomainException('Пакет уже обрабатывается или не готов к применению');
                }
                $batch = $locked_batch;

                $organization_ids = [];
                foreach (self::_merged_definitions($rows, 'organization_code', ['organization_name', 'organization_legal_name']) as $code => $def)
                {
                    $existing = self::_row('SELECT * FROM prx_300_organizations WHERE lower(code) = :code',
                        [':code' => (string) $code]);
                    if ($existing)
                    {
                        self::_execute('UPDATE prx_300_organizations SET name = :name
                            , legal_name = :legal_name
                            WHERE id = '.(int) $existing['id'],
                            [':name' => (string) ($def['organization_name'] ?: $existing['name']),
                             ':legal_name' => (string) ($def['organization_legal_name'] ?: $existing['legal_name'])]);
                        $organization_ids[$code] = (int) $existing['id'];
                        $counts['organizations_updated']++;
                    }
                    else
                    {
                        $created = self::_row('INSERT INTO prx_300_organizations (code, name, legal_name)
                                               VALUES (:code, :name, :legal_name) RETURNING id',
                            [':code' => (string) $code, ':name' => (string) $def['organization_name'],
                             ':legal_name' => (string) $def['organization_legal_name']]);
                        $organization_ids[$code] = (int) $created['id'];
                        $counts['organizations_created']++;
                    }
                }

                $site_ids = [];
                foreach ($rows as $row)
                {
                    if ($row['site_code'] === '')
                    {
                        continue;
                    }
                    $key = $row['organization_code']."\0".$row['site_code'];
                    if (isset($site_ids[$key]))
                    {
                        continue;
                    }
                    $def = self::_merged_site_definition($rows, $row['organization_code'], $row['site_code']);
                    $org_id = $organization_ids[$row['organization_code']];
                    $existing = self::_row('SELECT * FROM prx_300_sites WHERE organization_id = '.$org_id.'
                        AND lower(code) = :code',
                        [':code' => (string) $row['site_code']]);
                    if ($existing)
                    {
                        self::_execute('UPDATE prx_300_sites SET name = :name
                            , city = :city, address = :address
                            , timezone = :timezone , status = \'active\'
                            WHERE id = '.(int) $existing['id'],
                            [':name' => (string) ($def['site_name'] ?: $existing['name']),
                             ':city' => (string) $def['site_city'], ':address' => (string) $def['site_address'],
                             ':timezone' => (string) ($def['site_timezone'] ?: $existing['timezone'])]);
                        $site_ids[$key] = (int) $existing['id'];
                        $counts['sites_updated']++;
                    }
                    else
                    {
                        $created = self::_row('INSERT INTO prx_300_sites (organization_id, code, name, city, address, timezone)
                                               VALUES ('.$org_id.', :code, :name, :city, :address, :timezone)
                                               RETURNING id',
                            [':code' => (string) $row['site_code'], ':name' => (string) $def['site_name'],
                             ':city' => (string) $def['site_city'], ':address' => (string) $def['site_address'],
                             ':timezone' => (string) ($def['site_timezone'] ?: 'UTC')]);
                        $site_ids[$key] = (int) $created['id'];
                        $counts['sites_created']++;
                    }
                }

                $department_defs = self::_department_definitions($rows);
                $department_ids = [];
                $pending = $department_defs;
                while ($pending)
                {
                    $progress = FALSE;
                    foreach (array_keys($pending) as $key)
                    {
                        $def = $pending[$key];
                        $org_id = $organization_ids[$def['organization_code']];
                        $parent_id = NULL;
                        $existing = self::_row('SELECT * FROM prx_300_departments WHERE organization_id = '.$org_id.'
                            AND lower(code) = :code',
                            [':code' => (string) $def['code']]);
                        if ($def['parent_specified'])
                        {
                            if ($def['parent_code'] !== '')
                            {
                                $parent_key = $def['organization_code']."\0".$def['parent_code'];
                                if (isset($department_defs[$parent_key]) AND ! isset($department_ids[$parent_key]))
                                {
                                    continue;
                                }
                                if (isset($department_ids[$parent_key]))
                                {
                                    $parent_id = $department_ids[$parent_key];
                                }
                                else
                                {
                                    $parent = self::_row('SELECT id FROM prx_300_departments WHERE organization_id = '.$org_id.'
                                        AND lower(code) = :code',
                                        [':code' => (string) $def['parent_code']]);
                                    $parent_id = $parent ? (int) $parent['id'] : NULL;
                                }
                            }
                        }
                        elseif ($existing)
                        {
                            $parent_id = isset($existing['parent_id']) ? (int) $existing['parent_id'] ?: NULL : NULL;
                        }

                        if ($existing)
                        {
                            self::_execute('UPDATE prx_300_departments SET parent_id = '.self::_qi($parent_id)
                                .', name = :name , status = \'active\'
                                WHERE id = '.(int) $existing['id'],
                                [':name' => (string) ($def['name'] ?: $existing['name'])]);
                            $department_ids[$key] = (int) $existing['id'];
                            $counts['departments_updated']++;
                        }
                        else
                        {
                            $created = self::_row('INSERT INTO prx_300_departments (organization_id, parent_id, code, name)
                                                   VALUES ('.$org_id.', '.self::_qi($parent_id).', :code, :name)
                                                   RETURNING id',
                                [':code' => (string) $def['code'], ':name' => (string) $def['name']]);
                            $department_ids[$key] = (int) $created['id'];
                            $counts['departments_created']++;
                        }
                        unset($pending[$key]);
                        $progress = TRUE;
                    }
                    if ( ! $progress)
                    {
                        throw new DomainException('Структура подразделений содержит цикл');
                    }
                }

                $existing_by_external = [];
                $external_values = [];
                foreach ($rows as $row)
                {
                    $external_values[$row['employee_external_id']] = TRUE;
                    if ($row['manager_external_id'] !== '')
                    {
                        $external_values[$row['manager_external_id']] = TRUE;
                    }
                }
                if ($external_values)
                {
                    $extv_in = '';
                    $extv_params = [];
                    foreach (array_keys($external_values) as $i => $extv)
                    {
                        $extv_in .= ($i ? ', ' : '').':extv'.$i;
                        $extv_params[':extv'.$i] = (string) $extv;
                    }
                    foreach (self::_all('SELECT external_id, employee_id FROM prx_300_external_identities
                                          WHERE system_code = :system
                                           AND external_id IN ('.$extv_in.')',
                        [':system' => (string) $batch['source_system']] + $extv_params) as $identity)
                    {
                        $existing_by_external[(string) $identity['external_id']] = (int) $identity['employee_id'];
                    }
                }

                $employee_by_external = $existing_by_external;
                $result_by_row = [];
                foreach ($rows as $row)
                {
                    $org_id = $organization_ids[$row['organization_code']];
                    $site_id = $row['site_code'] !== '' ? $site_ids[$row['organization_code']."\0".$row['site_code']] : NULL;
                    $department_id = $row['department_code'] !== ''
                        ? $department_ids[$row['organization_code']."\0".$row['department_code']] : NULL;
                    $existing_id = $existing_by_external[$row['employee_external_id']] ?? NULL;
                    // Отсутствие времени источника не превращаем в now(): иначе
                    // следующая корректная выгрузка с реальным HR-временем будет
                    // ошибочно сочтена устаревшей. Для существующей записи
                    // сохраняем прежнее время, для новой оставляем NULL.
                    $source_updated_sql = $row['source_updated_at'] !== ''
                        ? ':source_updated_at'
                        : ($existing_id ? 'source_updated_at' : 'NULL');
                    $source_params = $source_updated_sql === ':source_updated_at'
                        ? [':source_updated_at' => self::_datetime($row['source_updated_at'], 'Время обновления источника')]
                        : [];

                    if ($existing_id)
                    {
                        self::_execute('UPDATE prx_300_employees SET organization_id = '.$org_id
                            .', department_id = '.self::_qi($department_id).', site_id = '.self::_qi($site_id)
                            .', manager_employee_id = NULL, personnel_number = :personnel_number
                            , last_name = :last_name, first_name = :first_name
                            , middle_name = :middle_name, position_title = :position_title
                            , work_email = :work_email, work_phone = :work_phone
                            , employment_status = :status, hired_on = :hired
                            , terminated_on = :terminated, source_system = :source_system
                            , source_updated_at = '.$source_updated_sql.' WHERE id = '.(int) $existing_id,
                            [':personnel_number' => (string) $row['personnel_number'],
                             ':last_name' => (string) $row['last_name'],
                             ':first_name' => (string) $row['first_name'],
                             ':middle_name' => (string) $row['middle_name'],
                             ':position_title' => (string) $row['position_title'],
                             ':work_email' => (string) $row['work_email'],
                             ':work_phone' => (string) $row['work_phone'],
                             ':status' => (string) $row['employment_status'],
                             ':hired' => self::_pstr($row['hired_on']),
                             ':terminated' => self::_pstr($row['terminated_on']),
                             ':source_system' => (string) $batch['source_system']] + $source_params);
                        $employee_id = (int) $existing_id;
                        $counts['employees_updated']++;
                    }
                    else
                    {
                        $created = self::_row('INSERT INTO prx_300_employees
                            (organization_id, department_id, site_id, personnel_number, last_name, first_name, middle_name,
                             position_title, work_email, work_phone, employment_status, hired_on, terminated_on,
                             source_system, source_updated_at)
                            VALUES ('.$org_id.', '.self::_qi($department_id).', '.self::_qi($site_id).', '
                                .':personnel_number, :last_name, :first_name, :middle_name, :position_title, :work_email, '
                                .':work_phone, :status, :hired, :terminated, :source_system, '.$source_updated_sql.')
                            RETURNING id',
                            [':personnel_number' => (string) $row['personnel_number'],
                             ':last_name' => (string) $row['last_name'],
                             ':first_name' => (string) $row['first_name'],
                             ':middle_name' => (string) $row['middle_name'],
                             ':position_title' => (string) $row['position_title'],
                             ':work_email' => (string) $row['work_email'],
                             ':work_phone' => (string) $row['work_phone'],
                             ':status' => (string) $row['employment_status'],
                             ':hired' => self::_pstr($row['hired_on']),
                             ':terminated' => self::_pstr($row['terminated_on']),
                             ':source_system' => (string) $batch['source_system']] + $source_params);
                        $employee_id = (int) $created['id'];
                        $counts['employees_created']++;
                    }
                    $employee_by_external[$row['employee_external_id']] = $employee_id;
                    $result_by_row[(int) $row['_id']] = $employee_id;

                    self::_execute('UPDATE prx_300_external_identities SET is_primary = FALSE
                                    WHERE employee_id = '.$employee_id.' AND system_code = :system
                                     AND external_id <> :external_id',
                        [':system' => (string) $batch['source_system'],
                         ':external_id' => (string) $row['employee_external_id']]);
                    $identity = self::_row('SELECT * FROM prx_300_external_identities
                                            WHERE system_code = :system
                                             AND external_id = :external_id',
                        [':system' => (string) $batch['source_system'],
                         ':external_id' => (string) $row['employee_external_id']]);
                    if ($identity)
                    {
                        if ((int) $identity['employee_id'] !== $employee_id)
                        {
                            throw new DomainException('Внешний ID уже принадлежит другому сотруднику');
                        }
                        self::_execute('UPDATE prx_300_external_identities SET external_login = :external_login
                            , is_primary = TRUE, status = \'active\', source_updated_at = '.$source_updated_sql.'
                            WHERE id = '.(int) $identity['id'],
                            [':external_login' => (string) $row['external_login']] + $source_params);
                    }
                    else
                    {
                        self::_execute('INSERT INTO prx_300_external_identities
                            (employee_id, system_code, external_id, external_login, is_primary, source_updated_at)
                            VALUES ('.$employee_id.', :system, :external_id, :external_login, TRUE, '
                            .$source_updated_sql.')',
                            [':system' => (string) $batch['source_system'],
                             ':external_id' => (string) $row['employee_external_id'],
                             ':external_login' => (string) $row['external_login']] + $source_params);
                    }
                }

                foreach ($rows as $row)
                {
                    $employee_id = $employee_by_external[$row['employee_external_id']];
                    $manager_id = $row['manager_external_id'] !== ''
                        ? ($employee_by_external[$row['manager_external_id']] ?? NULL) : NULL;
                    if ($row['manager_external_id'] !== '' AND ! $manager_id)
                    {
                        throw new DomainException('Руководитель не найден после первого прохода импорта');
                    }
                    self::_execute('UPDATE prx_300_employees SET manager_employee_id = '.self::_qi($manager_id)
                        .' WHERE id = '.$employee_id);
                }

                self::_assert_no_cycles(array_values($organization_ids));

                foreach ($result_by_row as $row_id => $employee_id)
                {
                    self::_execute("UPDATE prx_300_import_rows SET status = 'applied', error = '', result_employee_id = "
                        .(int) $employee_id.' WHERE id = '.(int) $row_id);
                }

                $summary = self::_summary($batch);
                $summary['applied'] = $counts + ['applied_at' => date('c')];
                $actor_id = $actor->id();
            self::_execute('UPDATE prx_300_import_batches SET status = \'applied\', created_count = '
                .(int) $counts['employees_created'].', updated_count = '.(int) $counts['employees_updated']
                .', skipped_count = '.(int) $counts['skipped'].', applied_by_user_id = '
                .($actor_id > 0 ? $actor_id : 'NULL').', applied_at = now(), summary = :summary::jsonb'
                .' WHERE id = '.(int) $batch_id,
                [':summary' => json_encode($summary)]);
                $audit_org = count($organization_ids) === 1 ? reset($organization_ids) : NULL;
                self::_audit($actor, $audit_org, 'import_batch', (int) $batch_id, 'import.applied', $counts + [
                    'source_system' => (string) $batch['source_system'],
                ]);

                return NULL;
            });
        }
        catch (Throwable $e)
        {
            $summary = self::_summary($batch);
            $summary['apply_error'] = 'apply_failed';
            self::_execute("UPDATE prx_300_import_batches SET status = 'failed', summary = :summary::jsonb
                WHERE id = ".(int) $batch_id,
                [':summary' => json_encode($summary)]);
            Kohana::$log->add(Log::WARNING, 'prx300: импорт не применён: :class', [':class' => get_class($e)]);
            throw new DomainException('Не удалось применить пакет: транзакция отменена без частичных изменений');
        }
        if ($result !== NULL)
        {
            return $result;
        }

        $applied = self::_format_batch(self::_batch_row((int) $batch_id));
        self::_event('prx300.import.applied', [
            'batch_id' => (int) $batch_id,
            'source_system' => (string) $batch['source_system'],
            'employees_created' => (int) $counts['employees_created'],
            'employees_updated' => (int) $counts['employees_updated'],
            'skipped' => (int) $counts['skipped'],
        ], $actor);

        return $applied;
    }

    public static function batches(Prx_Actor $actor, $limit = 30)
    {
        Policy_Prx300_Employee::requireImportRead($actor);
        $rows = self::_all('SELECT * FROM prx_300_import_batches ORDER BY created_at DESC LIMIT '
            .max(1, min(100, (int) $limit)));
        $out = [];
        foreach ($rows as $row)
        {
            if (Policy_Prx300_Employee::batchInScope($actor, $row))
            {
                $out[] = self::_format_batch($row);
            }
        }

        return $out;
    }

    public static function batch(Prx_Actor $actor, $batch_id, $include_rows = TRUE)
    {
        Policy_Prx300_Employee::requireImportRead($actor);
        $batch = self::_batch_row((int) $batch_id);
        Policy_Prx300_Employee::assertBatch($actor, $batch);
        $out = self::_format_batch($batch);
        if ($include_rows)
        {
            $out['rows'] = [];
            foreach (self::_all('SELECT * FROM prx_300_import_rows WHERE batch_id = '.(int) $batch_id.' ORDER BY row_number') as $row)
            {
                $payload = json_decode((string) $row['payload'], TRUE);
                $out['rows'][] = [
                    'id' => (int) $row['id'],
                    'row_number' => (int) $row['row_number'],
                    'payload' => is_array($payload) ? $payload : [],
                    'status' => (string) $row['status'],
                    'error' => (string) $row['error'],
                    'result_employee_id' => isset($row['result_employee_id']) ? (int) $row['result_employee_id'] ?: NULL : NULL,
                ];
            }
        }

        return $out;
    }

    /**
     * Финальный инвариант внутри транзакции: delta-строка вместе с уже
     * существующим графом не должна замыкать подразделения или руководителей.
     * Проверка внутри файла этого не видит, если вторая половина цепочки
     * была импортирована раньше.
     */
    protected static function _assert_no_cycles(array $organization_ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $organization_ids))));
        if ( ! $ids)
        {
            return;
        }
        $in = implode(', ', $ids);
        $checks = [
            ['prx_300_departments', 'parent_id', 'Структура подразделений содержит цикл'],
            ['prx_300_employees', 'manager_employee_id', 'Линия руководителей содержит цикл'],
        ];
        foreach ($checks as [$table, $parent_column, $message])
        {
            $row = self::_row('WITH RECURSIVE walk(id, parent_id, path, is_cycle) AS (
                    SELECT id, '.$parent_column.', ARRAY[id], FALSE
                    FROM '.$table.' WHERE organization_id IN ('.$in.')
                    UNION ALL
                    SELECT parent.id, parent.'.$parent_column.', walk.path || parent.id,
                           parent.id = ANY(walk.path)
                    FROM '.$table.' parent
                    JOIN walk ON walk.parent_id = parent.id
                    WHERE NOT walk.is_cycle AND walk.parent_id IS NOT NULL
                ) SELECT count(*) AS c FROM walk WHERE is_cycle');
            if ((int) ($row['c'] ?? 0) > 0)
            {
                throw new DomainException($message);
            }
        }
    }

    protected static function _normalize_row(array $input)
    {
        $row = [];
        foreach (self::allowed_headers() as $field)
        {
            $row[$field] = trim((string) ($input[$field] ?? ''));
        }
        foreach (['organization_code', 'site_code', 'department_code', 'parent_department_code'] as $field)
        {
            $row[$field] = mb_strtolower($row[$field]);
        }
        $row['work_email'] = mb_strtolower($row['work_email']);
        $row['employment_status'] = mb_strtolower($row['employment_status'] ?: 'active');

        return $row;
    }

    protected static function _batch_row($batch_id)
    {
        $row = self::_row('SELECT * FROM prx_300_import_batches WHERE id = '.(int) $batch_id);
        if ( ! $row)
        {
            throw new DomainException('Пакет импорта не найден');
        }

        return $row;
    }

    protected static function _summary(array $batch)
    {
        $summary = json_decode((string) ($batch['summary'] ?? ''), TRUE);

        return is_array($summary) ? $summary : [];
    }

    protected static function _format_batch(array $row)
    {
        $codes = json_decode((string) ($row['organization_codes'] ?? '[]'), TRUE);

        return [
            'id' => (int) $row['id'],
            'source_system' => (string) $row['source_system'],
            'file_name' => (string) $row['file_name'],
            'file_sha256' => (string) $row['file_sha256'],
            'mode' => (string) $row['mode'],
            'status' => (string) $row['status'],
            'organization_codes' => is_array($codes) ? $codes : [],
            'total_rows' => (int) $row['total_rows'],
            'valid_rows' => (int) $row['valid_rows'],
            'error_rows' => (int) $row['error_rows'],
            'created_count' => (int) $row['created_count'],
            'updated_count' => (int) $row['updated_count'],
            'skipped_count' => (int) $row['skipped_count'],
            'summary' => self::_summary($row),
            'created_at' => (string) $row['created_at'],
            'applied_at' => (string) ($row['applied_at'] ?? ''),
        ];
    }

    protected static function _any_row_value(array $rows, $org_code, $code_field, $code, $value_field)
    {
        foreach ($rows as $row)
        {
            if ($row['organization_code'] === $org_code AND $row[$code_field] === $code AND $row[$value_field] !== '')
            {
                return TRUE;
            }
        }

        return FALSE;
    }

    protected static function _department_definitions(array $rows)
    {
        $defs = [];
        $add = function ($org, $code, $name, $parent, $parent_specified, $row_id) use (&$defs) {
            if ($org === '' OR $code === '')
            {
                return;
            }
            $key = $org."\0".$code;
            if ( ! isset($defs[$key]))
            {
                $defs[$key] = [
                    'organization_code' => $org,
                    'code' => $code,
                    'name' => '',
                    'names' => [],
                    'parent_code' => '',
                    'parents' => [],
                    'parent_specified' => FALSE,
                    'name_conflict' => FALSE,
                    'parent_conflict' => FALSE,
                    'row_ids' => [],
                ];
            }
            $defs[$key]['row_ids'][(int) $row_id] = TRUE;
            if ($name !== '')
            {
                $defs[$key]['names'][$name] = TRUE;
                $defs[$key]['name'] = $name;
            }
            if ($parent_specified)
            {
                $defs[$key]['parents'][$parent] = TRUE;
                $defs[$key]['parent_code'] = $parent;
                $defs[$key]['parent_specified'] = TRUE;
            }
        };

        foreach ($rows as $row)
        {
            $add($row['organization_code'], $row['department_code'], $row['department_name'],
                $row['parent_department_code'], TRUE, $row['_id'] ?? 0);
            $add($row['organization_code'], $row['parent_department_code'], $row['parent_department_name'],
                '', FALSE, $row['_id'] ?? 0);
        }

        foreach ($defs as &$def)
        {
            $def['name_conflict'] = count($def['names']) > 1;
            $def['parent_conflict'] = count($def['parents']) > 1;
            $def['row_ids'] = array_keys($def['row_ids']);
            unset($def['names'], $def['parents']);
        }
        unset($def);

        return $defs;
    }

    protected static function _department_cycle_keys(array $defs)
    {
        $state = [];
        $cycles = [];
        $visit = function ($key, $stack) use (&$visit, &$state, &$cycles, $defs) {
            $state[$key] = 1;
            $stack[] = $key;
            $def = $defs[$key];
            if ($def['parent_specified'] AND $def['parent_code'] !== '')
            {
                $parent_key = $def['organization_code']."\0".$def['parent_code'];
                if (isset($defs[$parent_key]))
                {
                    if (($state[$parent_key] ?? 0) === 1)
                    {
                        $start = array_search($parent_key, $stack, TRUE);
                        foreach (array_slice($stack, $start === FALSE ? 0 : $start) as $cycle_key)
                        {
                            $cycles[$cycle_key] = TRUE;
                        }
                    }
                    elseif (($state[$parent_key] ?? 0) === 0)
                    {
                        $visit($parent_key, $stack);
                    }
                }
            }
            $state[$key] = 2;
        };
        foreach (array_keys($defs) as $key)
        {
            if (($state[$key] ?? 0) === 0)
            {
                $visit($key, []);
            }
        }

        return array_keys($cycles);
    }

    protected static function _manager_cycle_row_ids(array $rows)
    {
        $by_external = [];
        foreach ($rows as $row)
        {
            if ($row['employee_external_id'] !== '')
            {
                $by_external[$row['employee_external_id']] = $row;
            }
        }
        $state = [];
        $cycles = [];
        $visit = function ($external, $stack) use (&$visit, &$state, &$cycles, $by_external) {
            $state[$external] = 1;
            $stack[] = $external;
            $manager = (string) ($by_external[$external]['manager_external_id'] ?? '');
            if ($manager !== '' AND isset($by_external[$manager]))
            {
                if (($state[$manager] ?? 0) === 1)
                {
                    $start = array_search($manager, $stack, TRUE);
                    foreach (array_slice($stack, $start === FALSE ? 0 : $start) as $cycle_external)
                    {
                        $cycles[(int) $by_external[$cycle_external]['_id']] = TRUE;
                    }
                }
                elseif (($state[$manager] ?? 0) === 0)
                {
                    $visit($manager, $stack);
                }
            }
            $state[$external] = 2;
        };
        foreach (array_keys($by_external) as $external)
        {
            if (($state[$external] ?? 0) === 0)
            {
                $visit($external, []);
            }
        }

        return $cycles;
    }

    protected static function _merged_definitions(array $rows, $key_field, array $fields)
    {
        $defs = [];
        foreach ($rows as $row)
        {
            $key = $row[$key_field];
            if ( ! isset($defs[$key]))
            {
                $defs[$key] = array_fill_keys($fields, '');
            }
            foreach ($fields as $field)
            {
                if ($row[$field] !== '')
                {
                    $defs[$key][$field] = $row[$field];
                }
            }
        }

        return $defs;
    }

    protected static function _merged_site_definition(array $rows, $org_code, $site_code)
    {
        $def = ['site_name' => '', 'site_city' => '', 'site_address' => '', 'site_timezone' => ''];
        foreach ($rows as $row)
        {
            if ($row['organization_code'] !== $org_code OR $row['site_code'] !== $site_code)
            {
                continue;
            }
            foreach (array_keys($def) as $field)
            {
                if ($row[$field] !== '')
                {
                    $def[$field] = $row[$field];
                }
            }
        }

        return $def;
    }
}
