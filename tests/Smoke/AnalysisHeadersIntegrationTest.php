<?php
use PHPUnit\Framework\TestCase;

class AnalysisHeadersIntegrationTest extends TestCase
{
    private $db;
    protected function setUp(): void { $this->db = Database::instance(); $this->db->begin(); }
    protected function tearDown(): void { $this->db->rollback(); }

    private function document(): int
    {
        return (int) DB::query(Database::SELECT,
            "INSERT INTO prx_391_documents (reg_no, subject, sensitivity) VALUES (:number, 'Тест заголовка', 'personal') RETURNING id")
            ->param(':number', 'headers-'.bin2hex(random_bytes(5)))->execute()->get('id');
    }
    private function create_run(int $document): int
    {
        return (int) DB::query(Database::SELECT,
            "INSERT INTO prx_392_runs (document_id, message_id, profile, model, prompt_version, input_hash, created_at) "
            ."VALUES (:document, 0, 'classify_extract_v1', 'local::test', '1', :hash, '2099-01-01') RETURNING id")
            ->parameters([':document' => $document, ':hash' => bin2hex(random_bytes(16))])->execute()->get('id');
    }
    private function proposal(int $run, int $document): int
    {
        return (int) DB::query(Database::SELECT,
            "INSERT INTO prx_392_suggestions (run_id, document_id, payload, created_at) VALUES (:run, :document, '{}'::jsonb, '2099-01-01') RETURNING id")
            ->parameters([':run' => $run, ':document' => $document])->execute()->get('id');
    }

    public function test_batch_headers_are_minimal_deduplicated_and_reflect_current_document(): void
    {
        $id = $this->document();
        $header = Service_Prx391_Documents::headers_for_processing([$id, (string) $id, -1, 'invalid']);
        $this->assertSame([$id], array_keys($header));
        $this->assertSame(['id', 'reg_no', 'subject'], array_keys($header[$id]));
        $this->assertSame('Тест заголовка', $header[$id]['subject']);
        DB::query(Database::UPDATE, 'UPDATE prx_391_documents SET subject = :subject WHERE id = :id')
            ->parameters([':subject' => 'Новое название', ':id' => $id])->execute();
        $this->assertSame('Новое название', Service_Prx391_Documents::headers_for_processing([$id])[$id]['subject']);
        $this->assertSame([], Service_Prx391_Documents::headers_for_processing([]));
        $this->assertSame([], Service_Prx391_Documents::headers_for_processing([-1, 0, '1 OR true']));
    }

    public function test_analysis_lists_keep_order_metadata_and_missing_document_semantics(): void
    {
        $id = $this->document();
        $first = $this->create_run($id);
        $second = $this->create_run($id);
        $orphan = $this->create_run(0);
        $proposal = $this->proposal($second, $id);
        $this->proposal($orphan, 0);
        $runs = Service_Prx392_Analysis::runs(3);
        $this->assertSame([$orphan, $second, $first], array_map('intval', array_column($runs, 'id')));
        $this->assertNull($runs[0]['subject']);
        $this->assertNull($runs[0]['reg_no']);
        $this->assertSame('Тест заголовка', $runs[1]['subject']);
        $this->assertSame($runs[1]['reg_no'], $runs[2]['reg_no']);
        $this->assertSame([$second], array_map('intval', array_column(Service_Prx392_Analysis::runs_for_document($id, 1), 'id')));
        $proposals = Service_Prx392_Analysis::proposals(2);
        $this->assertSame([$proposal], array_map('intval', array_column($proposals, 'id')));
        $this->assertSame('Тест заголовка', $proposals[0]['subject']);
        $this->assertSame('classify_extract_v1', $proposals[0]['profile']);
        $this->assertSame([], Service_Prx392_Analysis::runs(0));
        $this->assertSame([], Service_Prx392_Analysis::proposals(0));
        $this->assertSame([], Service_Prx392_Analysis::runs_for_document($id, 0));
    }
}
