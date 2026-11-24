<?php

use PHPUnit\Framework\TestCase;

/**
 * Настройки OCR-шлюза: контракт и белый список overrides.
 *
 * Запуск: php tests/Support/mini_runner.php Prx450Ocr
 */
class Prx450OcrTest extends TestCase
{
    public function test_config_has_upstream_and_overrides(): void
    {
        $config = Kohana::$config->load('prx450_document_ocr')->as_array();

        $this->assertArrayHasKey('base_url', $config['upstream']);
        $this->assertSame('/parse', $config['upstream']['parse_path']);
        $this->assertSame('auto', $config['defaults']['engine']);
        $this->assertContains('engine', $config['client_overrides']);
        $this->assertContains('output_format', $config['client_overrides']);
        $this->assertContains('llm_fix', $config['client_overrides']);
    }

    public function test_scope_is_declared_for_api_hub(): void
    {
        $scopes = Service_Prx150_Services::scopes();

        $this->assertArrayHasKey('prx450.parse', $scopes);
        $this->assertSame('prx450_document_ocr', $scopes['prx450.parse']['module']);
    }
}
