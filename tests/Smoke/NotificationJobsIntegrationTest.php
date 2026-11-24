<?php
use PHPUnit\Framework\TestCase;

/** Маркер сборщика принадлежит очереди; реальные PostgreSQL-операции и rollback. */
class NotificationJobsIntegrationTest extends TestCase
{
    private $db;
    private $queue;
    protected function setUp(): void
    {
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
        $this->db->begin();
        $this->queue = Service_Prx120_JobQueue::instance();
    }
    protected function tearDown(): void { $this->db->rollback(); }
    private function job(string $status, string $visitor = 'u:123'): int
    {
        $id = Service_Prx120_JobQueue::push('test.notification.marker', [], ['visitor_id' => $visitor, 'budget' => FALSE]);
        $this->db->query(Database::UPDATE, 'UPDATE prx_120_jobs SET status = '.$this->db->quote($status).' WHERE id = '.$id, FALSE);
        return $id;
    }
    public function test_candidates_and_acknowledgement_exclude_anonymous_and_nonterminal_jobs(): void
    {
        $done = $this->job('done');
        $failed = $this->job('failed');
        $pending = $this->job('pending');
        $processing = $this->job('processing');
        $cancelled = $this->job('cancelled');
        $anonymous = $this->job('done', 'g:test');
        $this->assertSame([], $this->queue->pending_notifications(0));
        $this->assertSame([], $this->queue->pending_notifications(-1));
        $rows = $this->queue->pending_notifications(2);
        $this->assertSame([$failed, $done], array_map('intval', array_column($rows, 'id')));
        $this->assertSame(['id', 'channel', 'status', 'payload', 'error', 'created_by', 'visitor_id'], array_keys($rows[0]));
        foreach ([$pending, $processing, $cancelled, $anonymous, 0, -1] as $id)
            $this->assertFalse($this->queue->mark_notification_processed($id));
        $this->assertTrue($this->queue->mark_notification_processed($failed));
        $stamp = $this->stamp($failed);
        $this->assertNotNull($stamp);
        $this->assertFalse($this->queue->mark_notification_processed($failed));
        $this->assertSame($stamp, $this->stamp($failed));
        $this->assertSame([$done], array_map('intval', array_column($this->queue->pending_notifications(1), 'id')));
    }
    public function test_new_migration_preserves_existing_marker_on_repeated_up_and_down(): void
    {
        $id = $this->job('done');
        $this->db->query(Database::UPDATE, "UPDATE prx_120_jobs SET notified_at = '2020-01-02 03:04:05+00' WHERE id = ".$id, FALSE);
        $stamp = $this->stamp($id);
        require_once APPPATH.'modules/prx120_jobs/migrations_pg/pgMigration_Prx_120_007_Notification_Marker.php';
        $migration = new pgMigration_Prx_120_007_Notification_Marker;
        $migration->up();
        $migration->up();
        $migration->down(); // rollback владения не удаляет исторические отметки
        $this->assertSame($stamp, $this->stamp($id));
        $this->assertFalse($this->queue->mark_notification_processed($id));
        $ids = array_map('intval', array_column($this->queue->pending_notifications(1000), 'id'));
        $this->assertNotContains($id, $ids);
    }
    private function stamp(int $id)
    {
        return $this->db->query(Database::SELECT, 'SELECT notified_at FROM prx_120_jobs WHERE id = '.$id, FALSE)->get('notified_at');
    }
}
