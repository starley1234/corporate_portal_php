<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj641_imagegen 001: таблица прогонов генерации изображений.
 *
 * nj_641_runs — очередь/журнал генераций: режим, параметры, результат
 * (картинка base64) и учёт для rate limit по client_ip.
 */
class pgMigration_Nj_641_001_Imagegen extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_641_runs" (
    "id"                BIGSERIAL    PRIMARY KEY,
    "visitor_uid"       VARCHAR(40)  NOT NULL DEFAULT '',
    "client_ip"         VARCHAR(64)  NOT NULL DEFAULT '',
    "mode"              VARCHAR(16)  NOT NULL DEFAULT 'generate',
    "prompt"            TEXT         NOT NULL DEFAULT '',
    "negative_prompt"   TEXT         NOT NULL DEFAULT '',
    "width"             INTEGER      NOT NULL DEFAULT 1024,
    "height"            INTEGER      NOT NULL DEFAULT 1024,
    "seed"              BIGINT       NOT NULL DEFAULT 0,
    "strength"          NUMERIC(4,3) NULL,
    "image_b64"         TEXT         NULL,
    "image_info"        JSONB        NULL,
    "status"            VARCHAR(12)  NOT NULL DEFAULT 'queued',
    "model"             VARCHAR(160) NULL,
    "duration_ms"       INTEGER      NOT NULL DEFAULT 0,
    "error"             TEXT         NULL,
    "job_id"            BIGINT       NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"       TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_641_runs_uid_idx" ON "nj_641_runs" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_641_runs_ip_idx"  ON "nj_641_runs" ("client_ip", "id" DESC);
COMMENT ON TABLE "nj_641_runs" IS 'NJ-641 прогоны генерации изображений; image_b64 — результат (PNG base64), rate limit по client_ip';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_641_runs"')->execute();
    }
}
