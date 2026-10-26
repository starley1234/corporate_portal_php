<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj636_pdfsign 001: журнал операций с подписями PDF.
 *
 * nj_636_runs — история (stamp|count|strip): имя файла, вид, страницы, статус,
 * сводка (JSONB). Rate limit — по этой же таблице (client_ip).
 */
class pgMigration_Nj_636_001_Runs extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_636_runs" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(255) NOT NULL DEFAULT '',
    "kind"        VARCHAR(16)  NOT NULL DEFAULT 'stamp',
    "pages"       INTEGER      NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "result"      JSONB        NULL,
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_636_runs_ip_idx"  ON "nj_636_runs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_636_runs_uid_idx" ON "nj_636_runs" ("visitor_uid", "id" DESC);
COMMENT ON TABLE "nj_636_runs" IS 'NJ-636 история операций подписей/реквизитов PDF (stamp|count|strip); result JSONB — сводка';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_636_runs"')->execute();
    }
}
