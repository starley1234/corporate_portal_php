<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx400_specflow: журнал правок строк оператором (HITL-коррекции).
 *
 * Каждая правка поля строки — отдельная строка (старое/новое значение):
 * журнал не переписывается, полная история для аудита и future active
 * learning (обучение промптов по правкам операторов).
 */
class pgMigration_Prx_400_004_Edits extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<'SQL'
CREATE TABLE IF NOT EXISTS prx_400_edits (
    id BIGSERIAL PRIMARY KEY,
    row_id BIGINT NOT NULL REFERENCES prx_400_rows(id) ON DELETE CASCADE,
    spec_id BIGINT NOT NULL,
    field VARCHAR(32) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    actor_id BIGINT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS prx_400_edits_row_idx ON prx_400_edits (row_id);
CREATE INDEX IF NOT EXISTS prx_400_edits_spec_idx ON prx_400_edits (spec_id);
SQL
        );
    }

    public function down()
    {
        $this->run_query("DROP TABLE IF EXISTS prx_400_edits CASCADE;");
    }
}
