<?php
use PHPUnit\Framework\TestCase;

class Prx208JobTestBus extends Service_Prx208_EventBus
{
    public function __construct()
    {
        parent::__construct();
    }
}

/** При выключенных DB-подписках старый мост не дублирует уведомления очереди. */
class JobNotificationsIntegrationTest extends TestCase
{
    private function create_user(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $rows = DB::query(Database::SELECT,
            'INSERT INTO users (username, email, password, logins, email_confirmed, failed_login_attempts, created_at, updated_at) '
            .'VALUES (:name, :email, :password, 0, 1, 0, now(), now()) RETURNING id')
            ->parameters([':name' => 'test-'.$suffix, ':email' => $suffix.'@example.test', ':password' => 'not-a-login'])
            ->execute()->as_array();
        $id = (int) $rows[0]['id'];
        DB::query(Database::INSERT,
            "INSERT INTO roles_users (user_id, role_id) SELECT :id, id FROM roles WHERE name = 'login'")
            ->param(':id', $id)->execute();
        return $id;
    }

    public function test_enabled_bridge_sends_only_to_explicit_targets_once(): void
    {
        Event::has('prx120.job_done');
        $db = Database::instance();
        $db->begin();
        $group = Kohana::$config->load('prx208_events');
        $old_config = $group->get('event_bus');
        try {
            $first = $this->create_user();
            $second = $this->create_user();
            $initiator = $this->create_user();
            $unrelated = $this->create_user();
            $disabled = $this->create_user();
            $cell_disabled = $this->create_user();
            Service_Prx597_Notify::save_prefs($disabled, ['in_app_enabled' => FALSE]);
            $event = 'prx208.test.bridge.'.bin2hex(random_bytes(6));
            $restricted_event = 'prx208.test.restricted.'.bin2hex(random_bytes(6));
            Service_Prx597_Registry::ensure($event, ['roles' => 'login', 'channels' => 'in_app']);
            Service_Prx597_Registry::ensure($restricted_event, ['roles' => 'admin', 'channels' => 'in_app']);
            Service_Prx597_Registry::save_sub($cell_disabled, $event, 'in_app', FALSE);
            DB::query(Database::INSERT,
                'INSERT INTO prx_208_event_subscribers (event_name, module_id, is_active, is_notification) '
                ."VALUES (:event, 'integration-test-1', TRUE, TRUE), (:event, 'integration-test-2', TRUE, TRUE), "
                ."(:restricted, 'integration-test', TRUE, TRUE)")
                ->parameters([':event' => $event, ':restricted' => $restricted_event])->execute();
            $config = $old_config;
            $config['load_db_subscribers'] = TRUE;
            $group->set('event_bus', $config);
            $bus = new Prx208JobTestBus();
            $this->assertTrue($bus->publish($event, [
                'target_user_ids' => [$first, $second, $second, $initiator, $disabled, $cell_disabled, [1], TRUE, 'invalid'],
                'user_id' => $unrelated, 'user_ids' => [$unrelated], 'recipients' => [$unrelated],
                'channels' => ['in_app'], 'initiator_id' => $initiator, 'title' => 'Тест моста',
            ], $initiator));
            $rows = DB::query(Database::SELECT,
                'SELECT user_id FROM prx_597_notifications WHERE event_alias = :event ORDER BY user_id')
                ->param(':event', $event)->execute()->as_array();
            $ids = array_map('intval', array_column($rows, 'user_id'));
            $expected = [$first, $second];
            sort($expected);
            $this->assertSame($expected, $ids);

            // Данные события не должны обходить роль события или выключенную
            // пользователем клетку «событие × канал» через channels.
            $this->assertTrue($bus->publish($restricted_event, [
                'target_user_id' => $first, 'channels' => ['in_app'], 'title' => 'Тест роли',
            ], NULL));
            $restricted = DB::query(Database::SELECT,
                'SELECT count(*) AS n FROM prx_597_notifications WHERE event_alias = :event')
                ->param(':event', $restricted_event)->execute()->get('n');
            $this->assertSame(0, (int) $restricted);

            // Внутренний флаг prx597 не заменяет контракт force_notification.
            $this->assertTrue($bus->publish($event, [
                'target_user_id' => $first, 'initiator_id' => $first, 'force_self' => TRUE,
            ], NULL));
            $same = DB::query(Database::SELECT,
                'SELECT count(*) AS n FROM prx_597_notifications WHERE event_alias = :event')
                ->param(':event', $event)->execute()->get('n');
            $this->assertSame(2, (int) $same);
            $this->assertTrue($bus->publish($event, [
                'target_user_id' => $initiator, 'force_notification' => 'false',
            ], $initiator));
            $same = DB::query(Database::SELECT,
                'SELECT count(*) AS n FROM prx_597_notifications WHERE event_alias = :event')
                ->param(':event', $event)->execute()->get('n');
            $this->assertSame(2, (int) $same);

            // Явный force_notification действительно позволяет доставить
            // инициатору; настройки каналов и роли по-прежнему действуют.
            $this->assertTrue($bus->publish($event, [
                'target_user_id' => $initiator, 'initiator_id' => $initiator,
                'force_notification' => TRUE, 'title' => 'Тест моста',
            ], $initiator));
            $rows = DB::query(Database::SELECT,
                'SELECT user_id FROM prx_597_notifications WHERE event_alias = :event ORDER BY user_id')
                ->param(':event', $event)->execute()->as_array();
            $expected[] = $initiator;
            sort($expected);
            $this->assertSame($expected, array_map('intval', array_column($rows, 'user_id')));
        } finally {
            $group->set('event_bus', $old_config);
            $db->rollback();
            Service_Prx597_Registry::forget_events();
        }
    }

    public function test_sweep_respects_created_by_then_visitor_and_does_not_repeat(): void
    {
        Event::has('prx120.job_done');
        $db = Database::instance();
        $db->begin();
        try {
            $owner = $this->create_user();
            $visitor = $this->create_user();
            $channel = 'test.sweep.'.bin2hex(random_bytes(5));
            $created_by = Service_Prx120_JobQueue::push($channel, [], [
                'created_by' => $owner, 'visitor_id' => 'u:'.$visitor, 'budget' => FALSE,
            ]);
            $visitor_only = Service_Prx120_JobQueue::push($channel, [], [
                'visitor_id' => 'u:'.$visitor, 'budget' => FALSE,
            ]);
            $anonymous = Service_Prx120_JobQueue::push($channel, [], [
                'visitor_id' => 'g:anonymous-test', 'budget' => FALSE,
            ]);
            DB::query(Database::UPDATE,
                "UPDATE prx_120_jobs SET status = 'failed', finished_at = now() WHERE id IN (:one, :two, :three)")
                ->parameters([':one' => $created_by, ':two' => $visitor_only, ':three' => $anonymous])->execute();
            Service_Prx597_Notify::sweep_jobs(20);
            Service_Prx597_Notify::sweep_jobs(20);

            $rows = DB::query(Database::SELECT,
                "SELECT user_id FROM prx_597_notifications WHERE user_id IN (:owner, :visitor) AND event_alias = 'prx120.job.failed' ORDER BY user_id")
                ->parameters([':owner' => $owner, ':visitor' => $visitor])->execute()->as_array();
            $expected = [$owner, $visitor];
            sort($expected);
            $this->assertSame($expected, array_map('intval', array_column($rows, 'user_id')));
            $markers = DB::query(Database::SELECT,
                'SELECT id, notified_at IS NOT NULL AS notified FROM prx_120_jobs WHERE id IN (:one, :two, :three) ORDER BY id')
                ->parameters([':one' => $created_by, ':two' => $visitor_only, ':three' => $anonymous])->execute()->as_array();
            $this->assertSame(['t', 't', 'f'], array_column($markers, 'notified'));
        } finally {
            $db->rollback();
        }
    }

    public function test_owned_job_completion_and_failure_produce_one_notification_each(): void
    {
        // Подменяем singleton только на время теста, даже если в окружении
        // разработчика уже включён opt-in загрузки DB-подписок.
        Event::has('prx120.job_done');
        $group = Kohana::$config->load('prx208_events');
        $old_config = $group->get('event_bus');
        $instance = new ReflectionProperty(Service_Prx208_EventBus::class, '_instance');
        $instance->setAccessible(TRUE);
        $old_bus = $instance->getValue();
        $config = $old_config;
        $config['load_db_subscribers'] = FALSE;
        $group->set('event_bus', $config);
        $instance->setValue(NULL, new Prx208JobTestBus());
        $db = Database::instance();
        $db->begin();
        try {
            $this->assertFalse(Event::has('prx120.job_done'));
            $this->assertFalse(Event::has('prx120.job_failed'));
            $owner = $this->create_user();
            $queue = Service_Prx120_JobQueue::instance();
            $channel = 'test.notifications.'.bin2hex(random_bytes(5));

            $done = Service_Prx120_JobQueue::push($channel, [], ['created_by' => $owner]);
            $claimed = $queue->claim([$channel], 'test-worker');
            $this->assertSame($done, (int) $claimed['id']);
            $this->assertTrue($queue->complete($done, 'test-worker', ['status' => 'ok']));

            $failed = Service_Prx120_JobQueue::push($channel, [], ['created_by' => $owner, 'max_attempts' => 1]);
            $claimed = $queue->claim([$channel], 'test-worker');
            $this->assertSame($failed, (int) $claimed['id']);
            $this->assertTrue($queue->fail($failed, 'test-worker', 'test failure', FALSE));
            Service_Prx597_Notify::sweep_jobs(20);
            Service_Prx597_Notify::sweep_jobs(20);

            $notifications = DB::query(Database::SELECT,
                'SELECT event_alias FROM prx_597_notifications WHERE user_id = :id ORDER BY id')
                ->param(':id', $owner)->execute()->as_array();
            $aliases = array_column($notifications, 'event_alias');
            sort($aliases);
            $this->assertSame(['prx120.job.done', 'prx120.job.failed'], $aliases);
            $events = DB::query(Database::SELECT,
                "SELECT event_name FROM prx_208_events WHERE payload::jsonb->>'job_id' IN (:done, :failed) ORDER BY id")
                ->parameters([':done' => (string) $done, ':failed' => (string) $failed])->execute()->as_array();
            $this->assertSame(['prx120.job_done', 'prx120.job_failed'], array_column($events, 'event_name'));
        } finally {
            $db->rollback();
            $instance->setValue(NULL, $old_bus);
            $group->set('event_bus', $old_config);
        }
    }
}
