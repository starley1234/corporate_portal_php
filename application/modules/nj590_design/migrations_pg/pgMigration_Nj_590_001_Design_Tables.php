<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-590/001: «Дизайн страницы» (nj590_design).
 *
 * Прогоны (URL + пожелания + N) → варианты дизайна (самодостаточные
 * HTML-файлы на диске сайта, путь в nj_590_variants.path).
 * Тот же DDL дублируется идемпотентно в Service_Nj590_Design::ensure_tables().
 */
class pgMigration_Nj_590_001_Design_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_590_runs" (
    "id"             BIGSERIAL PRIMARY KEY,
    "code"           VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"        BIGINT       NOT NULL,
    "url"            TEXT         NOT NULL,
    "host"           VARCHAR(200) NOT NULL DEFAULT '',
    "title"          VARCHAR(300) NOT NULL DEFAULT '',
    "wishes"         TEXT         NULL,
    "variants_count" SMALLINT     NOT NULL DEFAULT 3,
    "status"         VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"          TEXT         NULL,
    "progress"       VARCHAR(300) NOT NULL DEFAULT '',
    "summary"        TEXT         NULL,
    "chosen"         SMALLINT     NOT NULL DEFAULT 0,
    "model"          VARCHAR(120) NULL,
    "job_id"         BIGINT       NULL,
    "usage"          JSONB        NULL,
    "deleted_at"     TIMESTAMPTZ  NULL,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"    TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_590_runs_user_idx" ON "nj_590_runs" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_590_runs_status_idx" ON "nj_590_runs" ("status", "id" DESC);
ALTER TABLE "nj_590_runs" ADD COLUMN IF NOT EXISTS "model" VARCHAR(120) NULL;
COMMENT ON COLUMN "nj_590_runs"."model" IS 'Модель генерации вариантов (админский выбор формы или env воркера)';
COMMENT ON TABLE  "nj_590_runs" IS 'NJ-590 прогоны дизайна: new|running|done|error; chosen = выбранный вариант';
COMMENT ON COLUMN "nj_590_runs"."wishes" IS 'Пожелания к дизайну от владельца';
COMMENT ON COLUMN "nj_590_runs"."summary" IS 'Разбор страницы от ИИ: назначение, что улучшим';

CREATE TABLE IF NOT EXISTS "nj_590_variants" (
    "id"         BIGSERIAL PRIMARY KEY,
    "run_id"     BIGINT       NOT NULL REFERENCES "nj_590_runs"("id") ON DELETE CASCADE,
    "no"         SMALLINT     NOT NULL,
    "title"      VARCHAR(300) NOT NULL DEFAULT '',
    "concept"    TEXT         NULL,
    "path"       VARCHAR(120) NOT NULL DEFAULT '',
    "size"       BIGINT       NOT NULL DEFAULT 0,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_590_variants_run_no_key" UNIQUE ("run_id", "no")
);
CREATE INDEX IF NOT EXISTS "nj_590_variants_run_idx" ON "nj_590_variants" ("run_id", "no");
COMMENT ON TABLE "nj_590_variants" IS 'NJ-590 варианты дизайна: path = HTML-файл на диске сайта';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_590_variants", "nj_590_runs" CASCADE')->execute();
    }
}
