<?php
use PHPUnit\Framework\TestCase;

/** Выполнение платформенных контрактов на чистой мигрированной PostgreSQL. */
class PortalFoundationTest extends TestCase {
    public function test_migrations_have_no_pending_work(): void
    {
        $this->assertSame([], Service_Prx111_Migrator::instance()->pending());
    }

    public function test_echo_queue_claim_heartbeat_complete(): void
    {
        $queue = Service_Prx120_JobQueue::instance();
        $channel = 'portal.test.'.bin2hex(random_bytes(4));
        $id = Service_Prx120_JobQueue::push($channel, ['message' => 'PRX'], ['module' => 'prx100_platform']);
        try
        {
            $job = $queue->claim([$channel], 'portal-test-worker');
            $this->assertSame($id, (int) $job['id']);
            $this->assertTrue((bool) $queue->heartbeat($id, 'portal-test-worker'));
            $this->assertFalse((bool) $queue->complete($id, 'wrong-worker', ['ok' => TRUE]));
            $this->assertTrue((bool) $queue->complete($id, 'portal-test-worker', ['ok' => TRUE]));
            $done = $queue->get($id);
            $this->assertSame('done', $done['status']);
            $this->assertTrue((bool) $done['result']['ok']);
        }
        finally
        {
            $queue->delete_job($id);
        }
    }

    public function test_release_mine_executes_both_recovery_queries(): void
    {
        $this->assertSame(0, Service_Prx120_JobQueue::instance()->release_mine('no-such-test-worker-'.bin2hex(random_bytes(6))));
    }

    public function test_in_app_notification_is_scoped_to_its_recipient(): void
    {
        $db = Database::instance();
        $suffix = bin2hex(random_bytes(6));
        $user = ORM::factory('User');
        $user->email = 'test-'.$suffix.'@example.test';
        $user->username = 'test-'.$suffix;
        $user->password = bin2hex(random_bytes(16));
        $user->create();
        $uid = (int) $user->id;
        try
        {
            $nid = Service_Prx597_Notify::inbox_add($uid, ['title' => 'PRX test', 'body' => 'Test only', 'href' => '/tasks']);
            $this->assertGreaterThan(0, $nid);
            $rows = Service_Prx597_Notify::latest($uid);
            $this->assertSame($nid, (int) $rows[0]['id']);
            Service_Prx597_Notify::mark_read($uid, [$nid]);
            $this->assertSame(0, Service_Prx597_Notify::unread_count($uid));
        }
        finally
        {
            $db->query(NULL, 'DELETE FROM prx_597_notifications WHERE user_id = '.$uid);
            $user->delete();
        }
    }

    public function test_rag_remember_exact_and_delete(): void
    {
        $key = 'test-'.bin2hex(random_bytes(8));
        $stored = Service_Prx560_Knowledge::remember('prx100_platform', 'test', $key, 'PRX test', 'Technical test only', [], NULL, FALSE);
        try
        {
            $exact = Service_Prx560_Knowledge::find_exact('prx100_platform', $key);
            $this->assertNotEmpty($exact);
            $this->assertSame('PRX test', $exact['title']);
            $again = Service_Prx560_Knowledge::remember('prx100_platform', 'test', $key, 'PRX test', 'Technical test only', [], NULL, FALSE);
            $this->assertSame('known', $again['status']);
        }
        finally
        {
            Service_Prx560_Knowledge::delete($stored['id']);
        }
    }

    public function test_event_bus_preserves_payload(): void
    {
        $bus = Service_Prx208_EventBus::instance();
        $event = 'prx100.test.'.bin2hex(random_bytes(4));
        $received = NULL;
        $bus->listen($event, function ($payload) use (&$received) { $received = $payload; });
        try
        {
            $bus->publish($event, ['message' => 'PRX']);
            $this->assertSame(['message' => 'PRX'], $received);
        }
        finally
        {
            $bus->forget($event);
        }
    }
}
