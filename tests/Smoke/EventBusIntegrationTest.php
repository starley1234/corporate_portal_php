<?php
use PHPUnit\Framework\TestCase;

/** Отдельная шина: проверяем мост, не создавая реальные уведомления. */
class Prx208TestBus extends Service_Prx208_EventBus
{
    public $notifications = [];

    public function __construct(bool $load_db_subscribers = TRUE)
    {
        $group = Kohana::$config->load('prx208_events');
        $old = $group->get('event_bus');
        $config = $old;
        $config['load_db_subscribers'] = $load_db_subscribers;
        $group->set('event_bus', $config);
        try {
            parent::__construct();
        } finally {
            $group->set('event_bus', $old);
        }
    }

    protected function _bridge_to_prx597($payload, $event_name, $initiator_id)
    {
        $this->notifications[] = [$payload, $event_name, $initiator_id];
    }
}

class Prx208TestSubscriber
{
    public static $calls = [];

    public static function receive($payload, $event_name, $initiator_id)
    {
        self::$calls[] = [$payload, $event_name, $initiator_id];
    }

    public static function throw_error($payload, $event_name, $initiator_id)
    {
        throw new Error('private-listener-error-29');
    }

    public function instance_only($payload, $event_name, $initiator_id) {}
    private static function hidden($payload, $event_name, $initiator_id) {}
}

class Prx208TestBrokenSubscriber
{
    public function __construct()
    {
        throw new Error('test constructor failure');
    }

    public function receive($payload, $event_name, $initiator_id) {}
}

/** Проверка реальной загрузки подписок через PostgreSQL, а не мок ORM. */
class EventBusIntegrationTest extends TestCase
{
    private function insert_subscription(string $event, ?string $callback, bool $active, bool $notification): int
    {
        $rows = DB::query(Database::SELECT,
            'INSERT INTO prx_208_event_subscribers (event_name, module_id, callback_method, is_active, is_notification) '
            .'VALUES (:event, :module, :callback, '.($active ? 'TRUE' : 'FALSE').', '.($notification ? 'TRUE' : 'FALSE').') RETURNING id')
            ->parameters([':event' => $event, ':module' => 'integration-test', ':callback' => $callback])
            ->execute()->as_array();

        return (int) $rows[0]['id'];
    }

    public function test_database_subscriptions_are_opt_in_but_in_memory_listeners_still_work(): void
    {
        $old_flag = getenv('PRX208_DB_SUBSCRIBERS_ENABLED');
        try {
            putenv('PRX208_DB_SUBSCRIBERS_ENABLED');
            $config = include APPPATH.'config/prx208_events.php';
            $this->assertFalse($config['event_bus']['load_db_subscribers']);
            putenv('PRX208_DB_SUBSCRIBERS_ENABLED=0');
            $config = include APPPATH.'config/prx208_events.php';
            $this->assertFalse($config['event_bus']['load_db_subscribers']);
            putenv('PRX208_DB_SUBSCRIBERS_ENABLED=1');
            $config = include APPPATH.'config/prx208_events.php';
            $this->assertTrue($config['event_bus']['load_db_subscribers']);
        } finally {
            $old_flag === FALSE ? putenv('PRX208_DB_SUBSCRIBERS_ENABLED') : putenv('PRX208_DB_SUBSCRIBERS_ENABLED='.$old_flag);
        }

        Event::has('prx208.test.initialized');
        $db = Database::instance();
        $db->begin();
        try {
            $event = 'prx208.test.'.bin2hex(random_bytes(6));
            $this->insert_subscription($event, 'Prx208TestSubscriber::receive', TRUE, FALSE);
            $bus = new Prx208TestBus(FALSE);
            $this->assertFalse($bus->has_listeners($event));
            Prx208TestSubscriber::$calls = [];
            $bus->listen($event, ['Prx208TestSubscriber', 'receive']);
            $this->assertTrue($bus->publish($event, ['value' => 1], NULL));
            $this->assertCount(1, Prx208TestSubscriber::$calls);
        } finally {
            $db->rollback();
            Prx208TestSubscriber::$calls = [];
        }
    }

    public function test_subscriber_flags_save_and_validation_preserves_form_selection(): void
    {
        Event::has('prx208.test.initialized');
        $db = Database::instance();
        $db->begin();
        try {
            $subscriber = ORM::factory('Prx208_Subscriber');
            $subscriber->event_name = 'prx208.test.'.bin2hex(random_bytes(6));
            $subscriber->module_id = 'integration-test';
            $subscriber->callback_method = 'Prx208TestSubscriber::receive';
            $subscriber->is_active = FALSE;
            $subscriber->is_notification = FALSE;
            $subscriber->save();
            $reloaded = ORM::factory('Prx208_Subscriber', $subscriber->id);
            $this->assertFalse($reloaded->is_active);
            $this->assertFalse($reloaded->is_notification);
            $reloaded->is_active = TRUE;
            $reloaded->is_notification = TRUE;
            $reloaded->save();
            $reloaded = ORM::factory('Prx208_Subscriber', $subscriber->id);
            $this->assertTrue($reloaded->is_active);
            $this->assertTrue($reloaded->is_notification);
            $reloaded->is_active = FALSE;
            $reloaded->is_notification = FALSE;
            $reloaded->save();
            $reloaded = ORM::factory('Prx208_Subscriber', $subscriber->id);
            $this->assertFalse($reloaded->is_active);
            $this->assertFalse($reloaded->is_notification);

            $invalid = ORM::factory('Prx208_Subscriber');
            $invalid->event_name = '';
            $invalid->is_active = FALSE;
            $invalid->is_notification = TRUE;
            $errors = [];
            try {
                $invalid->save();
                $this->fail('Пустое событие должно отклоняться');
            } catch (ORM_Validation_Exception $e) {
                $errors = $e->errors('models');
            }
            $this->assertArrayHasKey('event_name', $errors);
            $this->assertFalse(isset($errors['is_active']));
            $html = View::factory('prx208/subscriber/form', [
                'subscriber' => $invalid, 'available_events' => [], 'available_modules' => [],
                'errors' => $errors, 'db_subscribers_enabled' => FALSE, 'csrf' => 'test',
            ])->render();
            preg_match('/<input\\b[^>]*\\bid="is_active"[^>]*>/s', $html, $active);
            preg_match('/<input\\b[^>]*\\bid="is_notification"[^>]*>/s', $html, $notification);
            $this->assertNotEmpty($active);
            $this->assertNotEmpty($notification);
            $this->assertStringNotContainsString('checked', $active[0]);
            $this->assertStringContainsString('checked', $notification[0]);

            $invalid_flag = ORM::factory('Prx208_Subscriber');
            $invalid_flag->event_name = 'prx208.test.invalid-flag';
            $invalid_flag->is_active = 'not-a-boolean';
            $invalid_flag->is_notification = TRUE;
            $flag_errors = [];
            try {
                $invalid_flag->save();
                $this->fail('Некорректный флаг должен отклоняться');
            } catch (ORM_Validation_Exception $e) {
                $flag_errors = $e->errors('models');
            }
            $this->assertArrayHasKey('is_active', $flag_errors);
        } finally {
            $db->rollback();
        }
    }

    public function test_active_callbacks_load_and_only_explicit_notifications_are_bridged(): void
    {
        Event::has('prx208.test.initialized');
        $db = Database::instance();
        $db->begin();
        try {
            $prefix = 'prx208.test.'.bin2hex(random_bytes(6));
            $callback = $prefix.'.callback';
            $inactive = $prefix.'.inactive';
            $notification = $prefix.'.notification';
            $inactive_notification = $prefix.'.inactive_notification';
            $active_id = $this->insert_subscription($callback, 'Prx208TestSubscriber::receive', TRUE, FALSE);
            $inactive_id = $this->insert_subscription($inactive, 'Prx208TestSubscriber::receive', FALSE, FALSE);
            $this->insert_subscription($notification, NULL, TRUE, TRUE);
            $this->insert_subscription($inactive_notification, NULL, FALSE, TRUE);

            $active_model = ORM::factory('Prx208_Subscriber', $active_id);
            $inactive_model = ORM::factory('Prx208_Subscriber', $inactive_id);
            $this->assertSame(TRUE, $active_model->is_active);
            $this->assertSame(FALSE, $active_model->is_notification);
            $this->assertSame(FALSE, $inactive_model->is_active);

            Prx208TestSubscriber::$calls = [];
            $bus = new Prx208TestBus();
            $payload = ['target_user_id' => 123, 'value' => 'test'];
            $this->assertTrue($bus->publish($callback, $payload, 17));
            $this->assertSame([[$payload, $callback, 17]], Prx208TestSubscriber::$calls);
            $this->assertSame([], $bus->notifications);
            $this->assertFalse($bus->publish($inactive, $payload, 17));
            $this->assertFalse($bus->publish($inactive_notification, $payload, 17));
            $this->assertCount(1, Prx208TestSubscriber::$calls);

            $notification_payload = ['target_user_ids' => [123]];
            $this->assertTrue($bus->publish($notification, $notification_payload, 17));
            $this->assertSame([[$notification_payload, $notification, 17]], $bus->notifications);
        } finally {
            $db->rollback();
            Prx208TestSubscriber::$calls = [];
        }
    }

    public function test_invalid_callbacks_do_not_register_or_crash_initialization(): void
    {
        Event::has('prx208.test.initialized');
        $db = Database::instance();
        $db->begin();
        $autoload = static function ($class) {
            if ($class === 'Prx208TestAutoloadFailure') {
                throw new Error('private-autoload-error-81');
            }
        };
        spl_autoload_register($autoload);
        try {
            $prefix = 'prx208.test.'.bin2hex(random_bytes(6));
            $this->insert_subscription($prefix.'.private', 'Prx208TestSubscriber::hidden', TRUE, FALSE);
            $this->insert_subscription($prefix.'.instance', 'Prx208TestSubscriber::instance_only', TRUE, FALSE);
            $this->insert_subscription($prefix.'.constructor', 'Prx208TestBrokenSubscriber->receive', TRUE, FALSE);
            $this->insert_subscription($prefix.'.autoload', 'Prx208TestAutoloadFailure::receive', TRUE, FALSE);
            $this->insert_subscription($prefix.'.valid', 'Prx208TestSubscriber::receive', TRUE, FALSE);

            $bus = new Prx208TestBus();
            $this->assertFalse($bus->has_listeners($prefix.'.private'));
            $this->assertFalse($bus->has_listeners($prefix.'.instance'));
            $this->assertFalse($bus->has_listeners($prefix.'.constructor'));
            $this->assertFalse($bus->has_listeners($prefix.'.autoload'));
            $this->assertTrue($bus->has_listeners($prefix.'.valid'));
            $this->assertFalse($bus->publish($prefix.'.private', [], NULL));
        } finally {
            spl_autoload_unregister($autoload);
            $db->rollback();
        }
    }

    public function test_listener_error_is_isolated_and_log_contains_no_exception_text(): void
    {
        Event::has('prx208.test.initialized');
        $path = tempnam(sys_get_temp_dir(), 'prx208-diag-');
        $old_log_enabled = getenv('PRX_LOG_ENABLED');
        putenv('PRX_LOG_ENABLED=1');
        $writer = new Log_Plain($path, 0, 0, Log::INFO);
        Kohana::$log->attach($writer);
        $db = Database::instance();
        $db->begin();
        try {
            $event = 'prx208.test.'.bin2hex(random_bytes(6));
            $this->insert_subscription($event, 'Prx208TestSubscriber::throw_error', TRUE, FALSE);
            $bus = new Prx208TestBus();
            $bus->listen($event, ['Prx208TestSubscriber', 'receive']);
            Prx208TestSubscriber::$calls = [];
            $payload = ['value' => 'private-payload-37'];
            $this->assertTrue($bus->publish($event, $payload, NULL));
            $this->assertSame([[$payload, $event, NULL]], Prx208TestSubscriber::$calls);

            $lines = preg_grep('/prx208_diag/', explode("\n", file_get_contents($path)));
            $diagnostics = implode("\n", $lines);
            $this->assertStringContainsString('stage=listener result=error error_type=Error', $diagnostics);
            $this->assertStringNotContainsString('private-listener-error-29', $diagnostics);
            $this->assertStringNotContainsString('private-payload-37', $diagnostics);
        } finally {
            $db->rollback();
            Prx208TestSubscriber::$calls = [];
            Kohana::$log->detach($writer);
            $old_log_enabled === FALSE ? putenv('PRX_LOG_ENABLED') : putenv('PRX_LOG_ENABLED='.$old_log_enabled);
            unlink($path);
        }
    }

    public function test_subscriber_load_failure_is_logged_without_sql(): void
    {
        Event::has('prx208.test.initialized');
        $path = tempnam(sys_get_temp_dir(), 'prx208-diag-');
        $old_log_enabled = getenv('PRX_LOG_ENABLED');
        putenv('PRX_LOG_ENABLED=1');
        $writer = new Log_Plain($path, 0, 0, Log::INFO);
        Kohana::$log->attach($writer);
        $db = Database::instance();
        $db->begin();
        try {
            DB::query(Database::UPDATE,
                'ALTER TABLE prx_208_event_subscribers RENAME TO prx_208_subscribers_test_hidden')->execute();
            $bus = new Prx208TestBus();
            $this->assertFalse($bus->has_listeners('prx208.test.missing'));
            $lines = preg_grep('/prx208_diag/', explode("\n", file_get_contents($path)));
            $diagnostics = implode("\n", $lines);
            $this->assertStringContainsString('stage=subscribers result=error error_type=Database_Exception', $diagnostics);
            $this->assertStringNotContainsString('SELECT ', $diagnostics);
            $this->assertStringNotContainsString('prx_208_event_subscribers', $diagnostics);
        } finally {
            $db->rollback();
            Kohana::$log->detach($writer);
            $old_log_enabled === FALSE ? putenv('PRX_LOG_ENABLED') : putenv('PRX_LOG_ENABLED='.$old_log_enabled);
            unlink($path);
        }
    }
}
