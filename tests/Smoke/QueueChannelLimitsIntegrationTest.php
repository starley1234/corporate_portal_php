<?php
use PHPUnit\Framework\TestCase;

/**
 * prx120: лимиты каналов (prx_120_channel_limits), повтор упавших,
 * сводка каналов. Всё в транзакции с откатом — очередь стенда не трогаем.
 */
class QueueChannelLimitsIntegrationTest extends TestCase
{
    /** @var Database */
    protected $db;

    protected function setUp(): void
    {
        $this->db = Database::instance(Kohana::$config->load('prx120_jobs.db_group'));
        $this->db->begin();
    }

    protected function tearDown(): void
    {
        $this->db->rollback();
    }

    protected function channel_name(string $tag): string
    {
        return 'test.limit.'.$tag.'.'.bin2hex(random_bytes(4));
    }

    public function test_max_concurrent_holds_second_job_until_first_finishes(): void
    {
        $queue = Service_Prx120_JobQueue::instance();
        $ch    = $this->channel_name('conc');
        Service_Prx120_Channels::set_limit($ch, 1, 0, 0);

        $a = Service_Prx120_JobQueue::push($ch, ['n' => 1]);
        $b = Service_Prx120_JobQueue::push($ch, ['n' => 2]);

        $first = $queue->claim([$ch], 'w1');
        $this->assertSame((int) $a, (int) $first['id']);
        $this->assertNull($queue->claim([$ch], 'w2'), 'второй захват при max_concurrent=1');

        $this->assertTrue((bool) $queue->complete($a, 'w1', ['ok' => TRUE]));
        $second = $queue->claim([$ch], 'w2');
        $this->assertSame((int) $b, (int) $second['id']);
    }

    public function test_blocked_channel_does_not_stall_other_channels(): void
    {
        $queue = Service_Prx120_JobQueue::instance();
        $slow  = $this->channel_name('slow');
        $free  = $this->channel_name('free');
        Service_Prx120_Channels::set_limit($slow, 0, 3600, 0);

        $s1 = Service_Prx120_JobQueue::push($slow, [], ['priority' => 1]);
        $s2 = Service_Prx120_JobQueue::push($slow, [], ['priority' => 1]);
        $f1 = Service_Prx120_JobQueue::push($free, [], ['priority' => 50]);

        $this->assertSame((int) $s1, (int) $queue->claim([$slow, $free], 'w1')['id']);
        // s2 приоритетнее, но канал взят меньше часа назад — очередь идёт дальше.
        $this->assertSame((int) $f1, (int) $queue->claim([$slow, $free], 'w2')['id']);
        $this->assertNull($queue->claim([$slow, $free], 'w3'));
        $this->assertSame('pending', $queue->get($s2)['status']);
    }

    public function test_racing_claim_over_limit_is_returned_to_queue(): void
    {
        $queue = Service_Prx120_JobQueue::instance();
        $ch    = $this->channel_name('race');

        $a = Service_Prx120_JobQueue::push($ch, []);
        $b = Service_Prx120_JobQueue::push($ch, []);
        // Оба захвата прошли до появления лимита — как два воркера, одновременно
        // прошедшие фильтр выбора.
        $queue->claim([$ch], 'w1');
        $queue->claim([$ch], 'w2');
        Service_Prx120_Channels::set_limit($ch, 1, 0, 0);

        $keep = new ReflectionMethod($queue, '_keep_limited_claim');
        $keep->setAccessible(TRUE);
        $this->assertFalse($keep->invoke($queue, (int) $b, $ch), 'лишний захват возвращается');

        $job = $queue->get($b);
        $this->assertSame('pending', $job['status']);
        $this->assertSame(0, (int) $job['attempts'], 'попытка не сгорает');
        $this->assertTrue(empty($job['claimed_by']), 'захват снят');
        $this->assertTrue($keep->invoke($queue, (int) $a, $ch), 'первый захват остаётся');
    }

    public function test_zero_limits_remove_row_and_bounds_are_checked(): void
    {
        $ch = $this->channel_name('row');
        Service_Prx120_Channels::set_limit($ch, 2, 10, 0);
        $this->assertSame(['max_concurrent' => 2, 'min_interval_sec' => 10], Service_Prx120_Channels::limits()[$ch]);

        Service_Prx120_Channels::set_limit($ch, 0, 0, 0);
        $this->assertArrayNotHasKey($ch, Service_Prx120_Channels::limits());

        foreach ([[-1, 0], [0, 86401], [1001, 0]] as [$m, $i])
        {
            try
            {
                Service_Prx120_Channels::set_limit($ch, $m, $i, 0);
                $this->fail('ожидалось InvalidArgumentException для '.$m.'/'.$i);
            }
            catch (InvalidArgumentException $e)
            {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_retry_failed_requeues_each_failed_job_once(): void
    {
        $queue = Service_Prx120_JobQueue::instance();
        $ch    = $this->channel_name('retry');

        $id = Service_Prx120_JobQueue::push($ch, ['doc' => 7], ['max_attempts' => 1]);
        $queue->claim([$ch], 'w1');
        $queue->fail($id, 'w1', 'boom', FALSE);
        $this->assertSame('failed', $queue->get($id)['status']);

        $row = $this->overview_row($ch);
        $this->assertSame(1, $row['retryable']);
        $this->assertSame(1, $row['failed_24h']);

        $new = Service_Prx120_Channels::retry_failed($ch);
        $this->assertCount(1, $new);
        $copy = $queue->get($new[0]);
        $this->assertSame('pending', $copy['status']);
        $payload = is_string($copy['payload']) ? json_decode($copy['payload'], TRUE) : $copy['payload'];
        $this->assertSame(7, (int) $payload['doc']);

        $this->assertSame([], Service_Prx120_Channels::retry_failed($ch), 'второй раз не повторяем');
        $this->assertSame(0, $this->overview_row($ch)['retryable']);
        $this->assertSame(1, $this->overview_row($ch)['pending']);
    }

    protected function overview_row(string $ch): array
    {
        foreach (Service_Prx120_Channels::overview() as $row)
        {
            if ($row['channel'] === $ch)
            {
                return $row;
            }
        }

        $this->fail('канала '.$ch.' нет в сводке');
    }
}
