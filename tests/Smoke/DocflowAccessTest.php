<?php
use PHPUnit\Framework\TestCase;

/** ACL на уровне сервисов + явные фоновые операции, временная PostgreSQL. */
class DocflowAccessTest extends TestCase
{
    private $db;
    private $acl;
    private $old_acl = [];

    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->db->begin();
        $this->acl = Kohana::$config->load('acl');
        // Production по-прежнему admin-only; здесь проверяем будущую ролевую матрицу.
        foreach ([
            'prx391.view' => ['login'],
            'prx391.edit' => ['docflow_clerk', 'docflow_lawyer', 'docflow_manager'],
            'prx391.admin' => ['docflow_manager'],
            'prx392.run' => ['docflow_clerk', 'docflow_lawyer', 'docflow_manager'],
            'prx393.manage' => ['docflow_clerk', 'docflow_lawyer', 'docflow_manager'],
        ] as $key => $roles) {
            $this->old_acl[$key] = $this->acl->get($key);
            $this->acl->set($key, $roles);
        }
    }

    protected function tearDown(): void
    {
        $this->db->rollback();
        foreach ($this->old_acl as $key => $value) $this->acl->set($key, $value);
    }

    private function document(string $sensitivity, string $prefix): int
    {
        $row = $this->db->query(Database::SELECT,
            'INSERT INTO prx_391_documents (reg_no, subject, sensitivity) VALUES ('
            .$this->db->quote($prefix.'-'.$sensitivity).','.$this->db->quote($prefix).','.$this->db->quote($sensitivity).') RETURNING id', FALSE)->current();
        return (int) $row['id'];
    }

    private function denied(callable $operation): void
    {
        try { $operation(); } catch (HTTP_Exception_403 $e) { $this->assertSame(403, $e->getCode()); return; }
        $this->fail('Expected 403');
    }

    public function test_visibility_is_consistent_for_card_list_history_and_stats(): void
    {
        $prefix = 'acl-'.bin2hex(random_bytes(4));
        $clerk = new Prx_Actor(71, ['login', 'docflow_clerk']);
        $lawyer = new Prx_Actor(72, ['login', 'docflow_lawyer']);
        $before_clerk = Service_Prx391_Documents::stats($clerk)['total'];
        $before_lawyer = Service_Prx391_Documents::stats($lawyer)['total'];
        $ids = [];
        foreach (['public', 'internal', 'personal', 'commercial'] as $sensitivity) {
            $ids[$sensitivity] = $this->document($sensitivity, $prefix);
            $this->db->query(Database::INSERT, 'INSERT INTO prx_391_history (document_id, event, note) VALUES ('
                .$ids[$sensitivity].", 'test', ".$this->db->quote($prefix).')', FALSE);
        }
        $this->assertNull(Service_Prx391_Documents::document($ids['personal'], $clerk));
        $this->assertSame($ids['personal'], (int) Service_Prx391_Documents::document($ids['personal'], $lawyer)['id']);
        $this->assertNull(Service_Prx391_Documents::document($ids['public']));
        $this->assertSame([], Service_Prx391_Documents::visible_ids(array_values($ids)));
        $this->denied(fn() => Service_Prx391_Documents::list_documents());
        $this->denied(fn() => Service_Prx391_Documents::stats());
        $this->assertSame([$ids['public'], $ids['internal']], Service_Prx391_Documents::visible_ids(array_values($ids), $clerk));
        $this->assertSame(array_values($ids), Service_Prx391_Documents::visible_ids(array_values($ids), $lawyer));
        $this->assertSame(2, Service_Prx391_Documents::list_documents(['q' => $prefix], $clerk)['total']);
        $this->assertSame(4, Service_Prx391_Documents::list_documents(['q' => $prefix], $lawyer)['total']);
        $this->assertSame($before_clerk + 2, Service_Prx391_Documents::stats($clerk)['total']);
        $this->assertSame($before_lawyer + 4, Service_Prx391_Documents::stats($lawyer)['total']);
        $history = Service_Prx391_Documents::recent_history(100, $clerk);
        $this->assertNotContains($ids['personal'], array_map('intval', array_column($history, 'document_id')));
        $this->assertNotContains($ids['commercial'], array_map('intval', array_column($history, 'document_id')));
        $this->assertSame($ids['personal'], (int) Service_Prx391_Documents::document_for_processing($ids['personal'])['id']);
        // Изменить гриф нельзя даже редактору с доступом к карточке.
        try {
            Service_Prx391_Documents::save_card($ids['public'], ['sensitivity' => 'personal'], $clerk);
            $this->fail('Only document admin changes sensitivity');
        } catch (RuntimeException $e) { $this->assertStringContainsString('гриф', $e->getMessage()); }
    }

    public function test_user_routing_and_review_do_not_gain_system_access(): void
    {
        $prefix = 'flow-'.bin2hex(random_bytes(4));
        $id = $this->document('personal', $prefix);
        $clerk = new Prx_Actor(71, ['login', 'docflow_clerk']);
        $lawyer = new Prx_Actor(72, ['login', 'docflow_lawyer']);
        $this->denied(fn() => Service_Prx393_Workflow::route($id));
        $this->denied(fn() => Service_Prx392_Analysis::queue_for_document($id));
        try {
            Service_Prx393_Workflow::route($id, $clerk);
            $this->fail('Clerk cannot route personal document');
        } catch (InvalidArgumentException $e) { $this->assertStringContainsString('не найден', $e->getMessage()); }
        $deadline = Service_Prx393_Workflow::route_automatically($id);
        $this->assertNotEmpty($deadline);
        $this->assertSame('open', $deadline['status']);
        Service_Prx391_Documents::close($id, $lawyer);
        $this->assertSame('met', Service_Prx393_Workflow::deadline_for($id)['status']);
        // Закрытие прошло через событие и явный служебный route_automatically.
        $row = $this->db->query(Database::SELECT, "SELECT actor_id FROM prx_391_history WHERE document_id = ".$id." AND event = 'closed' ORDER BY id DESC LIMIT 1", FALSE)->current();
        $this->assertSame(72, (int) $row['actor_id']);

        $run = $this->db->query(Database::SELECT, 'INSERT INTO prx_392_runs (document_id, message_id, profile, model, prompt_version, input_hash) VALUES ('
            .$id.",0,'classify_extract_v1','local::test','1',".$this->db->quote(md5($prefix)).') RETURNING id', FALSE)->current();
        $suggestion = $this->db->query(Database::SELECT, 'INSERT INTO prx_392_suggestions (run_id, document_id, payload) VALUES ('
            .(int) $run['id'].','.$id.",'{}'::jsonb) RETURNING id", FALSE)->current();
        $suggestion_id = (int) $suggestion['id'];
        $this->denied(fn() => Service_Prx392_Analysis::reject($suggestion_id));
        try {
            Service_Prx392_Analysis::reject($suggestion_id, $clerk);
            $this->fail('Clerk cannot reject hidden proposal');
        } catch (InvalidArgumentException $e) { $this->assertStringContainsString('не найден', $e->getMessage()); }
        $this->assertTrue(Service_Prx392_Analysis::reject($suggestion_id, $lawyer));
        $row = $this->db->query(Database::SELECT, 'SELECT reviewed_by, status FROM prx_392_suggestions WHERE id = '.$suggestion_id, FALSE)->current();
        $this->assertSame('rejected', $row['status']);
        $this->assertSame(72, (int) $row['reviewed_by']);
    }
}
