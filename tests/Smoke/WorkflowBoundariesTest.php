<?php
use PHPUnit\Framework\TestCase;

/** Реальные контракты prx391/Auth и workflow на временной PostgreSQL. */
class WorkflowBoundariesTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->db->begin();
    }

    protected function tearDown(): void
    {
        $this->db->rollback();
    }

    private function document(string $status = 'new', string $sensitivity = 'personal'): int
    {
        $row = $this->db->query(Database::SELECT,
            'INSERT INTO prx_391_documents (reg_no, status, sensitivity, received_at) VALUES ('
            .$this->db->quote('boundary-'.bin2hex(random_bytes(5))).','.$this->db->quote($status).','
            .$this->db->quote($sensitivity).", '2099-01-01 12:00:00') RETURNING id", FALSE)->current();
        return (int) $row['id'];
    }

    private function user(array $roles)
    {
        $suffix = bin2hex(random_bytes(6));
        $user = ORM::factory('User');
        $user->username = 'boundary-'.$suffix;
        $user->email = $suffix.'@example.test';
        $user->password = bin2hex(random_bytes(16));
        $user->create();
        foreach ($roles as $name) $user->add('roles', ORM::factory('Role', ['name' => $name]));
        return $user;
    }

    public function test_recalculation_gets_only_open_ids_from_document_owner(): void
    {
        $first = $this->document();
        $second = $this->document('in_work');
        $third = $this->document('on_hold');
        $closed = $this->document('closed');
        $archived = $this->document('archived');
        $this->assertSame([$third, $second], Service_Prx391_Documents::open_ids_for_processing(2));
        $this->assertSame([$third, $second, $first], array_slice(Service_Prx391_Documents::open_ids_for_processing(), 0, 3));
        $this->assertSame([], Service_Prx391_Documents::open_ids_for_processing(0));
        $this->assertSame([], Service_Prx391_Documents::open_ids_for_processing(-1));
        $this->assertSame(0, Service_Prx393_Workflow::recalculate_all(0));
        $this->assertSame(2, Service_Prx393_Workflow::recalculate_all(2));
        $this->assertNotEmpty(Service_Prx393_Workflow::deadline_for($third));
        $this->assertNotEmpty(Service_Prx393_Workflow::deadline_for($second));
        foreach ([$first, $closed, $archived] as $id) $this->assertNull(Service_Prx393_Workflow::deadline_for($id));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_escalations_filter_active_role_members_by_current_document_access(): void
    {
        $acl = Kohana::$config->load('acl');
        $previous = $acl->get('prx391.view');
        $acl->set('prx391.view', ['login']); // только тестовая матрица; production остаётся admin-only
        try
        {
            $eligible = $this->user(['login', 'docflow_clerk', 'docflow_lawyer']);
            $clerk = $this->user(['login', 'docflow_clerk']);
            $blocked = $this->user(['docflow_clerk', 'docflow_lawyer']);
            $unrelated = $this->user(['login', 'docflow_lawyer']);
            $actors = Service_Prx102_Users::actors_with_role('docflow_clerk');
            $ids = array_map(fn($actor) => $actor->id(), $actors);
            $this->assertContains((int) $eligible->id, $ids);
            $this->assertContains((int) $clerk->id, $ids);
            $this->assertNotContains((int) $blocked->id, $ids);
            $this->assertNotContains((int) $unrelated->id, $ids);
            $this->assertSame(count($ids), count(array_unique($ids)));
            $roles = array_values(array_filter($actors, fn($actor) => $actor->id() === (int) $eligible->id))[0]->roles();
            $this->assertContains('docflow_lawyer', $roles); // не только роль назначения
            $this->assertContains('login', $roles);
            $this->assertSame([], Service_Prx102_Users::actors_with_role('unknown-'.bin2hex(random_bytes(4))));
            $this->assertSame([], Service_Prx102_Users::actors_with_role(' '));

            $captured = [];
            Event::listen('prx393.deadline.overdue', function ($payload) use (&$captured) {
                $captured[(int) $payload['document_id']] = $payload['target_user_ids'];
            });
            $first = $this->document();
            Service_Prx393_Workflow::route_automatically($first);
            $this->make_overdue($first);
            Service_Prx393_Workflow::maybe_escalate();
            $this->assertSame([(int) $eligible->id], $captured[$first]);
            $this->assertSame(0, Service_Prx393_Workflow::maybe_escalate());

            // Снятие роли должно влиять на следующий обход без сброса кэша/перезапуска.
            $eligible->remove('roles', ORM::factory('Role', ['name' => 'docflow_lawyer']));
            $second = $this->document();
            Service_Prx393_Workflow::route_automatically($second);
            $this->make_overdue($second);
            Service_Prx393_Workflow::maybe_escalate();
            $this->assertSame([], $captured[$second]);
            $clerk->remove('roles', ORM::factory('Role', ['name' => 'login']));
            $this->assertNotContains((int) $clerk->id, array_map(fn($actor) => $actor->id(), Service_Prx102_Users::actors_with_role('docflow_clerk')));
        }
        finally
        {
            $acl->set('prx391.view', $previous);
        }
    }

    private function make_overdue(int $id): void
    {
        $this->db->query(Database::UPDATE, "UPDATE prx_393_deadlines SET due_at = now() - interval '1 hour' WHERE document_id = ".$id, FALSE);
        $this->db->query(Database::UPDATE, "UPDATE prx_393_assignments SET role_code = 'docflow_clerk' WHERE document_id = ".$id, FALSE);
    }
}
