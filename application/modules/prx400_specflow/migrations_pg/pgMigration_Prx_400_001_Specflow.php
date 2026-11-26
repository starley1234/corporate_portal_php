<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: спецификации (пакеты), позиции, локальный справочник НСИ.
 *
 * Позиции хранят распознанные поля, флаги математики и результат матчинга
 * с НСИ (match JSONB: score, alternatives) — верификация оператора меняет
 * status/verified, не переписывая сырые поля (edits — отдельная история).
 */
class pgMigration_Prx_400_001_Specflow extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_specs (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NULL,
    owner_id BIGINT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_path TEXT NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued'
        CHECK (status IN ('queued','running','review','done','failed')),
    template_id VARCHAR(64) NULL,
    template_title VARCHAR(255) NULL,
    spec_number VARCHAR(64) NULL,
    department VARCHAR(255) NULL,
    pages INT NULL,
    text_sha256 CHAR(64) NULL,
    math JSONB NULL,
    summary TEXT NULL,
    error VARCHAR(500) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_400_specs_owner ON prx_400_specs (owner_id, id DESC);

CREATE TABLE IF NOT EXISTS prx_400_rows (
    id BIGSERIAL PRIMARY KEY,
    spec_id BIGINT NOT NULL REFERENCES prx_400_specs(id) ON DELETE CASCADE,
    seq INT NOT NULL,
    section VARCHAR(255) NULL,
    pos INT NULL,
    raw_name TEXT NOT NULL DEFAULT '',
    code_doc VARCHAR(255) NOT NULL DEFAULT '',
    qty NUMERIC(14,4) NULL,
    unit VARCHAR(16) NOT NULL DEFAULT '',
    unit_price NUMERIC(16,4) NULL,
    total_price NUMERIC(16,4) NULL,
    flags JSONB NOT NULL DEFAULT '[]'::jsonb,
    match JSONB NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new'
        CHECK (status IN ('ok','review','new','error')),
    verified BOOLEAN NOT NULL DEFAULT FALSE,
    verified_by BIGINT NULL,
    verified_at TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS prx_400_rows_spec ON prx_400_rows (spec_id, seq);

CREATE TABLE IF NOT EXISTS prx_400_catalog (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    name TEXT NOT NULL,
    unit VARCHAR(16) NOT NULL DEFAULT 'шт.',
    okei_code VARCHAR(8) NULL,
    keywords TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS prx_400_rows, prx_400_specs, prx_400_catalog;');
    }
}
