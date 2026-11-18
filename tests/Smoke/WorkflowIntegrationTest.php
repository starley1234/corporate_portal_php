<?php
use PHPUnit\Framework\TestCase;

/** Маршрутизация и полный жизненный цикл срока на настоящем PostgreSQL. */
class WorkflowIntegrationTest extends TestCase
{
    private $actor = ['user_id' => 0, 'roles' => ['system'], 'is_system' => TRUE];

    private function insert_document(string $type, string $received_at): int
    {
        $rows = DB::query(Database::SELECT,
            'INSERT INTO prx_391_documents (reg_no, subject, type_id, received_at) '
            .'VALUES (:reg_no, :subject, (SELECT id FROM prx_391_types WHERE code = :type), :received_at) RETURNING id')
            ->parameters([
                ':reg_no' => 'TEST-'.bin2hex(random_bytes(6)),
                ':subject' => 'Тестовый документ',
                ':type' => $type,
                ':received_at' => $received_at,
            ])->execute()->as_array();

        return (int) $rows[0]['id'];
    }

    private function create_user(array $roles): int
    {
        $suffix = bin2hex(random_bytes(6));
        $rows = DB::query(Database::SELECT,
            'INSERT INTO users (username, email, password, logins, email_confirmed, failed_login_attempts, created_at, updated_at) '
            .'VALUES (:name, :email, :password, 0, 1, 0, now(), now()) RETURNING id')
            ->parameters([':name' => 'wf-'.$suffix, ':email' => $suffix.'@example.test', ':password' => 'not-a-login'])
            ->execute()->as_array();
        $id = (int) $rows[0]['id'];
        foreach ($roles as $role) {
            DB::query(Database::INSERT,
                'INSERT INTO roles_users (user_id, role_id) SELECT :id, id FROM roles WHERE name = :role')
                ->parameters([':id' => $id, ':role' => $role])->execute();
        }
        return $id;
    }

    private function count_rows(string $table, int $document_id): int
    {
        $rows = DB::query(Database::SELECT, 'SELECT count(*) AS n FROM '.$table.' WHERE document_id = :id')
            ->param(':id', $document_id)->execute()->as_array();
        return (int) $rows[0]['n'];
    }

    public function test_registration_sets_one_calendar_deadline_snapshot_and_sidebar(): void
    {
        Event::has('prx391.document.registered');
        $db = Database::instance();
        $db->begin();
        try {
            // Пятница: правило invoice — 72 календарных часа, не три рабочих дня.
            $document_id = $this->insert_document('invoice', '2026-11-13 10:00:00');
            Event::fire('prx391.document.registered', ['document_id' => $document_id]);
            $deadline = Service_Prx393_Workflow::deadline_for($document_id);
            $this->assertNotNull($deadline);
            $this->assertSame('open', $deadline['status']);
            $this->assertSame('rule', $deadline['source']);
            $this->assertSame('2026-11-16 10:00:00', $deadline['due_at']);
            $this->assertContains($deadline['business_days'], [FALSE, 0, '0', 'f', 'false']);
            $this->assertSame('Бухгалтерия', $deadline['department']);
            $this->assertSame('docflow_clerk', $deadline['role_code']);
            $this->assertArrayHasKey('state', $deadline);
            $this->assertArrayHasKey('hours_left', $deadline);

            $doc = Service_Prx391_Documents::document($document_id, $this->actor);
            $this->assertSame($deadline['due_at'], $doc['deadline_at']);
            $this->assertSame('rule', $doc['deadline_source']);
            $this->assertSame('Бухгалтерия', $doc['department']);
            $this->assertSame('docflow_clerk', $doc['assignee_role']);
            $html = View::factory('391/partials/sla_sidebar', [
                'doc' => $doc, 'deadline' => $deadline, 'csrf' => 'test', 'can_route' => TRUE,
            ])->render();
            $this->assertStringContainsString('Пересчитать маршрут', $html);
            $this->assertStringContainsString('16.11.2026 10:00', $html);

            // Повторная регистрация/пересчёт обновляет, а не дублирует строки.
            Event::fire('prx391.document.registered', ['document_id' => $document_id]);
            $this->assertSame((int) $deadline['id'], (int) Service_Prx393_Workflow::deadline_for($document_id)['id']);
            $this->assertSame(1, $this->count_rows('prx_393_assignments', $document_id));
            $this->assertSame(1, $this->count_rows('prx_393_deadlines', $document_id));
        } finally {
            $db->rollback();
        }
    }

    public function test_close_and_reopen_update_the_existing_deadline(): void
    {
        Event::has('prx391.document.updated');
        $db = Database::instance();
        $db->begin();
        try {
            $document_id = $this->insert_document('invoice', '2026-11-13 10:00:00');
            $created = Service_Prx393_Workflow::route($document_id, $this->actor);
            $this->assertSame('open', $created['status']);

            Service_Prx391_Documents::close($document_id, $this->actor);
            $closed = Service_Prx393_Workflow::deadline_for($document_id);
            $this->assertSame('met', $closed['status']);
            $this->assertSame('met', $closed['state']);
            $this->assertNotContains($document_id, array_map('intval', array_column(Service_Prx393_Workflow::deadlines(), 'document_id')));
            // Ручной вызов не должен вновь открыть срок закрытого документа.
            $manual = Service_Prx393_Workflow::route($document_id, $this->actor);
            $this->assertSame('met', $manual['status']);
            $this->assertSame((int) $created['id'], (int) $manual['id']);
            $doc = Service_Prx391_Documents::document($document_id, $this->actor);
            $html = View::factory('391/partials/sla_sidebar', [
                'doc' => $doc, 'deadline' => $closed, 'csrf' => 'test', 'can_route' => TRUE,
            ])->render();
            $this->assertStringContainsString('Снят с контроля', $html);
            $this->assertStringNotContainsString('Пересчитать маршрут', $html);
            $this->assertStringNotContainsString('просрочен на', $html);

            Service_Prx391_Documents::reopen($document_id, $this->actor);
            $reopened = Service_Prx393_Workflow::deadline_for($document_id);
            $this->assertSame('open', $reopened['status']);
            $this->assertSame((int) $created['id'], (int) $reopened['id']);
            $this->assertSame($created['due_at'], $reopened['due_at']);
            $this->assertSame(1, $this->count_rows('prx_393_deadlines', $document_id));
        } finally {
            $db->rollback();
        }
    }

    public function test_business_day_rule_respects_holiday_and_hint_recalculates(): void
    {
        Event::has('prx391.document.updated');
        $db = Database::instance();
        $db->begin();
        try {
            // 4 ноября и выходные — нерабочие: пять рабочих дней до 10 ноября.
            $document_id = $this->insert_document('tax', '2026-11-02 10:00:00');
            $deadline = Service_Prx393_Workflow::route($document_id, $this->actor);
            $this->assertSame('2026-11-10 18:00:00', $deadline['due_at']);
            $this->assertSame('rule', $deadline['source']);
            $this->assertContains($deadline['business_days'], [TRUE, 1, '1', 't', 'true']);

            $rules = array_column(Service_Prx393_Workflow::rules(TRUE), NULL, 'code');
            $this->assertSame(FALSE, $rules['invoice']['business_days']);
            $this->assertSame(TRUE, $rules['tax']['business_days']);

            DB::query(Database::UPDATE,
                "UPDATE prx_391_documents SET deadline_hint_days = 2, deadline_hint_unit = 'calendar' WHERE id = :id")
                ->param(':id', $document_id)->execute();
            Event::fire('prx391.document.updated', ['document_id' => $document_id, 'recalc' => TRUE]);
            $hint = Service_Prx393_Workflow::deadline_for($document_id);
            $this->assertSame('hint', $hint['source']);
            $this->assertSame('2026-11-04 18:00:00', $hint['due_at']);
            $this->assertContains($hint['business_days'], [FALSE, 0, '0', 'f', 'false']);
            $this->assertSame((int) $deadline['id'], (int) $hint['id']);
            $doc = Service_Prx391_Documents::document($document_id, $this->actor);
            $this->assertSame($hint['due_at'], $doc['deadline_at']);
        } finally {
            $db->rollback();
        }
    }

    public function test_urgent_hours_and_business_hint_record_the_actual_mode(): void
    {
        Event::has('prx391.document.updated');
        $db = Database::instance();
        $db->begin();
        try {
            $document_id = $this->insert_document('court', '2026-11-13 10:00:00');
            $deadline = Service_Prx393_Workflow::route($document_id, $this->actor);
            $this->assertSame('2026-11-14 10:00:00', $deadline['due_at']);
            $this->assertContains($deadline['business_days'], [FALSE, 0, '0', 'f', 'false']);

            DB::query(Database::UPDATE,
                "UPDATE prx_391_documents SET deadline_hint_days = 2, deadline_hint_unit = 'business' WHERE id = :id")
                ->param(':id', $document_id)->execute();
            Event::fire('prx391.document.updated', ['document_id' => $document_id, 'recalc' => TRUE]);
            $hint = Service_Prx393_Workflow::deadline_for($document_id);
            $this->assertSame('hint', $hint['source']);
            $this->assertSame('2026-11-17 18:00:00', $hint['due_at']);
            $this->assertContains($hint['business_days'], [TRUE, 1, '1', 't', 'true']);
        } finally {
            $db->rollback();
        }
    }

    public function test_bulk_recalculation_is_limited_to_open_documents(): void
    {
        Event::has('prx391.document.updated');
        $db = Database::instance();
        $db->begin();
        try {
            $open = $this->insert_document('tax', '2099-01-10 10:00:00');
            $closed = $this->insert_document('invoice', '2099-01-11 10:00:00');
            DB::query(Database::UPDATE, "UPDATE prx_391_documents SET status = 'closed' WHERE id = :id")
                ->param(':id', $closed)->execute();
            $this->assertNull(Service_Prx393_Workflow::route($closed, $this->actor));
            $this->assertSame(1, Service_Prx393_Workflow::recalculate_all(1));
            $this->assertSame('open', Service_Prx393_Workflow::deadline_for($open)['status']);
            $this->assertNull(Service_Prx393_Workflow::deadline_for($closed));
        } finally {
            $db->rollback();
        }
    }

    public function test_sla_and_history_respect_document_visibility(): void
    {
        Event::has('prx391.document.registered');
        $db = Database::instance();
        $db->begin();
        try {
            $internal = $this->insert_document('invoice', '2026-01-01 10:00:00');
            $personal = $this->insert_document('invoice', '2026-01-02 10:00:00');
            $commercial = $this->insert_document('invoice', '2026-01-03 10:00:00');
            DB::query(Database::UPDATE, "UPDATE prx_391_documents SET sensitivity = 'personal' WHERE id = :id")
                ->param(':id', $personal)->execute();
            DB::query(Database::UPDATE, "UPDATE prx_391_documents SET sensitivity = 'commercial' WHERE id = :id")
                ->param(':id', $commercial)->execute();
            foreach ([$internal, $personal, $commercial] as $id) {
                $deadline = Service_Prx393_Workflow::route($id, $this->actor);
                DB::query(Database::INSERT,
                    "INSERT INTO prx_393_escalations (deadline_id, document_id, level) VALUES (:deadline, :id, 't24')")
                    ->parameters([':deadline' => $deadline['id'], ':id' => $id])->execute();
                DB::query(Database::INSERT,
                    "INSERT INTO prx_391_history (document_id, event, note) VALUES (:id, 'test', 'test history')")
                    ->param(':id', $id)->execute();
            }
            $login = ['user_id' => 123, 'roles' => ['login']];
            $lawyer = ['user_id' => 124, 'roles' => ['login', 'docflow_lawyer']];
            $this->assertSame([$internal], Service_Prx391_Documents::visible_ids([$internal, $personal, $commercial], $login));
            $visible = array_map('intval', array_column(Service_Prx393_Workflow::visible_deadlines($login, 200), 'document_id'));
            $this->assertContains($internal, $visible);
            $this->assertNotContains($personal, $visible);
            $this->assertNotContains($commercial, $visible);
            $escalated = array_map('intval', array_column(Service_Prx393_Workflow::visible_escalations($login, 200), 'document_id'));
            $this->assertContains($internal, $escalated);
            $this->assertNotContains($personal, $escalated);
            $this->assertNotContains($commercial, $escalated);
            $history = array_map('intval', array_column(Service_Prx391_Documents::recent_history(200, $login), 'document_id'));
            $this->assertContains($internal, $history);
            $this->assertNotContains($personal, $history);
            $this->assertNotContains($commercial, $history);
            $this->assertNull(Service_Prx393_Workflow::visible_stats($login));
            $this->assertSame([], Service_Prx391_Documents::recent_history(200, []));
            $this->assertSame([], Service_Prx393_Workflow::visible_deadlines([], 200));

            $lawyer_ids = array_map('intval', array_column(Service_Prx393_Workflow::visible_deadlines($lawyer, 200), 'document_id'));
            $this->assertContains($personal, $lawyer_ids);
            $this->assertContains($commercial, $lawyer_ids);
            $lawyer_history = array_map('intval', array_column(Service_Prx391_Documents::recent_history(200, $lawyer), 'document_id'));
            $this->assertContains($personal, $lawyer_history);
            $this->assertNotNull(Service_Prx393_Workflow::visible_stats($lawyer));
        } finally {
            $db->rollback();
        }
    }

    public function test_escalation_targets_must_be_allowed_to_view_each_document(): void
    {
        Event::has('prx393.deadline.overdue');
        $db = Database::instance();
        $db->begin();
        try {
            $clerk = $this->create_user(['login', 'docflow_clerk']);
            $lawyer_clerk = $this->create_user(['login', 'docflow_clerk', 'docflow_lawyer']);
            $without_login = $this->create_user(['docflow_clerk']);
            $internal = $this->insert_document('invoice', '2001-01-01 10:00:00');
            $personal = $this->insert_document('invoice', '2001-01-02 10:00:00');
            DB::query(Database::UPDATE, "UPDATE prx_391_documents SET sensitivity = 'personal' WHERE id = :id")
                ->param(':id', $personal)->execute();
            Service_Prx393_Workflow::route($internal, $this->actor);
            Service_Prx393_Workflow::route($personal, $this->actor);
            Service_Prx393_Workflow::maybe_escalate();

            $rows = DB::query(Database::SELECT,
                "SELECT payload FROM prx_208_events WHERE event_name = 'prx393.deadline.overdue' "
                ."AND payload::jsonb->>'document_id' IN (:internal, :personal)")
                ->parameters([':internal' => (string) $internal, ':personal' => (string) $personal])
                ->execute()->as_array();
            $targets = [];
            foreach ($rows as $row) {
                $payload = json_decode($row['payload'], TRUE);
                $targets[(int) $payload['document_id']] = $payload['target_user_ids'];
            }
            $this->assertArrayHasKey($internal, $targets);
            $this->assertArrayHasKey($personal, $targets);
            $this->assertContains($clerk, $targets[$internal]);
            $this->assertContains($lawyer_clerk, $targets[$internal]);
            $this->assertNotContains($without_login, $targets[$internal]);
            $this->assertNotContains($clerk, $targets[$personal]);
            $this->assertContains($lawyer_clerk, $targets[$personal]);
            $this->assertNotContains($without_login, $targets[$personal]);
        } finally {
            $db->rollback();
        }
    }

    public function test_registration_failure_log_excludes_sql_and_document_subject(): void
    {
        Event::has('prx391.document.registered');
        $path = tempnam(sys_get_temp_dir(), 'prx393-diag-');
        $old_log_enabled = getenv('PRX_LOG_ENABLED');
        putenv('PRX_LOG_ENABLED=1');
        $writer = new Log_Plain($path, 0, 0, Log::INFO);
        Kohana::$log->attach($writer);
        $db = Database::instance();
        $db->begin();
        try {
            $document_id = $this->insert_document('invoice', '2026-11-13 10:00:00');
            DB::query(Database::UPDATE,
                "UPDATE prx_391_documents SET subject = 'private-route-subject-71' WHERE id = :id")
                ->param(':id', $document_id)->execute();
            // Принудительный отказ внутри SQL прерывает только тестовую транзакцию.
            DB::query(Database::UPDATE,
                'ALTER TABLE prx_393_deadlines ADD CONSTRAINT prx393_test_reject CHECK (document_id <> '.(int) $document_id.')')
                ->execute();
            Event::fire('prx391.document.registered', ['document_id' => $document_id]);

            $lines = preg_grep('/prx393_diag/', explode("\n", file_get_contents($path)));
            $diagnostics = implode("\n", $lines);
            $this->assertStringContainsString('stage=register_route result=error document_id='.$document_id.' error_type=Database_Exception', $diagnostics);
            $this->assertStringNotContainsString('private-route-subject-71', $diagnostics);
            $this->assertStringNotContainsString('INSERT INTO', $diagnostics);
        } finally {
            $db->rollback();
            Kohana::$log->detach($writer);
            $old_log_enabled === FALSE ? putenv('PRX_LOG_ENABLED') : putenv('PRX_LOG_ENABLED='.$old_log_enabled);
            unlink($path);
        }
    }
}
