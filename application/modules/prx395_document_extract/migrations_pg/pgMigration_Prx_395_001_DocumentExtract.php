<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Prx_395_001_DocumentExtract extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_395_extractions (
    job_id BIGINT PRIMARY KEY,
    client_id VARCHAR(64) NOT NULL,
    request_key CHAR(64) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    file_id BIGINT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL DEFAULT now() + interval '24 hours',
    UNIQUE (client_id, request_key)
);
CREATE INDEX IF NOT EXISTS prx_395_extractions_expiry ON prx_395_extractions (expires_at, job_id);
CREATE INDEX IF NOT EXISTS prx_395_extractions_file ON prx_395_extractions (file_id);
CREATE TABLE IF NOT EXISTS prx_395_results (
    job_id BIGINT NOT NULL REFERENCES prx_395_extractions (job_id) ON DELETE CASCADE,
    attempt INTEGER NOT NULL CHECK (attempt > 0),
    receipt CHAR(32) NOT NULL UNIQUE,
    file_id BIGINT NOT NULL,
    metadata JSONB NOT NULL,
    PRIMARY KEY (job_id, attempt)
);
CREATE INDEX IF NOT EXISTS prx_395_results_file ON prx_395_results (file_id);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_395_results; DROP TABLE IF EXISTS prx_395_extractions;');
    }
}
