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

/** Фундамент доступа: actor, policy, глобальный CSRF-guard и публикация событий. */
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
        $db = $this->journal_db();
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

    private function journal_db()
    {
        try {
            $db = Database::instance();
            $db->query(Database::SELECT, 'SELECT 1 FROM prx_208_events LIMIT 1', FALSE);
            return $db;
        } catch (Throwable $e) {
            return NULL;
        }
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

            protected function scope(Prx_Actor $actor, array $row): bool
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
