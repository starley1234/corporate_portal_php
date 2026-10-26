<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj598_dossier 001: таблица досье. Идентична ensure_tables()
 * в Service_Nj598_Dossier.
 */
class pgMigration_Nj_598_001_Dossier_Table extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_598_dossiers" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NULL,
    "visitor_id"  VARCHAR(64)  NOT NULL DEFAULT '',
    "query"       VARCHAR(500) NOT NULL DEFAULT '',
    "company"     VARCHAR(300) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "result"      JSONB        NULL,
    "job_id"      BIGINT       NULL,
    "model_used"  VARCHAR(120) NOT NULL DEFAULT '',
    "usage"       JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_598_dossiers_owner_idx" ON "nj_598_dossiers" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_598_dossiers_visitor_idx" ON "nj_598_dossiers" ("visitor_id", "id" DESC);
COMMENT ON TABLE  "nj_598_dossiers" IS 'NJ-598 AI-досье на работодателя: new|running|done|error, result JSONB';
COMMENT ON COLUMN "nj_598_dossiers"."visitor_id" IS 'Visitor::uid(): u:<id> или g:<ubid> — владелец артефакта';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_598_dossiers"')->execute();
    }
}
