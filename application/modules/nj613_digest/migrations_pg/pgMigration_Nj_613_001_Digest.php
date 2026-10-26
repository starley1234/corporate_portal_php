<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj613_digest 001: подписка на дайджест и сами дайджесты.
 */
class pgMigration_Nj_613_001_Digest extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_613_subs" (
    "user_id"    BIGINT PRIMARY KEY,
    "weekday"    SMALLINT NOT NULL DEFAULT 1,
    "enabled"    BOOLEAN NOT NULL DEFAULT TRUE,
    "last_sent"  TIMESTAMPTZ NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS "nj_613_digests" (
    "id"         BIGSERIAL PRIMARY KEY,
    "user_id"    BIGINT NOT NULL,
    "date_from"  TIMESTAMPTZ NOT NULL,
    "date_to"    TIMESTAMPTZ NOT NULL,
    "stats"      JSONB NULL,
    "summary"    TEXT NULL,
    "status"     VARCHAR(12) NOT NULL DEFAULT 'new',
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_613_digests_user_idx" ON "nj_613_digests" ("user_id", "id" DESC);

COMMENT ON TABLE "nj_613_subs" IS 'nj613_digest: подписка руководителя; weekday 0=вс..6=сб';
COMMENT ON TABLE "nj_613_digests" IS 'nj613_digest: недельные сводки; status new|ready|sent';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_613_digests"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_613_subs"')->execute();
    }
}
