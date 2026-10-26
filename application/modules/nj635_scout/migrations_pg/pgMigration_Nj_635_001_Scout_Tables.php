<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-635/001: «Разведка контрагента» — таблица маршрутов.
 * Тот же DDL дублируется идемпотентно в Service_Nj635_Scout::ensure_tables().
 */
class pgMigration_Nj_635_001_Scout_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_635_projects" (
    "id"           BIGSERIAL PRIMARY KEY,
    "code"         VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"      BIGINT       NOT NULL,
    "query"        VARCHAR(500) NOT NULL DEFAULT '',
    "company"      VARCHAR(300) NOT NULL DEFAULT '',
    "domain"       VARCHAR(200) NOT NULL DEFAULT '',
    "inn"          VARCHAR(20)  NOT NULL DEFAULT '',
    "ogrn_hint"    VARCHAR(20)  NOT NULL DEFAULT '',
    "org_id"       BIGINT       NULL,
    "profile_code" VARCHAR(12)  NULL,
    "subject_code" VARCHAR(12)  NULL,
    "letter_code"  VARCHAR(12)  NULL,
    "dossier_code" VARCHAR(12)  NULL,
    "status"       VARCHAR(12)  NOT NULL DEFAULT 'running',
    "step"         VARCHAR(30)  NOT NULL DEFAULT 'org',
    "error"        TEXT         NULL,
    "created_at"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"   TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_635_projects_user_idx" ON "nj_635_projects" ("user_id", "id" DESC);
COMMENT ON TABLE "nj_635_projects" IS 'nj635_scout: маршрут разведки контрагента; связи артефактов nj594/nj591/nj609/nj592/nj598';
COMMENT ON COLUMN "nj_635_projects"."status" IS 'running|done|error (окончательный статус маршрута)';
COMMENT ON COLUMN "nj_635_projects"."step" IS 'текущий шаг маршрута (ключ steps())';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_635_projects" CASCADE')->execute();
    }
}
