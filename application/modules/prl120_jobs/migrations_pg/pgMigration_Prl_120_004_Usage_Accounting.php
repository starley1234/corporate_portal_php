<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция prl120/004: prl_120_usage — учёт ресурсов задач (отчёты воркеров):
 * какие модели сколько токенов, веб-чтение/поиски, время, ошибки для анализа.
 * Агрегаты для админки /prl120/usage: потребление по юзерам/модулям/моделям.
 */
class pgMigration_Prl_120_004_Usage_Accounting extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "prl_120_usage" (
    "job_id"            BIGINT PRIMARY KEY REFERENCES "prl_120_jobs"("id") ON DELETE CASCADE,
    "channel"           VARCHAR(80)  NOT NULL DEFAULT '',
    "visitor_id"        VARCHAR(64)  NOT NULL DEFAULT '',
    "user_id"           BIGINT       NULL,
    "models"            JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "llm_calls"         INTEGER      NOT NULL DEFAULT 0,
    "prompt_tokens"     BIGINT       NOT NULL DEFAULT 0,
    "completion_tokens" BIGINT       NOT NULL DEFAULT 0,
    "web_fetches"       INTEGER      NOT NULL DEFAULT 0,
    "search_queries"    INTEGER      NOT NULL DEFAULT 0,
    "errors"            JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "had_error"         SMALLINT     NOT NULL DEFAULT 0,
    "elapsed_ms"        INTEGER      NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"        TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prl_120_usage_channel_idx" ON "prl_120_usage" ("channel", "created_at");
CREATE INDEX IF NOT EXISTS "prl_120_usage_visitor_idx" ON "prl_120_usage" ("visitor_id", "created_at");
COMMENT ON TABLE "prl_120_usage" IS 'PRL-120 учёт ресурсов задач: модели/токены/время/ошибки (отчёты воркеров, upsert по job_id)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "prl_120_usage"')->execute();
    }
}
