<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj637_watermark 001: журнал наложений водяных знаков.
 *
 * nj_637_runs — история операций (diagonal|stamp): имя файла, режим, страницы,
 * статус, сводка (JSONB). Rate limit считается по этой же таблице (client_ip).
 */
class pgMigration_Nj_637_001_Runs extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_637_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(255) NOT NULL DEFAULT '',
    "mode"        VARCHAR(16)  NOT NULL DEFAULT 'diagonal',
    "pages"       INTEGER      NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_637_runs_ip_idx"  ON "nj_637_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_637_runs_uid_idx" ON "nj_637_runs" ("visitor_uid", "id" DESC);
COMMENT ON TABLE "nj_637_runs" IS 'NJ-637 история наложений водяных знаков (diagonal|stamp); result JSONB — сводка операции';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_637_runs"')->execute();
    }
}
