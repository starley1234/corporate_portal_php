<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: многофайловые спецификации, иерархический BOM, память
 * сопоставлений, подписки API (webhook) и действующие лица со знаком.
 *
 *  - prx_400_spec_files — PDF спецификации по порядку (file_no 1..N); файл №1
 *    дублирует prx_400_specs.file_id (совместимость отчётов и старого кода);
 *  - prx_400_rows: дерево (parent_id/level/pos_path «1.2.3»), источник
 *    (file_no/page) и происхождение строки (parsed|manual|split);
 *  - prx_400_aliases — решения оператора «наименование+обозначение → код НСИ»:
 *    следующие разборы применяют их раньше fuzzy-матчинга; отзываемы;
 *  - prx_400_hooks / prx_400_deliveries — подписка API-ключа и очередь
 *    доставки событий (outbox, повтор с отсрочкой);
 *  - actor_id/verified_by — подписанный ID, как owner_id (UI < 0, ключ > 0);
 *    правки удалённых строк остаются в журнале (row_id → NULL).
 */
class pgMigration_Prx_400_007_Structure extends pgMigration
{
    public function up()
    {
        // По одному оператору на вызов: DO-блоки с $-кавычками run_script не режет.
        foreach ($this->statements_up() as $sql)
        {
            $this->run_query($sql);
        }
    }

    public function down()
    {
        foreach ([
            'DROP TABLE IF EXISTS prx_400_deliveries',
            'DROP TABLE IF EXISTS prx_400_hooks',
            'DROP TABLE IF EXISTS prx_400_aliases',
            'DROP TABLE IF EXISTS prx_400_spec_files',
            'ALTER TABLE prx_400_catalog DROP COLUMN IF EXISTS updated_at',
            <<<'SQL'
DO $prx400$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'prx_400_rows' AND column_name = 'pos_path'
    ) THEN
        UPDATE prx_400_edits SET actor_id = -actor_id WHERE actor_id < 0;
        UPDATE prx_400_rows SET verified_by = -verified_by WHERE verified_by < 0;
        DELETE FROM prx_400_edits WHERE row_id IS NULL;
        ALTER TABLE prx_400_edits DROP CONSTRAINT IF EXISTS prx_400_edits_row_fk;
        ALTER TABLE prx_400_edits ALTER COLUMN row_id SET NOT NULL;
        ALTER TABLE prx_400_edits ADD CONSTRAINT prx_400_edits_row_id_fkey
            FOREIGN KEY (row_id) REFERENCES prx_400_rows(id) ON DELETE CASCADE;
        ALTER TABLE prx_400_rows DROP COLUMN parent_id, DROP COLUMN level, DROP COLUMN pos_path,
            DROP COLUMN file_no, DROP COLUMN page, DROP COLUMN origin;
    END IF;
END $prx400$
SQL,
        ] as $sql)
        {
            $this->run_query($sql);
        }
    }

    /** @return string[] */
    private function statements_up(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_spec_files (
    id BIGSERIAL PRIMARY KEY,
    spec_id BIGINT NOT NULL REFERENCES prx_400_specs(id) ON DELETE CASCADE,
    file_no SMALLINT NOT NULL CHECK (file_no BETWEEN 1 AND 99),
    file_id BIGINT NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (spec_id, file_no)
)
SQL,
            'CREATE INDEX IF NOT EXISTS prx_400_spec_files_file ON prx_400_spec_files (file_id)',
            'CREATE INDEX IF NOT EXISTS prx_400_spec_files_sha ON prx_400_spec_files (file_sha256)',
            <<<'SQL'
INSERT INTO prx_400_spec_files (spec_id, file_no, file_id, file_sha256, original_name)
SELECT s.id, 1, s.file_id, s.file_sha256, s.original_name
  FROM prx_400_specs s
 WHERE s.file_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM prx_400_spec_files f WHERE f.spec_id = s.id)
SQL,
            // Строки: дерево и источник. Маркер «уже накатано» — колонка pos_path:
            // смена знака actor_id/verified_by выполняется ровно один раз.
            <<<'SQL'
DO $prx400$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'prx_400_rows' AND column_name = 'pos_path'
    ) THEN
        ALTER TABLE prx_400_rows
            ADD COLUMN parent_id BIGINT NULL REFERENCES prx_400_rows(id) ON DELETE SET NULL,
            ADD COLUMN level SMALLINT NOT NULL DEFAULT 0,
            ADD COLUMN pos_path VARCHAR(64) NOT NULL DEFAULT '',
            ADD COLUMN file_no SMALLINT NOT NULL DEFAULT 1,
            ADD COLUMN page INT NULL,
            ADD COLUMN origin VARCHAR(8) NOT NULL DEFAULT 'parsed'
                CHECK (origin IN ('parsed', 'manual', 'split'));
        UPDATE prx_400_rows SET verified_by = -verified_by WHERE verified_by > 0;
        UPDATE prx_400_edits SET actor_id = -actor_id WHERE actor_id > 0;
        ALTER TABLE prx_400_edits DROP CONSTRAINT IF EXISTS prx_400_edits_row_id_fkey;
        ALTER TABLE prx_400_edits ALTER COLUMN row_id DROP NOT NULL;
        ALTER TABLE prx_400_edits ADD CONSTRAINT prx_400_edits_row_fk
            FOREIGN KEY (row_id) REFERENCES prx_400_rows(id) ON DELETE SET NULL;
    END IF;
END $prx400$
SQL,
            'CREATE INDEX IF NOT EXISTS prx_400_rows_parent ON prx_400_rows (parent_id)',
            "ALTER TABLE prx_400_edits ADD COLUMN IF NOT EXISTS row_label VARCHAR(80) NOT NULL DEFAULT ''",
            'ALTER TABLE prx_400_catalog ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now()',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_aliases (
    id BIGSERIAL PRIMARY KEY,
    name_norm TEXT NOT NULL,
    code_doc_norm VARCHAR(255) NOT NULL DEFAULT '',
    catalog_code VARCHAR(64) NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'operator' CHECK (source IN ('operator', 'api', 'import')),
    actor_id BIGINT NULL,
    hits INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at TIMESTAMPTZ NULL
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS prx_400_aliases_key ON prx_400_aliases (name_norm, code_doc_norm) WHERE revoked_at IS NULL',
            'CREATE INDEX IF NOT EXISTS prx_400_aliases_code ON prx_400_aliases (catalog_code)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_hooks (
    owner_id BIGINT PRIMARY KEY CHECK (owner_id > 0),
    url VARCHAR(500) NOT NULL,
    secret VARCHAR(64) NOT NULL,
    events VARCHAR(200) NOT NULL DEFAULT 'spec.review,spec.done,spec.failed',
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_deliveries (
    id BIGSERIAL PRIMARY KEY,
    owner_id BIGINT NOT NULL,
    spec_id BIGINT NOT NULL,
    event VARCHAR(32) NOT NULL,
    payload JSONB NOT NULL,
    status VARCHAR(8) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'dead')),
    attempts SMALLINT NOT NULL DEFAULT 0,
    next_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_error VARCHAR(64) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    sent_at TIMESTAMPTZ NULL
)
SQL,
            'CREATE INDEX IF NOT EXISTS prx_400_deliveries_due ON prx_400_deliveries (status, next_at)',
            'CREATE INDEX IF NOT EXISTS prx_400_deliveries_owner ON prx_400_deliveries (owner_id, id DESC)',
        ];
    }
}
