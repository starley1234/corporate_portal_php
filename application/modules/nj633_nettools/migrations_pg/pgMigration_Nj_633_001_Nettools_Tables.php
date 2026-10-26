<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj633_nettools 001: таблицы сетевых инструментов.
 *
 *  * nj_633_checks — история сетевых проверок (DNS/WHOIS/TCP) с результатом;
 *    счётчик rate limit считается по этой же таблице (client_ip).
 *  * nj_633_notes  — LLM-объяснения результатов (опциональная фича):
 *    queued → done/error через очередь nj120 (канал llm.summarize).
 */
class pgMigration_Nj_633_001_Nettools_Tables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_633_checks" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "kind"        VARCHAR(16)  NOT NULL DEFAULT 'dns',
    "target"      VARCHAR(255) NOT NULL DEFAULT '',
    "port"        INTEGER      NULL,
    "result"      JSONB        NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'ok',
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_633_checks_ip_idx"   ON "nj_633_checks" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_633_checks_kind_idx" ON "nj_633_checks" ("kind", "id" DESC);
COMMENT ON TABLE "nj_633_checks" IS 'NJ-633 история сетевых проверок (dns|whois|tcp); result JSONB — результат, клиентский IP — для rate limit';
SQL
        )->execute();

        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_633_notes" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "check_id"    BIGINT       NULL,
    "job_id"      BIGINT       NULL,
    "client_ip"   VARCHAR(64)  NOT NULL DEFAULT '',
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "target"      VARCHAR(255) NOT NULL DEFAULT '',
    "prompt"      TEXT         NOT NULL DEFAULT '',
    "text"        TEXT         NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "model"       VARCHAR(120) NOT NULL DEFAULT '',
    "error"       TEXT         NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_633_notes_ip_idx"    ON "nj_633_notes" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_633_notes_check_idx" ON "nj_633_notes" ("check_id", "id" DESC);
COMMENT ON TABLE "nj_633_notes" IS 'NJ-633 LLM-объяснения результатов сетевых проверок: queued|done|error';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_633_notes"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_633_checks"')->execute();
    }
}
