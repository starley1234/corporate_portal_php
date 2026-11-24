<?php
use PHPUnit\Framework\TestCase;

/** Публичный Engine делегирует SELECT-отчёты, не запуская доставку. */
class NotificationReportsIntegrationTest extends TestCase
{
    private $db;
    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->db->begin();
        foreach (['prx_597_log', 'prx_597_outbox', 'prx_597_notifications'] as $table)
            $this->db->query(Database::DELETE, 'DELETE FROM '.$table, FALSE);
    }
    protected function tearDown(): void { $this->db->rollback(); }
    private function outbox(string $status, string $event = 'report.test'): int
    {
        return (int) $this->db->query(Database::SELECT,
            'INSERT INTO prx_597_outbox (user_id, channel, status, event_alias, subject) VALUES (17, \'email\', '
            .$this->db->quote($status).','.$this->db->quote($event).", 'Тема') RETURNING id", FALSE)->get('id');
    }
    private function log_row(int $outbox, bool $ok, bool $old = FALSE): int
    {
        return (int) $this->db->query(Database::SELECT,
            'INSERT INTO prx_597_log (outbox_id, channel, ok, created_at) VALUES ('.$outbox.", 'email', "
            .($ok ? 'TRUE' : 'FALSE').", now() - interval '".($old ? '48' : '0')." hours') RETURNING id", FALSE)->get('id');
    }
    public function test_journal_filters_recent_window_and_quoted_event(): void
    {
        $event = "report' OR true --";
        $first = $this->outbox('sent', $event);
        $second = $this->outbox('failed', 'other');
        $success = $this->log_row($first, TRUE);
        $failure = $this->log_row($second, FALSE);
        $this->log_row($first, TRUE, TRUE);
        $rows = Service_Prx597_Engine::journal(10, ['channel' => 'email', 'event' => $event, 'status' => 'ok']);
        $this->assertCount(2, $rows);
        $this->assertSame($event, $rows[0]['event_alias']);
        $this->assertSame([$failure], array_map('intval', array_column(Service_Prx597_Engine::journal(10, ['status' => 'failed']), 'id')));
        $this->assertSame([$success], array_map('intval', array_column(Service_Prx597_Engine::recent_sent(10, 24), 'id')));
        $this->assertSame('Тема', Service_Prx597_Engine::recent_sent(1)[0]['subject']);
        $this->assertCount(1, Service_Prx597_Engine::journal(0)); // прежний минимум limit=1
    }
    public function test_queue_reasons_dead_and_stats_are_read_only(): void
    {
        $pending = $this->outbox('pending');
        $this->outbox('sending');
        $failed = $this->outbox('failed');
        $dead = $this->outbox('dead');
        $this->db->query(Database::UPDATE, "UPDATE prx_597_outbox SET next_attempt_at = now() + interval '1 hour', last_error = 'Тихие часы' WHERE id = ".$pending, FALSE);
        $before = $this->db->query(Database::SELECT, 'SELECT * FROM prx_597_outbox ORDER BY id', FALSE)->as_array();
        $rows = Service_Prx597_Engine::queue();
        $this->assertCount(2, $rows);
        $this->assertSame($pending, (int) $rows[1]['id']);
        $this->assertSame('Тихие часы', $rows[1]['reason']);
        $this->assertSame([$dead, $failed], array_map('intval', array_column(Service_Prx597_Engine::dead(), 'id')));
        $stats = Service_Prx597_Engine::stats();
        $this->assertSame(2, $stats['pending']);
        $this->assertSame(1, $stats['dead']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['deferred']);
        $this->assertSame($before, $this->db->query(Database::SELECT, 'SELECT * FROM prx_597_outbox ORDER BY id', FALSE)->as_array());
    }
    public function test_inbox_and_feed_keep_time_windows_and_group_counts(): void
    {
        foreach ([['a', 0], ['a', 2], ['b', 0], ['old', 200]] as [$event, $hours])
            $this->db->query(Database::INSERT, 'INSERT INTO prx_597_notifications (user_id, event_alias, created_at) VALUES (17, '
                .$this->db->quote($event).", now() - interval '".$hours." hours')", FALSE);
        $this->assertCount(3, Service_Prx597_Engine::recent_inbox());
        $feed = Service_Prx597_Engine::feed_events(7, 12);
        $this->assertCount(2, $feed);
        $this->assertSame('a', $feed[0]['event_alias']);
        $this->assertSame(2, (int) $feed[0]['c']);
        $this->assertSame(1, (int) $feed[0]['c_hour']);
    }
}
