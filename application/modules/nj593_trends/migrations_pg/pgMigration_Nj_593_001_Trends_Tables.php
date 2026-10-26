<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-593/001: «Тренды GitHub» (nj593_trends).
 *
 * Обзоры (дата, фильтры, статус, вступление) → позиции (репозитории:
 * факты из GitHub API + тексты ИИ). Тот же DDL дублируется идемпотентно
 * в Service_Nj593_Trends::ensure_tables() (он РЕАЛЬНО исполняет запросы).
 */
class pgMigration_Nj_593_001_Trends_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_593_reviews" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "rdate"       DATE         NOT NULL DEFAULT CURRENT_DATE,
    "period_days" SMALLINT     NOT NULL DEFAULT 1,
    "min_stars"   INTEGER      NOT NULL DEFAULT 50,
    "language"    VARCHAR(40)  NOT NULL DEFAULT '',
    "item_count"  SMALLINT     NOT NULL DEFAULT 8,
    "focus"       VARCHAR(500) NULL,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "title"       VARCHAR(300) NOT NULL DEFAULT '',
    "summary"     TEXT         NULL,
    "model"       VARCHAR(120) NULL,
    "model_used"  VARCHAR(120) NULL,
    "job_id"      BIGINT       NULL,
    "usage"       JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_593_reviews_user_idx" ON "nj_593_reviews" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_593_reviews_status_idx" ON "nj_593_reviews" ("status", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_593_reviews_date_idx" ON "nj_593_reviews" ("rdate" DESC);
COMMENT ON TABLE  "nj_593_reviews" IS 'NJ-593 обзоры трендов GitHub: new|running|done|error; страница обзора публичная';
COMMENT ON COLUMN "nj_593_reviews"."rdate"       IS 'Дата обзора (охват: созданные за period_days до неё)';
COMMENT ON COLUMN "nj_593_reviews"."focus"       IS 'Пожелание пользователя: что считать интересным';
COMMENT ON COLUMN "nj_593_reviews"."title"       IS 'Заголовок обзора от ИИ (тема дня)';
COMMENT ON COLUMN "nj_593_reviews"."summary"     IS 'Вступление обзора от ИИ (2-3 предложения)';
COMMENT ON COLUMN "nj_593_reviews"."model_used"  IS 'Фактическая модель (сообщает воркер)';

CREATE TABLE IF NOT EXISTS "nj_593_items" (
    "id"            BIGSERIAL PRIMARY KEY,
    "review_id"     BIGINT       NOT NULL REFERENCES "nj_593_reviews"("id") ON DELETE CASCADE,
    "rank"          SMALLINT     NOT NULL,
    "full_name"     VARCHAR(200) NOT NULL,
    "url"           VARCHAR(300) NOT NULL DEFAULT '',
    "description"   TEXT         NULL,
    "language"      VARCHAR(40)  NOT NULL DEFAULT '',
    "stars"         INTEGER      NOT NULL DEFAULT 0,
    "forks"         INTEGER      NOT NULL DEFAULT 0,
    "gh_created_at" TIMESTAMPTZ  NULL,
    "topics"        VARCHAR(500) NOT NULL DEFAULT '',
    "title"         VARCHAR(300) NOT NULL DEFAULT '',
    "what"          TEXT         NULL,
    "why"           TEXT         NULL,
    "use_for"       TEXT         NULL,
    "score"         SMALLINT     NOT NULL DEFAULT 0,
    "created_at"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "nj_593_items_review_rank_key" UNIQUE ("review_id", "rank")
);
CREATE INDEX IF NOT EXISTS "nj_593_items_review_idx" ON "nj_593_items" ("review_id", "rank");
COMMENT ON TABLE "nj_593_items" IS 'NJ-593 позиции обзора: факты из GitHub API + тексты ИИ (what/why/use_for)';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_593_items", "nj_593_reviews" CASCADE')->execute();
    }
}
