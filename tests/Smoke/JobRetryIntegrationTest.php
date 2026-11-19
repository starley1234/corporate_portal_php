<?php
use PHPUnit\Framework\TestCase;

class JobRetryIntegrationTest extends TestCase
{
    public function test_temporary_failure_requeues_with_backoff_in_php(): void
    {
        $db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
        $queue = Service_Prx120_JobQueue::instance();
        $channel = 'test.retry.'.bin2hex(random_bytes(5));
        $worker = 'php-retry-test';
        $db->begin();
        try {
            $id = Service_Prx120_JobQueue::push($channel, [], ['max_attempts' => 3]);
            $claimed = $queue->claim([$channel], $worker);
            $this->assertSame((int) $id, (int) $claimed['id']);
            $this->assertTrue($queue->fail($id, $worker, 'DocumentJobError: ocr_unavailable', TRUE));
            $this->assertSame('pending', $queue->get($id)['status']);
            $this->assertNull($queue->claim([$channel], $worker));
            $delay = DB::query(Database::SELECT, 'SELECT (available_at > now() + interval \'10 seconds\')::int AS delayed '
                .'FROM prx_120_jobs WHERE id = :id')->param(':id', $id)->execute($db)->get('delayed');
            $this->assertSame(1, (int) $delay);
        } finally {
            $db->rollback();
        }
    }
}
