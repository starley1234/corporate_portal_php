<?php
use PHPUnit\Framework\TestCase;

/** Реальные отчёты/журнал prx115 без внешнего embedding-сервера. */
class SemanticDiagnosticsIntegrationTest extends TestCase
{
    private $db;
    private $config;
    private $old_sources;
    private $old_searches;
    private $old_url;
    protected function setUp(): void
    {
        $this->db = Database::instance();
        $this->db->begin();
        $this->config = Kohana::$config->load('prx115');
        $this->old_sources = $this->config->get('sources');
        $this->old_searches = $this->config->get('searches');
        $this->old_url = getenv('EMBEDDING_API_URL');
        $this->config->set('sources', []);
        $this->config->set('searches', ['keep' => 200]);
        $this->db->query(Database::DELETE, 'DELETE FROM prx_115_searches', FALSE);
    }
    protected function tearDown(): void
    {
        $this->db->rollback();
        $this->config->set('sources', $this->old_sources);
        $this->config->set('searches', $this->old_searches);
        $this->old_url === FALSE ? putenv('EMBEDDING_API_URL') : putenv('EMBEDDING_API_URL='.$this->old_url);
    }
    public function test_search_log_roundtrip_counts_windows_and_retention(): void
    {
        $facade = 'Service_Prx115_Semantic';
        $facade::log_search('report', "запрос'", [['match' => 'text'], ['match' => 'vector']], ['cached' => FALSE, 'ms' => 40]);
        $facade::log_search('report', 'кэш', [], ['cached' => TRUE, 'ms' => 0]);
        $facade::log_search('report', 'ошибка', [], ['error' => 'test', 'ms' => 20]);
        $stats = $facade::search_stats();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['empty']);
        $this->assertSame(1, $stats['errors']);
        $this->assertSame(1, $stats['cached']);
        $this->assertSame(30, $stats['avg_ms']);
        $rows = $facade::recent_searches(3);
        $this->assertSame('ошибка', $rows[0]['query']);
        $this->assertSame(1, $rows[2]['text_hits']);
        $this->assertSame(1, $rows[2]['vector_hits']);
        $this->assertCount(1, $facade::recent_searches(0));
        $this->db->query(Database::UPDATE, "UPDATE prx_115_searches SET created_at = now() - interval '48 hours' WHERE query = 'ошибка'", FALSE);
        $this->assertSame(2, $facade::search_stats(24)['total']);
        $this->config->set('searches', ['keep' => 2]);
        $facade::log_search('report', str_repeat('д', 600), []);
        $this->assertCount(2, $facade::recent_searches(10));
        $this->assertSame(500, mb_strlen($facade::recent_searches(1)[0]['query']));
    }
    public function test_sources_coverage_and_panel_use_owner_components_without_mutating_vectors(): void
    {
        $collection = 'diagnostics-'.bin2hex(random_bytes(5));
        $this->config->set('sources', [
            'fixture' => ['title' => 'Fixture', 'collection' => $collection, 'entity' => 'item',
                'sql' => "SELECT 'one'::text AS id, 'Текст'::text AS content"],
            'incomplete' => ['collection' => 'invalid'],
        ]);
        $this->assertCount(1, Service_Prx115_Semantic::sources());
        $coverage = Service_Prx115_Semantic::coverage($collection)[0];
        $this->assertSame(1, $coverage['total']);
        $this->assertSame(1, $coverage['missing']);
        $this->assertSame(0.0, $coverage['coverage']);
        $this->assertSame([], Service_Prx115_Semantic::coverage('absent'));
        $dims = (int) Kohana::$config->load('llm.embedding.dimensions');
        $vector = array_fill(0, $dims, 0.0); $vector[0] = 1.0;
        $this->assertSame(1, Service_Prx115_Semantic::store_vectors($collection, 'item', [
            ['id' => 'one', 'text' => 'Текст', 'vector' => $vector],
        ], 'test-model'));
        $coverage = Service_Prx115_Semantic::coverage($collection)[0];
        $this->assertSame(1, $coverage['fresh']);
        $this->assertSame(100.0, $coverage['coverage']);
        putenv('EMBEDDING_API_URL=https://not-contacted.example.test');
        $before = $this->db->query(Database::SELECT, 'SELECT count(*) AS n FROM prx_115_vectors', FALSE)->get('n');
        $panel = Service_Prx115_Semantic::panel();
        $this->assertTrue($panel['health']['ok']);
        $this->assertSame(100.0, $panel['health']['coverage_min']);
        $this->assertArrayHasKey('search_stats', $panel);
        $this->assertArrayHasKey('bridge', $panel['queue']);
        $this->assertArrayHasKey('pending', $panel['queue']);
        $this->assertSame((int) $before, $panel['totals']['vectors']);
        $this->assertSame($before, $this->db->query(Database::SELECT, 'SELECT count(*) AS n FROM prx_115_vectors', FALSE)->get('n'));
        $stats = Service_Prx115_Semantic::stats();
        $own = array_values(array_filter($stats, fn($row) => $row['name'] === $collection));
        $this->assertSame(1, $own[0]['vectors']);
    }
}
