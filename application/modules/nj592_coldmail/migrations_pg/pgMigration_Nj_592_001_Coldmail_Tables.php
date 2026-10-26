<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-592/001: «Холодное письмо» (nj592_coldmail).
 * Тот же DDL дублируется идемпотентно в Service_Nj592_Coldmail::ensure_tables().
 */
class pgMigration_Nj_592_001_Coldmail_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_592_letters" (
    "id"             BIGSERIAL PRIMARY KEY,
    "code"           VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"        BIGINT       NOT NULL,
    "company_query"  VARCHAR(300) NOT NULL DEFAULT '',
    "profile_code"   VARCHAR(12)  NULL,
    "domain"         VARCHAR(200) NOT NULL DEFAULT '',
    "product"        TEXT         NULL,
    "audience"       VARCHAR(300) NOT NULL DEFAULT '',
    "tone"           VARCHAR(30)  NOT NULL DEFAULT '',
    "variants_count" SMALLINT     NOT NULL DEFAULT 3,
    "status"         VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"          TEXT         NULL,
    "progress"       VARCHAR(300) NOT NULL DEFAULT '',
    "result"         JSONB        NULL,
    "sources"        JSONB        NULL,
    "job_id"         BIGINT       NULL,
    "usage"          JSONB        NULL,
    "deleted_at"     TIMESTAMPTZ  NULL,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at"    TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_592_letters_user_idx" ON "nj_592_letters" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_592_letters_status_idx" ON "nj_592_letters" ("status", "id" DESC);
COMMENT ON TABLE  "nj_592_letters" IS 'NJ-592 холодные письма: new|running|done|error; result = {variants:[{subject,paragraph,why}]}';
COMMENT ON COLUMN "nj_592_letters"."profile_code" IS 'Код профиля nj_591_profiles.code — источник фактов о компании';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_592_letters" CASCADE')->execute();
    }
}
