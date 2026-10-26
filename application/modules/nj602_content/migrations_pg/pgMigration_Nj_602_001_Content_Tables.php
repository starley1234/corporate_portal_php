<?php defined('SYSPATH') or die('No direct script access.');

/** Схема контент-конвейера; штатно ставится deploy-миграцией. */
class pgMigration_Nj_602_001_Content_Tables extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_602_articles" (
    "id" BIGSERIAL PRIMARY KEY,
    "code" VARCHAR(12) NOT NULL UNIQUE,
    "slug" VARCHAR(140) NOT NULL UNIQUE,
    "topic" VARCHAR(300) NOT NULL DEFAULT '',
    "title" VARCHAR(300) NOT NULL DEFAULT '',
    "summary" VARCHAR(600) NOT NULL DEFAULT '',
    "body_md" TEXT NULL,
    "image_prompt" TEXT NULL,
    "image_file" VARCHAR(300) NOT NULL DEFAULT '',
    "mermaid" TEXT NULL,
    "graph" JSONB NULL,
    "tags" JSONB NULL,
    "sources" JSONB NULL,
    "status" VARCHAR(12) NOT NULL DEFAULT 'collecting',
    "error" TEXT NULL,
    "job_id" BIGINT NULL,
    "model_used" VARCHAR(120) NOT NULL DEFAULT '',
    "usage" JSONB NULL,
    "published_at" TIMESTAMPTZ NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_602_articles_status_idx" ON "nj_602_articles" ("status", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_602_articles_pub_idx" ON "nj_602_articles" ("published_at" DESC);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_602_articles" CASCADE;');
    }
}
