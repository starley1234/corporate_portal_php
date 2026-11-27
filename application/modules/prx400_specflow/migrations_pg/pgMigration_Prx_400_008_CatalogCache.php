<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: кеш ответов внешнего НСИ (provider=http).
 *
 * Ключ — sha256 от «вида запроса + адреса источника + текста»: повторные
 * позиции разных спецификаций (болты, гайки, резисторы) не ходят в MDM
 * заново, а при сбое источника разбор может опереться на недавний ответ
 * (stale-if-error) вместо catalog_unavailable. Чистит prx400:purge.
 */
class pgMigration_Prx_400_008_CatalogCache extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_catalog_cache (
    cache_key CHAR(64) PRIMARY KEY,
    payload JSONB NOT NULL,
    fetched_at TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL);
        $this->run_query('CREATE INDEX IF NOT EXISTS prx_400_catalog_cache_fetched_idx ON prx_400_catalog_cache (fetched_at)');
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_400_catalog_cache');
    }
}
