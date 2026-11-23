<?php
use PHPUnit\Framework\TestCase;

class DocumentExtractionContractTest extends TestCase
{
    public function test_scope_is_declared_for_the_api_hub(): void
    {
        $scopes = Service_Prx150_Services::scopes();

        $this->assertArrayHasKey('prx395.extract', $scopes);
        $this->assertSame('prx395_document_extract', $scopes['prx395.extract']['module']);
        $this->assertGreaterThan(0, $scopes['prx395.extract']['rate_limit']);
        $this->assertSame('prx395.extract', Service_Prx395_Extraction::SCOPE);
    }

    public function test_module_error_keeps_the_machine_error_contract(): void
    {
        $e = new Prx395_Error('idempotency_conflict', 409, ['key' => 'x']);

        $this->assertTrue($e instanceof Prx150_Error);
        $this->assertSame(['error' => ['code' => 'idempotency_conflict', 'details' => ['key' => 'x']]], $e->payload());
        $this->assertSame(409, $e->getCode());
    }

    public function test_only_allowlisted_metadata_and_error_codes_cross_api_boundary(): void
    {
        $result = Service_Prx395_Extraction::validate_result([
            'text' => 'Документ', 'provider' => 'remote', 'format' => 'markdown',
            'pages_total' => 3, 'pages_processed' => NULL, 'warnings' => [], 'upstream_raw' => 'not metadata',
        ]);
        $this->assertSame(['provider' => 'remote', 'format' => 'markdown',
            'pages_total' => 3, 'pages_processed' => NULL, 'warnings' => []], $result);
        $this->assertSame('invalid_pdf', Service_Prx395_Extraction::error_code('PermanentJobError: invalid_pdf'));
        $this->assertSame('ocr_timeout', Service_Prx395_Extraction::error_code('DocumentJobError: ocr_timeout'));
        $this->assertSame('job_failed', Service_Prx395_Extraction::error_code('PermanentJobError: invalid_pdf private-text'));
        $this->assertSame('job_unavailable', Service_Prx395_Extraction::error_code('', FALSE));
    }

    public function test_incomplete_page_count_is_rejected_on_callback(): void
    {
        try {
            Service_Prx395_Extraction::validate_result([
                'text' => 'Only first page', 'provider' => 'remote', 'format' => 'markdown',
                'pages_total' => 3, 'pages_processed' => 1,
            ]);
            $this->fail('Нельзя принять неполный результат.');
        } catch (Prx395_Error $e) {
            $this->assertSame('invalid_result', $e->getMessage());
        }
    }
}
