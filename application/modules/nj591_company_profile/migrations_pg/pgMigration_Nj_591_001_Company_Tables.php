<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-591/001: «Профиль компании» (nj591_company_profile).
 * Тот же DDL дублируется идемпотентно в Service_Nj591_Company::ensure_tables().
 */
class pgMigration_Nj_591_001_Company_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_591_profiles" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "query"       VARCHAR(300) NOT NULL DEFAULT '',
    "kind"        VARCHAR(10)  NOT NULL DEFAULT 'domain',
    "domain"      VARCHAR(200) NOT NULL DEFAULT '',
    "company"     VARCHAR(300) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "result"      JSONB        NULL,
    "sources"     JSONB        NULL,
    "job_id"      BIGINT       NULL,
    "usage"       JSONB        NULL,
    "deleted_at"  TIMESTAMPTZ  NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_591_profiles_user_idx" ON "nj_591_profiles" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_591_profiles_status_idx" ON "nj_591_profiles" ("status", "id" DESC);
COMMENT ON TABLE  "nj_591_profiles" IS 'NJ-591 профили компаний: new|running|done|error; result = строгий JSON воркера';
COMMENT ON COLUMN "nj_591_profiles"."kind" IS 'domain | name (название — сайт ищем поиском)';
COMMENT ON COLUMN "nj_591_profiles"."result" IS '{sphere, size_estimate, contacts{emails,phones,address}, tech_stack[], decision_makers[{name,role,source}], summary, confidence}';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_591_profiles" CASCADE')->execute();
    }
}
