<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj639_qc 001: таблицы сервиса контроля качества.
 *
 * nj_639_rules   — правила поиска брака (текст, варианты вердикта, JSON-схема);
 * nj_639_refs    — эталонные изображения правила (файлы erp212);
 * nj_639_runs    — журнал прогонов (verdict JSONB, токены, статус; rate limit
 *                  по client_ip);
 * nj_639_sources — источники кадров авторежима (камера/FTP/очередь/вебхук).
 */
class pgMigration_Nj_639_001_QC extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_639_rules" (
    "id"          BIGSERIAL    PRIMARY KEY,
    "visitor_uid" VARCHAR(40)  NOT NULL DEFAULT '',
    "name"        VARCHAR(160) NOT NULL DEFAULT '',
    "rule_text"   TEXT         NOT NULL DEFAULT '',
    "variants"    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "schema"      JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_639_rules" IS 'NJ-639 правила поиска брака: текст, варианты вердикта, выходная JSON-схема';

CREATE TABLE IF NOT EXISTS "nj_639_refs" (
    "id"       BIGSERIAL PRIMARY KEY,
    "rule_id"  BIGINT    NOT NULL DEFAULT 0,
    "file_id"  BIGINT    NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "nj_639_refs_rule_idx" ON "nj_639_refs" ("rule_id");
COMMENT ON TABLE "nj_639_refs" IS 'NJ-639 эталонные изображения правила (file_id -> erp212)';

CREATE TABLE IF NOT EXISTS "nj_639_runs" (
    "id"                BIGSERIAL   PRIMARY KEY,
    "visitor_uid"       VARCHAR(40) NOT NULL DEFAULT '',
    "client_ip"         VARCHAR(64) NOT NULL DEFAULT '',
    "source_id"         BIGINT      NULL,
    "mode"              VARCHAR(12) NOT NULL DEFAULT 'manual',
    "trigger"           VARCHAR(12) NOT NULL DEFAULT 'manual',
    "status"            VARCHAR(12) NOT NULL DEFAULT 'queued',
    "verdict"           JSONB       NULL,
    "schema_ok"         BOOLEAN     NULL,
    "model"             VARCHAR(160) NULL,
    "prompt_tokens"     INTEGER     NOT NULL DEFAULT 0,
    "completion_tokens" INTEGER     NOT NULL DEFAULT 0,
    "duration_ms"       INTEGER     NOT NULL DEFAULT 0,
    "error"             TEXT        NULL,
    "job_id"            BIGINT      NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_639_runs_uid_idx" ON "nj_639_runs" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_639_runs_ip_idx"  ON "nj_639_runs" ("client_ip", "id" DESC);
COMMENT ON TABLE "nj_639_runs" IS 'NJ-639 журнал прогонов контроля качества; verdict JSONB — ответ VLM по схеме';

CREATE TABLE IF NOT EXISTS "nj_639_sources" (
    "id"           BIGSERIAL    PRIMARY KEY,
    "visitor_uid"  VARCHAR(40)  NOT NULL DEFAULT '',
    "code"         VARCHAR(40)  NOT NULL DEFAULT '',
    "name"         VARCHAR(140) NOT NULL DEFAULT '',
    "kind"         VARCHAR(16)  NOT NULL DEFAULT 'snapshot',
    "config"       JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "rule_id"      BIGINT       NOT NULL DEFAULT 0,
    "trigger"      VARCHAR(12)  NOT NULL DEFAULT 'timer',
    "interval_sec" INTEGER      NOT NULL DEFAULT 60,
    "enabled"      BOOLEAN      NOT NULL DEFAULT TRUE,
    "last_run_at"  TIMESTAMPTZ  NULL,
    "next_run_at"  TIMESTAMPTZ  NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_639_sources_uid_idx" ON "nj_639_sources" ("visitor_uid", "id" DESC);
COMMENT ON TABLE "nj_639_sources" IS 'NJ-639 источники кадров: IP-камера (snapshot/rtsp/onvif), FTP, HTTP-объект, папка, очередь';

SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_639_sources"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_639_runs"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_639_refs"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_639_rules"')->execute();
    }
}
