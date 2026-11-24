<?php
use PHPUnit\Framework\TestCase;

/** Управляемое чередование read/UPDATE: новый захват между двумя запросами. */
class JobOwnershipQueueProbe extends Service_Prx120_JobQueue
{
    public $on_read;
    public function get($id)
    {
        $row = parent::get($id);
        if ($this->on_read) {
            $callback = $this->on_read;
            $this->on_read = NULL;
            $callback();
        }
        return $row;
    }
    public function force_finish($id, $worker, $status)
    {
        return $this->_finalize_force($id, $worker, $status, 'test fallback');
    }
}

class JobOwnershipIntegrationTest extends TestCase
{
    private $db;
    private $queue;
    private $channel;

    protected function setUp(): void
    {
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
        $this->db->begin();
        $this->queue = new JobOwnershipQueueProbe;
        $this->channel = 'test.ownership.'.bin2hex(random_bytes(5));
    }
    protected function tearDown(): void { $this->db->rollback(); }

    private function job(int $attempts = 3): int
    {
        $id = Service_Prx120_JobQueue::push($this->channel, [], ['max_attempts' => $attempts]);
        $this->assertSame($id, (int) $this->queue->claim([$this->channel], 'worker-a')['id']);
        return $id;
    }
    private function change(int $id, string $set): void
    {
        $this->db->query(Database::UPDATE, 'UPDATE prx_120_jobs SET '.$set.' WHERE id = '.$id, FALSE);
    }
    private function events(int $id): int
    {
        return (int) $this->db->query(Database::SELECT, 'SELECT count(*) AS n FROM prx_120_job_events WHERE job_id = '.$id, FALSE)->get('n');
    }

    public function test_foreign_reports_never_mutate_current_claim_or_event_log(): void
    {
        $id = $this->job(1);
        $before = $this->queue->get($id);
        $events = $this->events($id);
        $this->assertFalse($this->queue->heartbeat($id, 'worker-b', 'foreign heartbeat'));
        $this->assertFalse($this->queue->progress($id, 'worker-b', 'foreign progress'));
        $this->assertFalse($this->queue->complete($id, 'worker-b', ['wrong' => TRUE]));
        $this->assertFalse($this->queue->fail($id, 'worker-b', 'foreign failure', FALSE));
        $this->assertFalse($this->queue->force_finish($id, 'worker-b', 'failed'));
        $this->assertSame($before, $this->queue->get($id));
        $this->assertSame($events, $this->events($id));
    }

    public function test_retry_releases_claim_and_rejects_duplicate_failure_and_progress(): void
    {
        $id = $this->job();
        $this->assertTrue($this->queue->fail($id, 'worker-a', 'temporary', TRUE));
        $pending = $this->queue->get($id);
        $this->assertSame('pending', $pending['status']);
        $this->assertNull($pending['claimed_by']);
        $this->assertNull($pending['claimed_at']);
        $events = $this->events($id);
        $this->assertFalse($this->queue->fail($id, 'worker-a', 'duplicate', FALSE));
        $this->assertFalse($this->queue->heartbeat($id, 'worker-a'));
        $this->assertFalse($this->queue->progress($id, 'worker-a', 'late'));
        $this->assertSame($pending, $this->queue->get($id));
        $this->assertSame($events, $this->events($id));
        $this->assertNull($this->queue->claim([$this->channel], 'worker-b'));
        $this->change($id, 'available_at = now()');
        $this->assertSame($id, (int) $this->queue->claim([$this->channel], 'worker-b')['id']);
        $this->assertSame(2, (int) $this->queue->get($id)['attempts']);
        $this->assertFalse($this->queue->complete($id, 'worker-a'));
        $this->assertTrue($this->queue->complete($id, 'worker-b', ['ok' => TRUE]));
        $events = $this->events($id);
        $this->assertTrue($this->queue->complete($id, 'worker-b')); // идемпотентный done
        $this->assertSame($events, $this->events($id));
    }

    public function test_pending_completion_cannot_overwrite_a_claim_between_read_and_write(): void
    {
        $id = $this->job();
        $this->change($id, "status = 'pending', claimed_by = NULL, claimed_at = NULL");
        // Первый read complete(), затем повторный read fallback, затем новый claim.
        $this->queue->on_read = function () use ($id) {
            $this->queue->on_read = function () use ($id) {
                $this->assertSame($id, (int) Service_Prx120_JobQueue::instance()->claim([$this->channel], 'worker-b')['id']);
            };
        };
        $this->assertFalse($this->queue->complete($id, 'worker-a', ['late' => TRUE]));
        $this->assertSame('processing', $this->queue->get($id)['status']);
        $this->assertSame('worker-b', $this->queue->get($id)['claimed_by']);
        $this->assertNull($this->queue->get($id)['result']);
    }

    public function test_terminal_fallback_cannot_overwrite_reassigned_job(): void
    {
        $id = $this->job(1);
        $this->queue->on_read = function () use ($id) { $this->change($id, "claimed_by = 'worker-b'"); };
        $this->assertFalse($this->queue->fail($id, 'worker-a', 'late terminal failure', FALSE));
        $this->assertSame('processing', $this->queue->get($id)['status']);
        $this->assertSame('worker-b', $this->queue->get($id)['claimed_by']);
    }

    public function test_own_fallback_records_the_actual_terminal_event(): void
    {
        $id = $this->job(1);
        $this->assertTrue($this->queue->force_finish($id, 'worker-a', 'failed'));
        $event = $this->db->query(Database::SELECT, 'SELECT event FROM prx_120_job_events WHERE job_id = '.$id.' ORDER BY id DESC LIMIT 1', FALSE)->get('event');
        $this->assertSame('failed', $event);
        $this->assertFalse($this->queue->force_finish($id, 'worker-a', 'done'));
        $this->assertSame('failed', $this->queue->get($id)['status']);
    }

    public function test_late_success_can_reconcile_unclaimed_pending_but_not_cancellation(): void
    {
        $id = $this->job();
        $this->change($id, "status = 'pending', claimed_by = NULL");
        $this->assertTrue($this->queue->complete($id, 'worker-a', ['saved' => TRUE]));
        $this->assertSame('done', $this->queue->get($id)['status']);
        $cancelled = $this->job();
        $this->queue->cancel($cancelled, TRUE);
        $this->assertFalse($this->queue->complete($cancelled, 'worker-a'));
        $this->assertFalse($this->queue->fail($cancelled, 'worker-a', 'late', FALSE));
        $this->assertSame('cancelled', $this->queue->get($cancelled)['status']);
    }
}
