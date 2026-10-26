<?php defined('SYSPATH') or die('No direct script access.');

/** Базовая схема новостей сайта. */
class pgMigration_Nj_604_001_News_Tables extends pgMigration
{
    public function up()
    {
        $this->run_query(<<<SQL
CREATE TABLE IF NOT EXISTS "nj_604_news" (
    "id" BIGSERIAL PRIMARY KEY,
    "kind" VARCHAR(12) NOT NULL DEFAULT 'manual',
    "title" VARCHAR(300) NOT NULL,
    "body_md" TEXT NOT NULL DEFAULT '',
    "href" VARCHAR(300) NOT NULL DEFAULT '',
    "tag" VARCHAR(40) NOT NULL DEFAULT '',
    "commits" JSONB NULL,
    "published" BOOLEAN NOT NULL DEFAULT TRUE,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    "published_at" TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_604_news_pub_idx" ON "nj_604_news" ("published", "id" DESC);

CREATE TABLE IF NOT EXISTS "nj_604_state" (
    "key" VARCHAR(40) PRIMARY KEY,
    "value" TEXT NOT NULL DEFAULT ''
);
SQL);
    }

    public function down()
    {
        $this->run_query('DROP TABLE IF EXISTS "nj_604_news" CASCADE;');
        $this->run_query('DROP TABLE IF EXISTS "nj_604_state" CASCADE;');
    }
}
