<?php

use PHPUnit\Framework\TestCase;

/**
 * Ключи и реестр scope внешнего API.
 *
 * Запуск: php tests/Support/mini_runner.php Prx150Keys
 */
class Prx150KeysTest extends TestCase
{
    public function test_token_shape_and_hash(): void
    {
        $token = Service_Prx150_Keys::generate();

        $this->assertMatchesRegularExpression('/\Aprx_[a-f0-9]{64}\z/', $token);
        $this->assertSame(64, strlen(Service_Prx150_Keys::hash($token)));
        $this->assertSame(substr($token, 0, 12), Service_Prx150_Keys::prefix($token));
    }

    public function test_two_tokens_are_different(): void
    {
        $this->assertNotSame(Service_Prx150_Keys::generate(), Service_Prx150_Keys::generate());
    }

    public function test_principal_carries_organization_and_scopes(): void
    {
        $principal = Service_Prx150_Keys::principal([
            'id' => 7, 'name' => 'ERP', 'organization_id' => 3,
            'scopes' => '["prx450.parse","prx395.extract"]',
        ]);

        $this->assertTrue($principal instanceof Prx_Principal);
        $this->assertSame(7, $principal->id());
        $this->assertSame(3, $principal->organizationId());
        $this->assertSame(['prx450.parse', 'prx395.extract'], $principal->scopes());
    }

    public function test_principal_without_organization_returns_null(): void
    {
        $principal = Service_Prx150_Keys::principal(['id' => 1, 'name' => 'x', 'scopes' => '[]']);

        $this->assertNull($principal->organizationId());
        $this->assertSame([], $principal->scopes());
    }

    public function test_human_actor_is_a_principal_too(): void
    {
        $this->assertTrue(Prx_Actor::guest() instanceof Prx_Principal);
        $this->assertNull(Prx_Actor::guest()->organizationId());
    }

    public function test_scope_registry_reads_manifest(): void
    {
        $scopes = Service_Prx150_Services::scopes();

        $this->assertArrayHasKey('prx450.parse', $scopes);
        $this->assertSame('prx450_document_ocr', $scopes['prx450.parse']['module']);
        $this->assertGreaterThan(0, $scopes['prx450.parse']['rate_limit']);
    }
}
