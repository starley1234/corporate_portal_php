<?php
use PHPUnit\Framework\TestCase;

/** Административные SELECT-контракты очереди; данные изолированы откатом транзакции. */
class QueueReportsIntegrationTest extends TestCase
{
    private $db;
    private $queue;
    protected function setUp(): void
    {
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
        $this->db->begin();
        $this->db->query(Database::DELETE, 'DELETE FROM prx_120_usage', FALSE);
        $this->db->query(Database::DELETE, 'DELETE FROM prx_120_jobs', FALSE);
        $this->queue = Service_Prx120_JobQueue::instance();
    }
    protected function tearDown(): void { $this->db->rollback(); }
    private function job(string $channel, string $status, string $extra = ''): int
    {
        $id = Service_Prx120_JobQueue::push($channel, ['private' => 'must not escape'], ['budget' => FALSE]);
        $this->db->query(Database::UPDATE, 'UPDATE prx_120_jobs SET status = '.$this->db->quote($status).$extra.' WHERE id = '.$id, FALSE);
        return $id;
    }
    public function test_platform_worker_and_usage_metrics_preserve_windows_and_null_semantics(): void
    {
        $empty = $this->queue->worker_activity();
        $this->assertSame(['day' => 0, 'live' => 0, 'ago' => NULL], $empty);
        $this->job('report', 'pending');
        $this->job('report', 'processing', ", heartbeat_at = NULL, created_at = now() - interval '1 hour', claimed_at = now() - interval '1 hour', claimed_by = 'silent'");
        $this->job('report', 'processing', ", heartbeat_at = now() - interval '20 minutes', claimed_by = 'silent'");
        $this->job('report', 'processing', ", heartbeat_at = now(), claimed_by = 'live'");
        $done = $this->job('report', 'done', ', finished_at = now()');
        $this->job('report', 'failed', ', finished_at = now()');
        $this->job('report', 'failed', ", created_at = now() - interval '3 days', finished_at = now() - interval '2 days'");
        $before = $this->queue->stats();
        $this->assertSame(['pending' => 1, 'processing' => 3, 'failed' => 2, 'failed24' => 1,
                          'done24' => 1, 'stuck' => 2, 'heartbeat_stuck' => 1], $this->queue->platform_metrics());
        $workers = $this->queue->worker_activity();
        $this->assertSame(2, $workers['day']);
        $this->assertSame(1, $workers['live']);
        $this->assertLessThan(10, $workers['ago']);
        $this->db->query(Database::INSERT, 'INSERT INTO prx_120_usage (job_id, prompt_tokens, completion_tokens) VALUES ('.$done.', 10, 7)', FALSE);
        $this->assertSame(['tokens' => 17, 'jobs' => 1], $this->queue->usage_today());
        $this->assertSame($before, $this->queue->stats()); // чтение не обслуживает очередь
    }
    public function test_channel_report_is_scoped_quoted_and_keeps_creation_time_windows(): void
    {
        $channel = "report.quote' OR true --";
        $this->job($channel, 'pending');
        $this->job($channel, 'processing');
        $this->job($channel, 'done', ", result = '{\"skipped\": false}'::jsonb");
        $this->job($channel, 'done', ", created_at = now() - interval '3 days', result = '{\"mode\": \"http-bridge\"}'::jsonb");
        $this->job($channel, 'failed', ", created_at = now() - interval '8 days'");
        $this->job('unrelated', 'pending');
        $this->assertSame(['pending' => 1, 'processing' => 1, 'done_24h' => 1, 'failed_24h' => 0,
                          'queued_recent' => 2, 'bridge' => 2], $this->queue->channel_metrics($channel));
        $this->assertSame(0, $this->queue->channel_metrics('absent')['pending']);
    }
    public function test_admin_activity_has_bounded_projection_and_guest_user_counts(): void
    {
        $this->job('activity', 'pending', ", visitor_id = 'u:1'");
        $this->job('activity', 'done', ", visitor_id = 'g:visitor'");
        $last = $this->job('old', 'done', ", created_at = now() - interval '2 days'");
        $activity = $this->queue->admin_activity();
        $this->assertSame(['d1' => 2, 'd1_users' => 1, 'd1_guests' => 1, 'd1_visitors' => 2], $activity['kpi']);
        $this->assertCount(1, $activity['channels']);
        $this->assertSame(2, (int) $activity['channels'][0]['c']);
        $this->assertSame(1, (int) $activity['channels'][0]['guests']);
        $this->assertSame($last, (int) $activity['jobs'][0]['id']);
        $this->assertSame(['id', 'channel', 'status', 'visitor_id', 'created_at', 'finished_at'], array_keys($activity['jobs'][0]));
        $this->assertLessThanOrEqual(120, count($activity['jobs']));
    }
}
