<?php
use PHPUnit\Framework\TestCase;

/** Интеграционные контракты prx300 на мигрированной PostgreSQL. */
class EmployeeDirectoryTest extends TestCase
{
    protected $suffix = '';
    protected $source = '';
    protected $org_codes = [];
    protected $user_ids = [];

    protected function setUp(): void
    {
        $this->suffix = bin2hex(random_bytes(4));
        $this->source = 'test300'.$this->suffix;
        $this->org_codes = [];
        $this->user_ids = [];
    }

    protected function tearDown(): void
    {
        $db = Database::instance();
        try
        {
            if ($this->org_codes)
            {
                $codes = implode(', ', array_map(function ($code) use ($db) {
                    return $db->quote($code);
                }, $this->org_codes));
                $orgs = 'SELECT id FROM prx_300_organizations WHERE code IN ('.$codes.')';
                $db->query(NULL, 'DELETE FROM prx_300_audit_log WHERE organization_id IN ('.$orgs.')');
                $db->query(NULL, 'UPDATE prx_300_departments SET manager_employee_id = NULL, parent_id = NULL WHERE organization_id IN ('.$orgs.')');
                $db->query(NULL, 'UPDATE prx_300_employees SET manager_employee_id = NULL WHERE organization_id IN ('.$orgs.')');
                $db->query(NULL, 'DELETE FROM prx_300_employees WHERE organization_id IN ('.$orgs.')');
                $db->query(NULL, 'DELETE FROM prx_300_departments WHERE organization_id IN ('.$orgs.')');
                $db->query(NULL, 'DELETE FROM prx_300_sites WHERE organization_id IN ('.$orgs.')');
                $db->query(NULL, 'DELETE FROM prx_300_organizations WHERE id IN ('.$orgs.')');
            }
            if ($this->source !== '')
            {
                $db->query(NULL, 'DELETE FROM prx_300_import_batches WHERE source_system = '.$db->quote($this->source));
            }
        }
        finally
        {
            foreach ($this->user_ids as $user_id)
            {
                $user = ORM::factory('User', (int) $user_id);
                if ($user->loaded())
                {
                    $user->delete();
                }
            }
        }
    }

    public function test_import_creates_structure_identity_and_directory_scope(): void
    {
        $seed = $this->_seed('t300a'.$this->suffix, 'A');
        $manager_id = Service_Prx300_Identity::employee_id_for_external($this->source, 'A-MGR-'.$this->suffix);
        $employee_id = Service_Prx300_Identity::employee_id_for_external($this->source, 'A-EMP-'.$this->suffix);
        $this->assertNotNull($manager_id);
        $this->assertNotNull($employee_id);
        $this->assertGreaterThan(0, $employee_id);

        $detail = Service_Prx300_Admin::employee_detail($this->_global_actor(), $employee_id);
        $this->assertSame($manager_id, $detail['manager_employee_id']);
        $this->assertSame('it'.$this->suffix, $detail['department_code']);
        $this->assertSame('Инженер', $detail['position_title']);

        $structure = Service_Prx300_Admin::form_options($this->_global_actor());
        $this->assertGreaterThan(0, count($structure['sites']));
        $this->assertGreaterThan(0, count($structure['departments']));
        $dashboard = Service_Prx300_Admin::dashboard($this->_global_actor());
        $this->assertGreaterThan(1, $dashboard['employees']);

        $identity = Service_Prx300_Identity::external_identity($employee_id, $this->source);
        $this->assertSame('A-EMP-'.$this->suffix, $identity['external_id']);
        $this->assertTrue($identity['is_primary']);

        $user = $this->_make_user('a');
        $this->assertNull(Service_Prx300_Directory::context_for_user((int) $user->id));
        Service_Prx300_Identity::link_user($this->_global_actor(), $employee_id, (string) $user->username, 'test');
        $context = Service_Prx300_Directory::context_for_user((int) $user->id);
        $this->assertSame($employee_id, $context['employee_id']);
        $this->assertSame($detail['organization_id'], $context['organization_id']);

        $actor = $this->_actor((int) $user->id, ['login'], ['can_directory' => TRUE]);
        $directory = Service_Prx300_Directory::directory($actor, ['q' => 'Сотрудник']);
        $this->assertSame(1, $directory['total']);
        $this->assertArrayNotHasKey('personnel_number', $directory['rows'][0]);
        $profile = Service_Prx300_Directory::profile_for_user((int) $user->id);
        $this->assertSame('A-002', $profile['personnel_number']);

        // Повтор того же содержимого возвращает применённый пакет и не плодит сотрудников.
        $again = Service_Prx300_Import::stage_rows($this->_global_actor(), $this->source, $seed['rows'], 'again.csv');
        $this->assertSame($seed['batch_id'], $again);
        $batch = Service_Prx300_Import::apply_batch($this->_global_actor(), $again);
        $this->assertSame('applied', $batch['status']);
        $this->assertSame(2, $batch['created_count']);

        // Пустое source_updated_at не должно превращаться в now() и делать
        // следующую выгрузку с реальным HR-временем «устаревшей».
        $without_time = $seed['rows'][1];
        $without_time['source_updated_at'] = '';
        $without_time['position_title'] = 'Старший инженер';
        $batch_id = Service_Prx300_Import::stage_rows($this->_global_actor(), $this->source, [$without_time], 'no-time.csv');
        Service_Prx300_Import::apply_batch($this->_global_actor(), $batch_id);
        $with_time = $without_time;
        $with_time['source_updated_at'] = '2026-10-26T13:00:00+00:00';
        $with_time['position_title'] = 'Ведущий инженер';
        $batch_id = Service_Prx300_Import::stage_rows($this->_global_actor(), $this->source, [$with_time], 'with-time.csv');
        Service_Prx300_Import::apply_batch($this->_global_actor(), $batch_id);
        $detail = Service_Prx300_Admin::employee_detail($this->_global_actor(), $employee_id);
        $this->assertSame('Ведущий инженер', $detail['position_title']);

        // Отключённый вручную внешний ID остаётся якорем того же сотрудника:
        // повторная HR-выгрузка реактивирует его, а не создаёт дубль.
        $identities = Service_Prx300_Identity::identities_for_employee($this->_global_actor(), $employee_id);
        Service_Prx300_Identity::disable_identity($this->_global_actor(), $identities[0]['id']);
        $this->assertNull(Service_Prx300_Identity::employee_id_for_external($this->source, 'A-EMP-'.$this->suffix));
        $reactivating = $with_time;
        $reactivating['source_updated_at'] = '2026-10-26T14:00:00+00:00';
        $reactivating['position_title'] = 'Главный инженер';
        $batch_id = Service_Prx300_Import::stage_rows($this->_global_actor(), $this->source, [$reactivating], 'reactivate.csv');
        Service_Prx300_Import::apply_batch($this->_global_actor(), $batch_id);
        $this->assertSame($employee_id, Service_Prx300_Identity::employee_id_for_external($this->source, 'A-EMP-'.$this->suffix));
        $detail = Service_Prx300_Admin::employee_detail($this->_global_actor(), $employee_id);
        $this->assertSame('Главный инженер', $detail['position_title']);
        $this->_assert_throws(DomainException::class, function () use ($employee_id) {
            Service_Prx300_Identity::save_identity($this->_global_actor(), $employee_id, [
                'system_code' => $this->source,
                'external_id' => 'A-EMP-'.$this->suffix,
                'status' => 'active',
            ]);
        });
    }

    public function test_invalid_import_is_rejected_without_partial_apply(): void
    {
        $org = 't300bad'.$this->suffix;
        $this->org_codes[] = $org;
        $row = $this->_row($org, 'BAD-'.$this->suffix, [
            'manager_external_id' => 'UNKNOWN-'.$this->suffix,
        ]);
        $batch_id = Service_Prx300_Import::stage_rows($this->_global_actor(), $this->source, [$row], 'bad.csv');
        $batch = Service_Prx300_Import::batch($this->_global_actor(), $batch_id, FALSE);
        $this->assertSame('rejected', $batch['status']);
        $this->assertSame(1, $batch['error_rows']);
        $this->assertNull(Service_Prx300_Identity::employee_id_for_external($this->source, 'BAD-'.$this->suffix));
        $this->_assert_throws(DomainException::class, function () use ($batch_id) {
            Service_Prx300_Import::apply_batch($this->_global_actor(), $batch_id);
        });
    }

    public function test_delta_import_rolls_back_cycles_with_existing_graph(): void
    {
        $this->_seed('t300a'.$this->suffix, 'A');
        $manager_id = Service_Prx300_Identity::employee_id_for_external($this->source, 'A-MGR-'.$this->suffix);

        $manager_cycle = $this->_row('t300a'.$this->suffix, 'A-MGR-'.$this->suffix, [
            'personnel_number' => 'A-001',
            'last_name' => 'Руководитель',
            'first_name' => 'Тест',
            'position_title' => 'Руководитель направления',
            'department_code' => 'management'.$this->suffix,
            'department_name' => 'Управление',
            'manager_external_id' => 'A-EMP-'.$this->suffix,
            'source_updated_at' => '2026-10-27T12:00:00+00:00',
        ]);
        $batch_id = Service_Prx300_Import::stage_rows($this->_global_actor(), $this->source, [$manager_cycle], 'cycle.csv');
        $this->_assert_throws(DomainException::class, function () use ($batch_id) {
            Service_Prx300_Import::apply_batch($this->_global_actor(), $batch_id);
        });
        $batch = Service_Prx300_Import::batch($this->_global_actor(), $batch_id, FALSE);
        $this->assertSame('failed', $batch['status']);
        $detail = Service_Prx300_Admin::employee_detail($this->_global_actor(), $manager_id);
        $this->assertNull($detail['manager_employee_id']);
    }

    public function test_hr_scope_blocks_other_organization_and_manager_cycles(): void
    {
        $a = $this->_seed('t300a'.$this->suffix, 'A');
        $b = $this->_seed('t300b'.$this->suffix, 'B');
        $employee_a = Service_Prx300_Identity::employee_id_for_external($this->source, 'A-EMP-'.$this->suffix);
        $employee_b = Service_Prx300_Identity::employee_id_for_external($this->source, 'B-EMP-'.$this->suffix);
        $manager_a = Service_Prx300_Identity::employee_id_for_external($this->source, 'A-MGR-'.$this->suffix);

        $user = $this->_make_user('hr');
        Service_Prx300_Identity::link_user($this->_global_actor(), $employee_a, (string) $user->email, 'test');
        $hr = $this->_actor((int) $user->id, ['login', 'hr_admin'], [
            'can_admin_view' => TRUE,
            'can_manage' => TRUE,
            'can_import' => TRUE,
            'can_identity' => TRUE,
            'can_directory' => TRUE,
        ]);

        $this->assertNull(Service_Prx300_Directory::colleague_card($hr, $employee_b));
        $employees = Service_Prx300_Admin::employees($hr, []);
        $this->assertSame(2, $employees['total']);
        $detail_b = Service_Prx300_Admin::employee_detail($this->_global_actor(), $employee_b);
        $this->_assert_throws(DomainException::class, function () use ($hr, $detail_b) {
            Service_Prx300_Admin::save_site($hr, [
                'organization_id' => $detail_b['organization_id'],
                'code' => 'foreign'.$this->suffix,
                'name' => 'Чужая площадка',
                'timezone' => 'UTC',
                'status' => 'active',
            ]);
        });

        $employee_detail = Service_Prx300_Admin::employee_detail($this->_global_actor(), $employee_a);
        $this->_assert_throws(DomainException::class, function () use ($employee_detail, $detail_b) {
            Service_Prx300_Admin::save_site($this->_global_actor(), [
                'organization_id' => $detail_b['organization_id'],
                'code' => 'moved'.$this->suffix,
                'name' => 'Перенесённая площадка',
                'timezone' => 'UTC',
                'status' => 'active',
            ], $employee_detail['site_id']);
        });

        $manager_detail = Service_Prx300_Admin::employee_detail($this->_global_actor(), $manager_a);
        $manager_detail['manager_employee_id'] = $employee_a;
        $this->_assert_throws(DomainException::class, function () use ($manager_detail, $manager_a) {
            Service_Prx300_Admin::save_employee($this->_global_actor(), $manager_detail, $manager_a);
        });

        $unlinked = $this->_make_user('free');
        $regular = $this->_actor((int) $unlinked->id, ['login'], ['can_directory' => TRUE]);
        $this->_assert_throws(DomainException::class, function () use ($regular) {
            Service_Prx300_Directory::directory($regular, []);
        });
    }

    public function test_csv_parser_accepts_template_and_rejects_unknown_columns(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'prx300csv');
        try
        {
            file_put_contents($path, "\xEF\xBB\xBF".Service_Prx300_Import::template_csv());
            $rows = Service_Prx300_Import::parse_csv_file($path);
            $this->assertCount(1, $rows);
            $this->assertSame('HR-0001', $rows[0]['employee_external_id']);

            file_put_contents($path, "employee_external_id;passport_number\nE1;secret\n");
            $this->_assert_throws(InvalidArgumentException::class, function () use ($path) {
                Service_Prx300_Import::parse_csv_file($path);
            });
        }
        finally
        {
            if (is_file($path))
            {
                unlink($path);
            }
        }
    }

    protected function _seed($org_code, $prefix)
    {
        $this->org_codes[] = $org_code;
        $manager = $this->_row($org_code, $prefix.'-MGR-'.$this->suffix, [
            'personnel_number' => $prefix.'-001',
            'last_name' => 'Руководитель',
            'first_name' => 'Тест',
            'position_title' => 'Руководитель направления',
            'department_code' => 'management'.$this->suffix,
            'department_name' => 'Управление',
            'work_email' => strtolower($prefix).'-mgr-'.$this->suffix.'@example.test',
        ]);
        $employee = $this->_row($org_code, $prefix.'-EMP-'.$this->suffix, [
            'personnel_number' => $prefix.'-002',
            'last_name' => 'Сотрудник',
            'first_name' => 'Тест',
            'position_title' => 'Инженер',
            'department_code' => 'it'.$this->suffix,
            'department_name' => 'ИТ',
            'parent_department_code' => 'management'.$this->suffix,
            'manager_external_id' => $prefix.'-MGR-'.$this->suffix,
            'work_email' => strtolower($prefix).'-emp-'.$this->suffix.'@example.test',
        ]);
        $rows = [$manager, $employee];
        $batch_id = Service_Prx300_Import::stage_rows($this->_global_actor(), $this->source, $rows, $prefix.'.csv');
        $batch = Service_Prx300_Import::apply_batch($this->_global_actor(), $batch_id);
        $this->assertSame('applied', $batch['status']);

        return ['batch_id' => $batch_id, 'rows' => $rows, 'batch' => $batch];
    }

    protected function _row($org_code, $external_id, array $overrides = [])
    {
        return array_merge([
            'employee_external_id' => $external_id,
            'organization_code' => $org_code,
            'organization_name' => 'Тестовая организация '.$org_code,
            'organization_legal_name' => '',
            'site_code' => 'main'.$this->suffix,
            'site_name' => 'Главная площадка',
            'site_city' => 'Город',
            'site_address' => '',
            'site_timezone' => 'UTC',
            'department_code' => 'dept'.$this->suffix,
            'department_name' => 'Отдел',
            'parent_department_code' => '',
            'parent_department_name' => '',
            'personnel_number' => '001',
            'last_name' => 'Тестов',
            'first_name' => 'Тест',
            'middle_name' => '',
            'position_title' => 'Специалист',
            'work_email' => '',
            'work_phone' => '',
            'employment_status' => 'active',
            'hired_on' => '2026-01-15',
            'terminated_on' => '',
            'manager_external_id' => '',
            'external_login' => '',
            'source_updated_at' => '2026-10-26T12:00:00+00:00',
        ], $overrides);
    }

    protected function _make_user($label)
    {
        $user = ORM::factory('User');
        $user->email = 't300-'.$label.'-'.$this->suffix.'@example.test';
        $user->username = 't300'.$label.$this->suffix;
        $user->password = bin2hex(random_bytes(16));
        $user->create();
        $this->user_ids[] = (int) $user->id;

        return $user;
    }

    protected function _global_actor()
    {
        return [
            'user_id' => 0,
            'roles' => ['admin'],
            'can_admin_view' => TRUE,
            'can_manage' => TRUE,
            'can_import' => TRUE,
            'can_identity' => TRUE,
            'can_directory' => TRUE,
        ];
    }

    protected function _actor($user_id, array $roles, array $flags)
    {
        return array_merge([
            'user_id' => (int) $user_id,
            'roles' => $roles,
            'can_admin_view' => FALSE,
            'can_manage' => FALSE,
            'can_import' => FALSE,
            'can_identity' => FALSE,
            'can_directory' => FALSE,
        ], $flags);
    }

    protected function _assert_throws($class, callable $operation)
    {
        try
        {
            $operation();
        }
        catch (Throwable $e)
        {
            $this->assertSame($class, get_class($e), $e->getMessage());
            return;
        }

        $this->fail('Ожидалось исключение '.$class);
    }
}
