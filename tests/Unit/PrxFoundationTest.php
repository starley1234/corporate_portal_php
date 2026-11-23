<?php
use PHPUnit\Framework\TestCase;

/** Шина не подключена: publish() предупреждает и возвращается, не роняя запрос. */
class Event_Test_Without_Bus extends Event
{
    protected static function bus()
    {
        return NULL;
    }
}

/** Фундамент доступа: actor, policy, глобальный CSRF-guard, события и фасад Storage. */
class PrxFoundationTest extends TestCase
{
    // ---- События: Event::publish ------------------------------------------------

    public function test_event_publish_reaches_listener_with_name_and_initiator(): void
    {
        $received = NULL;
        $event = 'prx999.test.'.bin2hex(random_bytes(4));
        Event::listen($event, function ($payload, $name, $initiator_id) use (&$received) {
            $received = [$payload, $name, $initiator_id];
        });

        // Журнал шины пишет в prx_208_events: с базой — в тестовой транзакции,
        // без базы шина сама глушит ошибку журнала, слушатель всё равно получает payload.
        $db = $this->db_with('prx_208_events');
        if ($db !== NULL) {
            $db->begin();
        }
        try {
            Event::publish($event, ['claim_id' => 7, 'tags' => ['a', 'b'], 'note' => NULL], 42);
        } finally {
            if ($db !== NULL) {
                $db->rollback();
            }
        }

        $this->assertSame([['claim_id' => 7, 'tags' => ['a', 'b'], 'note' => NULL], $event, 42], $received);
        $this->assertTrue(Event::has($event));
    }

    public function test_event_publish_rejects_bad_name_and_non_scalar_payload(): void
    {
        $stream = fopen('php://memory', 'r');
        $cases = [
            ['module.updated', ['id' => 1]],                           // без prxNNN
            ['prx999', ['id' => 1]],                                   // без entity.action
            ['prx999.test.published', ['model' => new stdClass]],      // объект
            ['prx999.test.published', ['nested' => ['model' => new stdClass]]],
            ['prx999.test.published', ['cb' => function () {}]],
            ['prx999.test.published', ['stream' => $stream]],
        ];
        try {
            foreach ($cases as [$name, $payload]) {
                try {
                    Event::publish($name, $payload);
                    $this->fail('Ожидался InvalidArgumentException для '.$name);
                } catch (InvalidArgumentException $e) {
                    $this->assertNotSame('', $e->getMessage());
                }
            }
        } finally {
            fclose($stream);
        }

        try {
            Event::listen('system.shutdown', function () {});
            $this->fail('Слушатель с именем вне контракта не может никогда сработать');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_event_publish_without_bus_warns_and_returns(): void
    {
        Event_Test_Without_Bus::publish('prx999.test.no_bus', ['id' => 1], 5); // без исключения
        Event_Test_Without_Bus::listen('prx999.test.no_bus', function () {});
        $this->assertFalse(Event_Test_Without_Bus::has('prx999.test.no_bus'));
    }

    /** Соединение с БД, если таблица есть; без БД — NULL (тест пропускается). */
    private function db_with(string $table)
    {
        try {
            $db = Database::instance();
            $db->query(Database::SELECT, 'SELECT 1 FROM '.$table.' LIMIT 1', FALSE);
            return $db;
        } catch (Throwable $e) {
            return NULL;
        }
    }

    // ---- Файлы: Storage:: --------------------------------------------------------

    /** Настоящий prx212: транзакция и временный backend, чтобы не задевать стенд. */
    public function test_storage_save_bytes_metadata_and_delete_owned(): void
    {
        $db = $this->db_with('prx_212_storage_backends');
        if ($db === NULL) $this->markTestSkipped('Нет БД: Storage::save/bytes/metadata не проверены');

        $root = sys_get_temp_dir().'/prx_storage_test_'.bin2hex(random_bytes(4));
        $db->begin();
        try
        {
            $db->query(Database::UPDATE, 'UPDATE prx_212_storage_backends SET is_active = 0', FALSE);
            $db->query(Database::INSERT, 'INSERT INTO prx_212_storage_backends (name, driver, config_data, is_active) VALUES ('
                .$db->quote('prx_test_'.bin2hex(random_bytes(4))).", 'local', "
                .$db->quote(json_encode(['root_path' => $root])).', 1)', FALSE);

            $source = tempnam(sys_get_temp_dir(), 'prx_src_');
            file_put_contents($source, '%PDF-1.4 prx storage test');
            $file_id = Storage::save([
                'tmp_name' => $source, 'name' => 'test.pdf',
                'size' => filesize($source), 'type' => 'application/pdf',
            ], 'prx999');
            unlink($source);

            $this->assertSame($file_id, (int) $file_id);
            $this->assertGreaterThan(0, $file_id);
            $this->assertSame('%PDF-1.4 prx storage test', Storage::bytes($file_id));
            $this->assertNull(Storage::bytes(-1));

            $meta = Storage::metadata($file_id);
            $this->assertSame(Storage::META_KEYS, array_keys($meta));
            $this->assertSame('prx999', $meta['module']);
            $this->assertSame('test.pdf', $meta['filename']);
            $this->assertSame(25, $meta['size']);
            $this->assertFalse($meta['is_public']);
            $this->assertNull($meta['url']);

            $ids = Storage::owned_ids_before('prx999', 48);
            $this->assertSame($ids, array_map('intval', $ids));

            $this->assertTrue(Storage::delete_owned($file_id, 'prx999'));
            $this->assertNull(Storage::bytes($file_id));
            $this->assertTrue(Storage::delete_owned($file_id, 'prx999')); // повтор безопасен
        }
        finally
        {
            $db->rollback();
            self::remove_tree($root);
        }
    }

    public function test_storage_rejects_bad_module_with_storage_exception(): void
    {
        $file = ['tmp_name' => __FILE__, 'name' => 'x.pdf', 'size' => 1, 'type' => 'application/pdf'];
        try
        {
            Storage::save($file, 'bad module!');
            $this->fail('Ожидался Storage_Exception для save()');
        }
        catch (Storage_Exception $e)
        {
            $this->assertNotSame('', $e->getMessage());
        }

        try
        {
            Storage::put('x', 'x.txt', 'prx999/../x');
            $this->fail('Ожидался Storage_Exception для put()');
        }
        catch (Storage_Exception $e)
        {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_storage_without_prx212_throws_on_write_and_reads_return_null(): void
    {
        // Фасад final: отключение модуля эмулируем через его служебный флаг.
        $flag = new ReflectionProperty(Storage::class, 'module_missing');
        $flag->setValue(NULL, TRUE);
        try
        {
            $writes = [
                'save' => fn () => Storage::save([], 'prx999'),
                'put' => fn () => Storage::put('x', 'x.txt', 'prx999'),
                'delete' => fn () => Storage::delete(1, 1),
                'delete_owned' => fn () => Storage::delete_owned(1, 'prx999'),
            ];
            foreach ($writes as $name => $call)
            {
                try
                {
                    $call();
                    $this->fail('Ожидался Storage_Exception: '.$name);
                }
                catch (Storage_Exception $e)
                {
                    $this->assertNotSame('', $e->getMessage());
                }
            }

            $this->assertNull(Storage::bytes(1));
            $this->assertNull(Storage::metadata(1));
            $this->assertNull(Storage::url(1));
            $this->assertNull(Storage::read_owned(1, 'prx999', 10));
            $this->assertSame([], Storage::owned_ids_before('prx999', 1));
        }
        finally
        {
            $flag->setValue(NULL, FALSE);
        }
    }

    private static function remove_tree(string $dir): void
    {
        if ( ! is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry)
        {
            if ($entry === '.' OR $entry === '..') continue;
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? self::remove_tree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_actor_from_array_does_not_promote_guests(): void
    {
        $this->assertFalse(Prx_Actor::fromArray(['roles' => ['admin']])->can('prx391.view'));
        $this->assertFalse(Prx_Actor::fromArray(['user_id' => 0, 'roles' => ['admin']])->can('prx391.view'));
        $this->assertTrue(Prx_Actor::fromArray(['user_id' => 7, 'roles' => ['admin']])->can('prx391.view'));
        $this->assertTrue(Prx_Actor::fromArray(['user_id' => -2])->isGuest());
    }

    public function test_guest_has_no_rights_and_no_org(): void
    {
        $guest = Prx_Actor::guest();

        $this->assertTrue($guest->isGuest());
        $this->assertSame(0, $guest->id());
        $this->assertSame([], $guest->roles());
        $this->assertFalse($guest->can('prx300.view'));
        $this->assertNull($guest->organizationId());
    }

    public function test_actor_keeps_roles_and_org_context(): void
    {
        $actor = new Prx_Actor(42, ['login', 'login', ''], [
            'employee_id' => '7',
            'organization_id' => 3,
            'department_id' => 0,
            'site_id' => NULL,
        ]);

        $this->assertSame(['login'], $actor->roles());
        $this->assertFalse($actor->isGuest());
        $this->assertSame(7, $actor->employeeId());
        $this->assertSame(3, $actor->organizationId());
        $this->assertNull($actor->departmentId());
        $this->assertNull($actor->siteId());
    }

    public function test_policy_denies_guest_before_touching_acl(): void
    {
        $policy = new class extends Prx_Policy {
            protected const VIEW_ACL = 'prx999.view';
        };

        $this->assertFalse($policy->view(Prx_Actor::guest(), ['id' => 1]));
    }

    public function test_policy_grant_admin_and_hide_foreign_row(): void
    {
        $policy = new class extends Prx_Policy {
            protected const VIEW_ACL = 'prx999.view';

            protected function scope(Prx_Principal $actor, array $row): bool
            {
                return (int) ($row['organization_id'] ?? 0) === 9;
            }
        };

        $this->assertTrue($policy->view(new Prx_Actor(1, ['admin']), ['id' => 1, 'organization_id' => 9]));
        $this->assertFalse($policy->view(new Prx_Actor(1, ['login']), ['id' => 1, 'organization_id' => 8]));
    }

    public function test_post_guard_allows_safe_methods(): void
    {
        $request = Request::factory('about');
        Prx_PostGuard::assert_safe($request); // GET — без исключения
        $this->assertSame('GET', $request->method());
    }

    public function test_post_guard_rejects_post_without_token(): void
    {
        $request = Request::factory('employee');
        $request->method(Request::POST);

        try {
            Prx_PostGuard::assert_safe($request);
            $this->fail('Ожидался 403');
        } catch (HTTP_Exception_403 $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_post_guard_allows_webhook_and_machine_credentials(): void
    {
        $webhook = Request::factory('prx597/hook/telegram');
        $webhook->method(Request::POST);
        Prx_PostGuard::assert_safe($webhook);

        // Заголовок ставим в сам запрос: у созданного через factory() запроса
        // нет ленивой загрузки заголовков (она только у initial).
        $api = Request::factory('api/v1/document-extractions/1');
        $api->method(Request::POST);
        $api->headers('Authorization', 'Bearer test-token');
        Prx_PostGuard::assert_safe($api);

        $this->assertSame(Request::POST, $api->method());
    }
}
