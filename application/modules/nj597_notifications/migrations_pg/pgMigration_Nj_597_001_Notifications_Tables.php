<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-597/001: «Уведомления» (nj597_notifications).
 *
 * nj_597_notifications — события для пользователя (колокольчик + письмо);
 * nj_597_prefs — настройки доставки. DDL == Service_Nj597_Notify::ensure_tables().
 */
class pgMigration_Nj_597_001_Notifications_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_597_notifications" (
    "id"           BIGSERIAL PRIMARY KEY,
    "user_id"      BIGINT       NOT NULL,
    "kind"         VARCHAR(32)  NOT NULL DEFAULT 'system',
    "title"        VARCHAR(300) NOT NULL DEFAULT '',
    "body"         TEXT         NOT NULL DEFAULT '',
    "href"         VARCHAR(300) NOT NULL DEFAULT '',
    "email_to"     VARCHAR(200) NOT NULL DEFAULT '',
    "email_status" VARCHAR(16)  NOT NULL DEFAULT 'none',
    "email_error"  TEXT         NULL,
    "read_at"      TIMESTAMPTZ  NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_597_notifications_user_idx" ON "nj_597_notifications" ("user_id", "read_at", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_597_notifications_email_idx" ON "nj_597_notifications" ("email_status", "id" DESC);
COMMENT ON TABLE  "nj_597_notifications" IS 'NJ-597 уведомления: job_done|job_failed|system|test; email none|pending|sent|failed';

CREATE TABLE IF NOT EXISTS "nj_597_prefs" (
    "user_id"       BIGINT PRIMARY KEY,
    "email_enabled" BOOLEAN      NOT NULL DEFAULT TRUE,
    "on_done"       BOOLEAN      NOT NULL DEFAULT TRUE,
    "on_failed"     BOOLEAN      NOT NULL DEFAULT TRUE,
    "updated_at"    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_597_prefs" IS 'NJ-597 настройки уведомлений пользователя';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_597_notifications"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_597_prefs"')->execute();
    }
}
