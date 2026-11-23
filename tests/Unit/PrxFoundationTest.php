<?php
use PHPUnit\Framework\TestCase;

/** Фундамент доступа: actor, policy и глобальный CSRF-guard. */
class PrxFoundationTest extends TestCase
{
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
