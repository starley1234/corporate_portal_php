<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-570/001: 3D-конструктор («Sketch 2 Solid»).
 *
 * Модели → ревизии (код OpenSCAD + промпт + изображение + сведения о моделях
 * LLM — датасет для дообучения) → файлы (STL/PNG) → оценки → журнал ресурсов.
 */
class pgMigration_Nj_570_001_Models_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_570_models" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "title"       VARCHAR(200) NOT NULL DEFAULT '',
    "description" TEXT         NOT NULL DEFAULT '',
    "status"      VARCHAR(16)  NOT NULL DEFAULT 'private',
    "clarify"     JSONB        NULL,
    "iterations"  SMALLINT     NOT NULL DEFAULT 3,
    "use_search"  BOOLEAN      NOT NULL DEFAULT FALSE,
    "current_rev" INT          NOT NULL DEFAULT 0,
    "views"       INT          NOT NULL DEFAULT 0,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_570_models_user_idx"   ON "nj_570_models" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_570_models_status_idx" ON "nj_570_models" ("status", "id" DESC);
COMMENT ON TABLE  "nj_570_models" IS 'NJ-570 3D-модели: private|pending|published|rejected';
COMMENT ON COLUMN "nj_570_models"."clarify" IS 'Вопросы LLM и ответы пользователя: {questions:[], answers:{}}';

CREATE TABLE IF NOT EXISTS "nj_570_revisions" (
    "id"         BIGSERIAL PRIMARY KEY,
    "model_id"   BIGINT      NOT NULL REFERENCES "nj_570_models"("id") ON DELETE CASCADE,
    "rev_no"     INT         NOT NULL,
    "source"     VARCHAR(16) NOT NULL DEFAULT 'create',
    "prompt"     TEXT        NOT NULL DEFAULT '',
    "image_b64"  TEXT        NULL,
    "code"       TEXT        NULL,
    "code_model" VARCHAR(120) NOT NULL DEFAULT '',
    "desc_model" VARCHAR(120) NOT NULL DEFAULT '',
    "summary"    TEXT        NOT NULL DEFAULT '',
    "params"     JSONB       NULL,
    "status"     VARCHAR(12) NOT NULL DEFAULT 'new',
    "error"      TEXT        NULL,
    "job_id"     BIGINT      NULL,
    "usage"      JSONB       NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ NULL,
    CONSTRAINT "nj_570_revisions_uniq" UNIQUE ("model_id", "rev_no")
);
CREATE INDEX IF NOT EXISTS "nj_570_revisions_model_idx" ON "nj_570_revisions" ("model_id", "rev_no" DESC);
COMMENT ON TABLE  "nj_570_revisions" IS 'NJ-570 ревизии: промпт+изображение(512px)+код+модели LLM — датасет для дообучения';
COMMENT ON COLUMN "nj_570_revisions"."status" IS 'new|coding|rendering|clarify|done|error';

CREATE TABLE IF NOT EXISTS "nj_570_files" (
    "id"          BIGSERIAL PRIMARY KEY,
    "revision_id" BIGINT      NOT NULL REFERENCES "nj_570_revisions"("id") ON DELETE CASCADE,
    "kind"        VARCHAR(8)  NOT NULL,
    "path"        VARCHAR(255) NOT NULL,
    "size"        BIGINT      NOT NULL DEFAULT 0,
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_570_files_rev_idx" ON "nj_570_files" ("revision_id", "kind");
COMMENT ON COLUMN "nj_570_files"."kind" IS 'stl|png';

CREATE TABLE IF NOT EXISTS "nj_570_ratings" (
    "id"         BIGSERIAL PRIMARY KEY,
    "model_id"   BIGINT NOT NULL REFERENCES "nj_570_models"("id") ON DELETE CASCADE,
    "user_id"    BIGINT NOT NULL,
    "score"      SMALLINT NOT NULL CHECK ("score" BETWEEN 1 AND 5),
    "comment"    VARCHAR(500) NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT "nj_570_ratings_uniq" UNIQUE ("model_id", "user_id")
);

CREATE TABLE IF NOT EXISTS "nj_570_usage" (
    "id"                BIGSERIAL PRIMARY KEY,
    "model_id"          BIGINT NULL,
    "revision_id"       BIGINT NULL,
    "job_id"            BIGINT NULL,
    "phase"             VARCHAR(16) NOT NULL,
    "model"             VARCHAR(120) NOT NULL DEFAULT '',
    "prompt_tokens"     INT NOT NULL DEFAULT 0,
    "completion_tokens" INT NOT NULL DEFAULT 0,
    "duration_ms"       INT NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_570_usage_model_idx" ON "nj_570_usage" ("model_id", "created_at" DESC);
COMMENT ON TABLE "nj_570_usage" IS 'NJ-570 затраченные ресурсы воркеров (токены, время) — учёт затрат';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_570_usage", "nj_570_ratings", "nj_570_files", "nj_570_revisions", "nj_570_models" CASCADE;');
    }
}
