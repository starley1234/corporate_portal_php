<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx399_docmerge — слияние договоров и допсоглашений (DocMergeAI).
 *
 * Схема намеренно «плоская, с JSONB-документами»: дерево итогового договора
 * (doc_tree) и карточка сделки (deal_card) хранятся цельными JSONB — их
 * читает человек и воркер, построчного SQL-доступа к узлам нет. Всё, что
 * нужно искать/считать, вынесено в отдельные колонки и таблицы.
 *
 * Статусы — VARCHAR, а не BOOLEAN: PDO/pgsql отдаёт BOOLEAN строкой 't'/'f'
 * и любое (int)-приведение читает их как 0 (урок prx597/prx102, см. AGENTS).
 */
class pgMigration_Prx_399_001_DocMerge extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_399_contracts (
    id BIGSERIAL PRIMARY KEY,
    title TEXT NOT NULL,
    -- balanced | customer | contractor: за кого даётся риск-аудит.
    focus VARCHAR(16) NOT NULL DEFAULT 'balanced',
    -- Risk Policy владельца (свободный текст стоп-факторов), JSONB ради
    -- будущих структурированных политик без ALTER TABLE.
    policy JSONB NOT NULL DEFAULT '{}'::jsonb,
    -- draft → parsing → auditing → audited; failed — любой сбой пайплайна.
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    error TEXT NULL,
    -- Contract Scratchpad: стороны, предмет, сумма, даты (200–300 токенов).
    deal_card JSONB NULL,
    -- Итоговая «плоская» редакция: [{path, title, text, status, source, history}].
    doc_tree JSONB NULL,
    -- Паспорт сделки (Executive Summary) — текст после риск-аудита.
    summary TEXT NULL,
    -- Недействительные ссылки, найденные программной валидацией сборки.
    broken_links JSONB NULL,
    audit_job_id BIGINT NULL,
    created_by BIGINT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_399_contracts_created
    ON prx_399_contracts (created_at DESC);
CREATE INDEX IF NOT EXISTS prx_399_contracts_owner
    ON prx_399_contracts (created_by);

CREATE TABLE IF NOT EXISTS prx_399_files (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL REFERENCES prx_399_contracts (id) ON DELETE CASCADE,
    -- base — базовый договор; ds — дополнительное соглашение.
    kind VARCHAR(8) NOT NULL,
    -- Порядок применения: base = 0, ДС = 1, 2, 3… (хронология цепочки).
    ordinal INTEGER NOT NULL DEFAULT 0,
    original_name TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    -- queued → working → parsed | failed (как у партии prx396).
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    job_id BIGINT NULL,
    pages INTEGER NULL,
    text_sha256 CHAR(64) NULL,
    -- AST базового договора (только kind = 'base').
    ast JSONB NULL,
    error TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_399_files_contract
    ON prx_399_files (contract_id, ordinal);
CREATE INDEX IF NOT EXISTS prx_399_files_job
    ON prx_399_files (job_id);

-- Дельты одного ДС: точечные инструкции «заменить/добавить/аннулировать».
CREATE TABLE IF NOT EXISTS prx_399_deltas (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL REFERENCES prx_399_contracts (id) ON DELETE CASCADE,
    file_id BIGINT NOT NULL REFERENCES prx_399_files (id) ON DELETE CASCADE,
    seq INTEGER NOT NULL,
    op VARCHAR(12) NOT NULL,
    anchor VARCHAR(64) NOT NULL,
    body TEXT NULL,
    note TEXT NULL,
    confidence REAL NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_399_deltas_contract
    ON prx_399_deltas (contract_id);
CREATE INDEX IF NOT EXISTS prx_399_deltas_file
    ON prx_399_deltas (file_id);

-- Матрица рисков: source = 'static' (программная валидация) | 'llm' (аудит).
CREATE TABLE IF NOT EXISTS prx_399_risks (
    id BIGSERIAL PRIMARY KEY,
    contract_id BIGINT NOT NULL REFERENCES prx_399_contracts (id) ON DELETE CASCADE,
    severity VARCHAR(8) NOT NULL,
    area VARCHAR(48) NULL,
    chunk_path VARCHAR(64) NULL,
    title TEXT NOT NULL,
    detail TEXT NULL,
    recommendation TEXT NULL,
    source VARCHAR(8) NOT NULL DEFAULT 'llm',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_399_risks_contract
    ON prx_399_risks (contract_id, severity);
SQL
        );
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_399_risks CASCADE");
        $this->run_query("DROP TABLE IF EXISTS prx_399_deltas CASCADE");
        $this->run_query("DROP TABLE IF EXISTS prx_399_files CASCADE");
        $this->run_query("DROP TABLE IF EXISTS prx_399_contracts CASCADE");
    }
}
