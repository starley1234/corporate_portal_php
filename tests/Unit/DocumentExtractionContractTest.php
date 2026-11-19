<?php
use PHPUnit\Framework\TestCase;

class DocumentExtractionContractTest extends TestCase
{
    public function test_machine_token_binds_one_client_and_cannot_be_a_worker_token(): void
    {
        $key = 'PRX395_DOCUMENT_EXTRACT_CLIENTS';
        $before = getenv($key);
        $worker = getenv('WORKER_API_TOKEN');
        try {
            $a = str_repeat('a', 48);
            $b = str_repeat('b', 48);
            putenv('WORKER_API_TOKEN='.$b);
            putenv($key.'={"erp":"'.$a.'"}');
            $this->assertSame('erp', Service_Prx395_Clients::authenticate('Bearer '.$a));
            $this->assertNull(Service_Prx395_Clients::authenticate('Bearer '.$b));
            $this->assertNull(Service_Prx395_Clients::authenticate('Bearer wrong'));
            putenv($key.'={"erp":"'.$b.'"}');
            try {
                Service_Prx395_Clients::authenticate('Bearer '.$b);
                $this->fail('Токен воркера не может быть ключом интеграции.');
            } catch (Prx395_Error $e) {
                $this->assertSame('integration_not_configured', $e->getMessage());
            }
        } finally {
            $before === FALSE ? putenv($key) : putenv($key.'='.$before);
            $worker === FALSE ? putenv('WORKER_API_TOKEN') : putenv('WORKER_API_TOKEN='.$worker);
        }
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
