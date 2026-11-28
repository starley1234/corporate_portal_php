<?php
use PHPUnit\Framework\TestCase;

/**
 * prx115: уборка векторов удалённых записей (prx115:prune, расписание
 * semantic_prune). Удаляет только старые сироты коллекций из реестра sources.
 */
class SemanticPruneIntegrationTest extends TestCase
{
    public function test_prune_removes_old_orphans_and_keeps_fresh_or_foreign_vectors(): void
    {
        $db = Database::instance();
        $db->begin();

        try
        {
            $col = Prx_Db::one("SELECT id FROM prx_115_collections WHERE name = 'prx560_knowledge'")
                ?: Prx_Db::one("INSERT INTO prx_115_collections (name, model, dims, description)
                    VALUES ('prx560_knowledge', 'test', 1, 'test') RETURNING id");
            $other = Prx_Db::one("INSERT INTO prx_115_collections (name, model, dims, description)
                VALUES (:n, 'test', 1, 'test') RETURNING id", [':n' => 'test_prune_'.bin2hex(random_bytes(4))]);

            // Тип колонки зависит от окружения: vector(N) при pgvector (CI), real[] без него.
            $type = (string) Prx_Db::one("SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute
                WHERE attrelid = 'prx_115_vectors'::regclass AND attname = 'embedding'")['t'];
            $embedding = preg_match('/^vector\((\d+)\)/', $type, $m)
                ? '['.implode(',', array_fill(0, (int) $m[1], '0.1')).']'
                : (str_starts_with($type, 'vector') ? '[0.1]' : '{0.1}');

            $vec = function (int $collection, string $entity_id, int $age_days) use ($embedding) {
                return (int) Prx_Db::one("INSERT INTO prx_115_vectors
                        (collection_id, entity, entity_id, content, metadata, embedding, model, dims, content_hash, updated_at)
                    VALUES (:c, 'doc', :e, 'x', '{}'::jsonb, :emb, 'test', 1, 'h', now() - make_interval(days => :d))
                    RETURNING id", [':c' => $collection, ':e' => $entity_id, ':d' => $age_days, ':emb' => $embedding])['id'];
            };

            $old_orphan   = $vec((int) $col['id'], '-900001', 10);
            $fresh_orphan = $vec((int) $col['id'], '-900002', 1);
            $foreign      = $vec((int) $other['id'], '-900003', 30);

            $this->assertGreaterThanOrEqual(1, Service_Prx115_Semantic::prune('prx560_knowledge', 7));

            $left = array_map('intval', array_column(Prx_Db::select(
                'SELECT id FROM prx_115_vectors WHERE id IN ('.$old_orphan.', '.$fresh_orphan.', '.$foreign.')'), 'id'));
            sort($left);

            $this->assertNotContains($old_orphan, $left, 'старая сирота удалена');
            $this->assertContains($fresh_orphan, $left, 'свежий вектор мог опередить запись — не трогаем');
            $this->assertContains($foreign, $left, 'коллекция без источника — не наша забота');
        }
        finally
        {
            $db->rollback();
        }
    }
}
