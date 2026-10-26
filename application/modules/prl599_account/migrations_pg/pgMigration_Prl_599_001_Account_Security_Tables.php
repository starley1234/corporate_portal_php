<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция prl599_account 001: таблицы 2FA/журнала входов/сессий/kick-меток.
 * Идентична ensure_tables() в Service_Prl599_Account.
 */
class pgMigration_Prl_599_001_Account_Security_Tables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "prl_599_totp" (
    "user_id"    BIGINT      PRIMARY KEY,
    "secret"     VARCHAR(64) NOT NULL,
    "enabled_at" TIMESTAMPTZ NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prl_599_totp" IS 'PRL-599 TOTP: secret base32; enabled_at NULL = настройка не завершена';

CREATE TABLE IF NOT EXISTS "prl_599_logins" (
    "id"         BIGSERIAL PRIMARY KEY,
    "user_id"    BIGINT       NOT NULL,
    "ip"         VARCHAR(64)  NOT NULL DEFAULT '',
    "ua"         VARCHAR(300) NOT NULL DEFAULT '',
    "session_id" VARCHAR(64)  NOT NULL DEFAULT '',
    "via"        VARCHAR(20)  NOT NULL DEFAULT 'password',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prl_599_logins_user_idx" ON "prl_599_logins" ("user_id", "id" DESC);
COMMENT ON TABLE "prl_599_logins" IS 'PRL-599 журнал входов (хвост 90 дней чистится автоматически)';

CREATE TABLE IF NOT EXISTS "prl_599_sessions" (
    "session_id" VARCHAR(64) PRIMARY KEY,
    "user_id"    BIGINT       NOT NULL,
    "ip"         VARCHAR(64)  NOT NULL DEFAULT '',
    "ua"         VARCHAR(300) NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_seen"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prl_599_sessions_user_idx" ON "prl_599_sessions" ("user_id");
COMMENT ON TABLE "prl_599_sessions" IS 'PRL-599 активные сессии устройств';

CREATE TABLE IF NOT EXISTS "prl_599_kicks" (
    "user_id"  BIGINT      PRIMARY KEY,
    "after_ts" TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prl_599_kicks" IS 'PRL-599 «выйти везде»: сессии созданные раньше after_ts недействительны';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "prl_599_totp"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "prl_599_logins"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "prl_599_sessions"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "prl_599_kicks"')->execute();
    }
}
