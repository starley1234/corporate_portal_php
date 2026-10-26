<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj638_pdfplan 001: журнал генераций документов А4.
 *
 * nj_638_runs — история сборки (title, pages, columns, статус). Rate limit —
 * по этой же таблице (client_ip).
 */
class pgMigration_Nj_638_001_Runs extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_638_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "title"       VARCHAR(255) NOT NULL DEFAULT '',
    "pages"       INTEGER      NULL,
    "columns"     SMALLINT     NOT NULL DEFAULT 1,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_638_runs_ip_idx"  ON "nj_638_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_638_runs_uid_idx" ON "nj_638_runs" ("visitor_uid", "id" DESC);
COMMENT ON TABLE "nj_638_runs" IS 'NJ-638 история генераций документа А4 (1|2 колонки); result JSONB — сводка';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_638_runs"')->execute();
    }
}
