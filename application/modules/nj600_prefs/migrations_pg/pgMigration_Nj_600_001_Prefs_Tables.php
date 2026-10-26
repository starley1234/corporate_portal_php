<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj600_prefs 001: nj_600_events (сырое, TTL) + nj_600_profile
 * (компактная суммаризация). Идентична ensure_tables() в Service_Nj600_Prefs.
 */
class pgMigration_Nj_600_001_Prefs_Tables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_600_events" (
    "id"           BIGSERIAL PRIMARY KEY,
    "user_id"      BIGINT       NULL,
    "visitor_uid"  VARCHAR(40)  NOT NULL DEFAULT '',
    "section"      VARCHAR(60)  NOT NULL DEFAULT '',
    "action"       VARCHAR(12)  NOT NULL DEFAULT 'page',
    "phrase"       VARCHAR(160) NOT NULL DEFAULT '',
    "ref_host"     VARCHAR(120) NOT NULL DEFAULT '',
    "utm_source"   VARCHAR(80)  NOT NULL DEFAULT '',
    "utm_medium"   VARCHAR(80)  NOT NULL DEFAULT '',
    "utm_campaign" VARCHAR(80)  NOT NULL DEFAULT '',
    "uri"          VARCHAR(200) NOT NULL DEFAULT '',
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_600_events_owner_idx" ON "nj_600_events" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_600_events_created_idx" ON "nj_600_events" ("created_at");
COMMENT ON TABLE "nj_600_events" IS 'NJ-600 сырое (TTL, чистится автоматически): страницы, поисковые фразы, referrer/utm';

CREATE TABLE IF NOT EXISTS "nj_600_profile" (
    "owner_key"      VARCHAR(40) PRIMARY KEY,
    "sections"       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    "phrases"        JSONB       NOT NULL DEFAULT '[]'::jsonb,
    "ref_hosts"      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    "utm_last"       JSONB       NOT NULL DEFAULT '{}'::jsonb,
    "pages_total"    BIGINT      NOT NULL DEFAULT 0,
    "searches_total" BIGINT      NOT NULL DEFAULT 0,
    "first_seen"     TIMESTAMPTZ NOT NULL DEFAULT now(),
    "last_seen"      TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_600_profile" IS 'NJ-600 компактная суммаризация предпочтений по владельцу (u:<id>/g:<ubid>)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_600_events"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_600_profile"')->execute();
    }
}
