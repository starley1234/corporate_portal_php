<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Prx_398_001_DocRoutes extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_398_routes (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL UNIQUE,
    owner_id BIGINT NOT NULL,
    file_id BIGINT NOT NULL,
    request_key CHAR(64) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','running','done','review','failed')),
    doc_type VARCHAR(32) NULL,
    route VARCHAR(32) NULL,
    confidence NUMERIC(4,3) NULL,
    state JSONB NULL,
    error_code VARCHAR(64) NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ NOT NULL,
    UNIQUE (owner_id, request_key)
);
CREATE INDEX IF NOT EXISTS prx_398_routes_expiry ON prx_398_routes (expires_at, id);
CREATE INDEX IF NOT EXISTS prx_398_routes_file ON prx_398_routes (file_id);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_398_routes;');
    }
}
