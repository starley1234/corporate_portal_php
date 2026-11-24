<?php defined('SYSPATH') or die('No direct script access.');

class pgMigration_Prx_396_001_PdfChunks extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_396_batches (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL,
    owner_id BIGINT NOT NULL,
    request_key CHAR(64) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    file_id BIGINT NOT NULL,
    pages_total INTEGER NULL,
    chunk_pages INTEGER NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    text_bytes BIGINT NOT NULL DEFAULT 0,
    error_code VARCHAR(64) NULL,
    config_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS prx_396_batches_owner_key
    ON prx_396_batches (owner_id, request_key);
CREATE INDEX IF NOT EXISTS prx_396_batches_expiry
    ON prx_396_batches (expires_at, id);
CREATE INDEX IF NOT EXISTS prx_396_batches_file
    ON prx_396_batches (file_id);
CREATE INDEX IF NOT EXISTS prx_396_batches_job
    ON prx_396_batches (job_id);

CREATE TABLE IF NOT EXISTS prx_396_parts (
    batch_id BIGINT NOT NULL REFERENCES prx_396_batches (id) ON DELETE CASCADE,
    part_no INTEGER NOT NULL,
    page_from INTEGER NOT NULL,
    page_to INTEGER NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    text_file_id BIGINT NULL,
    text_bytes BIGINT NOT NULL DEFAULT 0,
    error_code VARCHAR(64) NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (batch_id, part_no)
);
CREATE INDEX IF NOT EXISTS prx_396_parts_text_file
    ON prx_396_parts (text_file_id) WHERE text_file_id IS NOT NULL;
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_396_parts; DROP TABLE IF EXISTS prx_396_batches;');
    }
}
