<?php

use PHPUnit\Framework\TestCase;

/**
 * Ключи и реестр scope внешнего API.
 *
 * Запуск: php tests/mini_runner.php Prx150Keys
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

    public function test_scope_registry_reads_manifest(): void
    {
        $scopes = Service_Prx150_Services::scopes();

        $this->assertArrayHasKey('prx450.parse', $scopes);
        $this->assertSame('prx450_document_ocr', $scopes['prx450.parse']['module']);
        $this->assertGreaterThan(0, $scopes['prx450.parse']['rate_limit']);
    }
}
