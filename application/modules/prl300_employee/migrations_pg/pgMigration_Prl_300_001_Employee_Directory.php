<?php defined('SYSPATH') or die('No direct script access.');

/**
 * PRL-300/001: структура холдинга, сотрудники и интеграционные связи.
 *
 * Auth не расширяется кадровыми полями: пользователь сопоставляется с
 * сотрудником отдельной историей связей, а внешние идентификаторы хранятся
 * по системам-источникам. Личные контакты, паспортные и зарплатные данные
 * в эту схему намеренно не входят.
 */
class pgMigration_Prl_300_001_Employee_Directory extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
INSERT INTO roles (name, description) VALUES
    ('hr_viewer', 'Просмотр кадровой структуры своей организации'),
    ('hr_admin', 'Управление кадровыми данными своей организации')
ON CONFLICT (name) DO NOTHING;

CREATE OR REPLACE FUNCTION prl_300_touch_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END
$$ LANGUAGE plpgsql;

CREATE TABLE IF NOT EXISTS "prl_300_organizations" (
    "id"         BIGSERIAL PRIMARY KEY,
    "code"       VARCHAR(64)  NOT NULL,
    "name"       VARCHAR(255) NOT NULL,
    "legal_name" VARCHAR(512) NOT NULL DEFAULT '',
    "status"     VARCHAR(20)  NOT NULL DEFAULT 'active',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_organizations_code_check"
        CHECK ("code" ~ '^[a-z0-9][a-z0-9._-]*$'),
    CONSTRAINT "prl_300_organizations_status_check"
        CHECK ("status" IN ('active', 'archived'))
);
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_organizations_code_uq"
    ON "prl_300_organizations" (lower("code"));
DROP TRIGGER IF EXISTS "prl_300_organizations_touch" ON "prl_300_organizations";
CREATE TRIGGER "prl_300_organizations_touch"
    BEFORE UPDATE ON "prl_300_organizations"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

CREATE TABLE IF NOT EXISTS "prl_300_sites" (
    "id"              BIGSERIAL PRIMARY KEY,
    "organization_id" BIGINT       NOT NULL REFERENCES "prl_300_organizations" ("id") ON DELETE RESTRICT,
    "code"            VARCHAR(64)  NOT NULL,
    "name"            VARCHAR(255) NOT NULL,
    "city"            VARCHAR(120) NOT NULL DEFAULT '',
    "address"         TEXT         NOT NULL DEFAULT '',
    "timezone"        VARCHAR(64)  NOT NULL DEFAULT 'UTC',
    "status"          VARCHAR(20)  NOT NULL DEFAULT 'active',
    "created_at"      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_sites_id_org_uq" UNIQUE ("id", "organization_id"),
    CONSTRAINT "prl_300_sites_code_check"
        CHECK ("code" ~ '^[a-z0-9][a-z0-9._-]*$'),
    CONSTRAINT "prl_300_sites_status_check"
        CHECK ("status" IN ('active', 'archived'))
);
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_sites_org_code_uq"
    ON "prl_300_sites" ("organization_id", lower("code"));
CREATE INDEX IF NOT EXISTS "prl_300_sites_org_idx"
    ON "prl_300_sites" ("organization_id", "status", "name");
DROP TRIGGER IF EXISTS "prl_300_sites_touch" ON "prl_300_sites";
CREATE TRIGGER "prl_300_sites_touch"
    BEFORE UPDATE ON "prl_300_sites"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

CREATE TABLE IF NOT EXISTS "prl_300_departments" (
    "id"              BIGSERIAL PRIMARY KEY,
    "organization_id" BIGINT       NOT NULL REFERENCES "prl_300_organizations" ("id") ON DELETE RESTRICT,
    "parent_id"       BIGINT       NULL,
    "site_id"         BIGINT       NULL,
    "code"            VARCHAR(64)  NOT NULL,
    "name"            VARCHAR(255) NOT NULL,
    "sort_order"      INTEGER      NOT NULL DEFAULT 100,
    "status"          VARCHAR(20)  NOT NULL DEFAULT 'active',
    "created_at"      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_departments_id_org_uq" UNIQUE ("id", "organization_id"),
    CONSTRAINT "prl_300_departments_parent_fk"
        FOREIGN KEY ("parent_id", "organization_id")
        REFERENCES "prl_300_departments" ("id", "organization_id") ON DELETE RESTRICT,
    CONSTRAINT "prl_300_departments_site_fk"
        FOREIGN KEY ("site_id", "organization_id")
        REFERENCES "prl_300_sites" ("id", "organization_id") ON DELETE RESTRICT,
    CONSTRAINT "prl_300_departments_code_check"
        CHECK ("code" ~ '^[a-z0-9][a-z0-9._-]*$'),
    CONSTRAINT "prl_300_departments_status_check"
        CHECK ("status" IN ('active', 'archived')),
    CONSTRAINT "prl_300_departments_not_self_check"
        CHECK ("parent_id" IS NULL OR "parent_id" <> "id"),
    CONSTRAINT "prl_300_departments_sort_check"
        CHECK ("sort_order" >= 0 AND "sort_order" <= 10000)
);
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_departments_org_code_uq"
    ON "prl_300_departments" ("organization_id", lower("code"));
CREATE INDEX IF NOT EXISTS "prl_300_departments_tree_idx"
    ON "prl_300_departments" ("organization_id", "parent_id", "sort_order", "name");
DROP TRIGGER IF EXISTS "prl_300_departments_touch" ON "prl_300_departments";
CREATE TRIGGER "prl_300_departments_touch"
    BEFORE UPDATE ON "prl_300_departments"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

CREATE TABLE IF NOT EXISTS "prl_300_employees" (
    "id"                 BIGSERIAL PRIMARY KEY,
    "organization_id"    BIGINT       NOT NULL REFERENCES "prl_300_organizations" ("id") ON DELETE RESTRICT,
    "department_id"      BIGINT       NULL,
    "site_id"            BIGINT       NULL,
    "manager_employee_id" BIGINT      NULL,
    "personnel_number"   VARCHAR(64)  NOT NULL DEFAULT '',
    "last_name"          VARCHAR(120) NOT NULL,
    "first_name"         VARCHAR(120) NOT NULL,
    "middle_name"        VARCHAR(120) NOT NULL DEFAULT '',
    "position_title"     VARCHAR(255) NOT NULL DEFAULT '',
    "work_email"         VARCHAR(254) NOT NULL DEFAULT '',
    "work_phone"         VARCHAR(64)  NOT NULL DEFAULT '',
    "employment_status"  VARCHAR(20)  NOT NULL DEFAULT 'active',
    "hired_on"           DATE         NULL,
    "terminated_on"      DATE         NULL,
    "source_system"      VARCHAR(64)  NOT NULL DEFAULT 'manual',
    "source_updated_at"  TIMESTAMPTZ  NULL,
    "created_at"         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_employees_id_org_uq" UNIQUE ("id", "organization_id"),
    CONSTRAINT "prl_300_employees_department_fk"
        FOREIGN KEY ("department_id", "organization_id")
        REFERENCES "prl_300_departments" ("id", "organization_id") ON DELETE RESTRICT,
    CONSTRAINT "prl_300_employees_site_fk"
        FOREIGN KEY ("site_id", "organization_id")
        REFERENCES "prl_300_sites" ("id", "organization_id") ON DELETE RESTRICT,
    CONSTRAINT "prl_300_employees_manager_fk"
        FOREIGN KEY ("manager_employee_id", "organization_id")
        REFERENCES "prl_300_employees" ("id", "organization_id") ON DELETE RESTRICT,
    CONSTRAINT "prl_300_employees_status_check"
        CHECK ("employment_status" IN ('active', 'on_leave', 'suspended', 'terminated')),
    CONSTRAINT "prl_300_employees_manager_check"
        CHECK ("manager_employee_id" IS NULL OR "manager_employee_id" <> "id"),
    CONSTRAINT "prl_300_employees_dates_check"
        CHECK ("terminated_on" IS NULL OR "hired_on" IS NULL OR "terminated_on" >= "hired_on"),
    CONSTRAINT "prl_300_employees_terminated_check"
        CHECK ("terminated_on" IS NULL OR "employment_status" = 'terminated')
);
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_employees_personnel_uq"
    ON "prl_300_employees" ("organization_id", lower("personnel_number"))
    WHERE "personnel_number" <> '';
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_employees_email_uq"
    ON "prl_300_employees" ("organization_id", lower("work_email"))
    WHERE "work_email" <> '';
CREATE INDEX IF NOT EXISTS "prl_300_employees_directory_idx"
    ON "prl_300_employees" ("organization_id", "employment_status", "last_name", "first_name");
CREATE INDEX IF NOT EXISTS "prl_300_employees_department_idx"
    ON "prl_300_employees" ("department_id", "employment_status");
CREATE INDEX IF NOT EXISTS "prl_300_employees_manager_idx"
    ON "prl_300_employees" ("manager_employee_id");
DROP TRIGGER IF EXISTS "prl_300_employees_touch" ON "prl_300_employees";
CREATE TRIGGER "prl_300_employees_touch"
    BEFORE UPDATE ON "prl_300_employees"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

ALTER TABLE "prl_300_departments"
    ADD COLUMN IF NOT EXISTS "manager_employee_id" BIGINT NULL;
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'prl_300_departments_manager_fk'
    ) THEN
        ALTER TABLE "prl_300_departments"
            ADD CONSTRAINT "prl_300_departments_manager_fk"
            FOREIGN KEY ("manager_employee_id", "organization_id")
            REFERENCES "prl_300_employees" ("id", "organization_id") ON DELETE RESTRICT;
    END IF;
END
$$;
CREATE INDEX IF NOT EXISTS "prl_300_departments_manager_idx"
    ON "prl_300_departments" ("manager_employee_id");

CREATE TABLE IF NOT EXISTS "prl_300_external_identities" (
    "id"                BIGSERIAL PRIMARY KEY,
    "employee_id"       BIGINT       NOT NULL REFERENCES "prl_300_employees" ("id") ON DELETE CASCADE,
    "system_code"       VARCHAR(64)  NOT NULL,
    "external_id"       VARCHAR(191) NOT NULL,
    "external_login"    VARCHAR(191) NOT NULL DEFAULT '',
    "is_primary"        BOOLEAN      NOT NULL DEFAULT FALSE,
    "status"            VARCHAR(20)  NOT NULL DEFAULT 'active',
    "valid_from"        DATE         NULL,
    "valid_to"          DATE         NULL,
    "source_updated_at" TIMESTAMPTZ  NULL,
    "created_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_external_identities_system_check"
        CHECK ("system_code" ~ '^[a-z0-9][a-z0-9._-]*$'),
    CONSTRAINT "prl_300_external_identities_status_check"
        CHECK ("status" IN ('active', 'disabled')),
    CONSTRAINT "prl_300_external_identities_dates_check"
        CHECK ("valid_to" IS NULL OR "valid_from" IS NULL OR "valid_to" >= "valid_from"),
    CONSTRAINT "prl_300_external_identities_uq"
        UNIQUE ("system_code", "external_id")
);
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_external_identities_primary_uq"
    ON "prl_300_external_identities" ("employee_id", "system_code")
    WHERE "is_primary" AND "status" = 'active';
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_external_identities_login_uq"
    ON "prl_300_external_identities" ("system_code", lower("external_login"))
    WHERE "status" = 'active' AND "external_login" <> '';
CREATE INDEX IF NOT EXISTS "prl_300_external_identities_employee_idx"
    ON "prl_300_external_identities" ("employee_id", "system_code");
DROP TRIGGER IF EXISTS "prl_300_external_identities_touch" ON "prl_300_external_identities";
CREATE TRIGGER "prl_300_external_identities_touch"
    BEFORE UPDATE ON "prl_300_external_identities"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

CREATE TABLE IF NOT EXISTS "prl_300_user_links" (
    "id"               BIGSERIAL PRIMARY KEY,
    "employee_id"      BIGINT       NOT NULL REFERENCES "prl_300_employees" ("id") ON DELETE CASCADE,
    "user_id"          INTEGER      NOT NULL REFERENCES "users" ("id") ON DELETE CASCADE,
    "status"           VARCHAR(20)  NOT NULL DEFAULT 'active',
    "linked_via"       VARCHAR(32)  NOT NULL DEFAULT 'manual',
    "linked_by_user_id" INTEGER     NULL REFERENCES "users" ("id") ON DELETE SET NULL,
    "linked_at"        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "revoked_at"       TIMESTAMPTZ  NULL,
    "revoke_reason"    VARCHAR(255) NOT NULL DEFAULT '',
    "note"             VARCHAR(255) NOT NULL DEFAULT '',
    "created_at"       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_user_links_status_check"
        CHECK ("status" IN ('active', 'revoked')),
    CONSTRAINT "prl_300_user_links_revoked_check"
        CHECK (("status" = 'active' AND "revoked_at" IS NULL)
            OR ("status" = 'revoked' AND "revoked_at" IS NOT NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_user_links_employee_active_uq"
    ON "prl_300_user_links" ("employee_id") WHERE "status" = 'active';
CREATE UNIQUE INDEX IF NOT EXISTS "prl_300_user_links_user_active_uq"
    ON "prl_300_user_links" ("user_id") WHERE "status" = 'active';
CREATE INDEX IF NOT EXISTS "prl_300_user_links_history_idx"
    ON "prl_300_user_links" ("employee_id", "linked_at" DESC);
DROP TRIGGER IF EXISTS "prl_300_user_links_touch" ON "prl_300_user_links";
CREATE TRIGGER "prl_300_user_links_touch"
    BEFORE UPDATE ON "prl_300_user_links"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

CREATE TABLE IF NOT EXISTS "prl_300_import_batches" (
    "id"                 BIGSERIAL PRIMARY KEY,
    "source_system"      VARCHAR(64)  NOT NULL,
    "file_name"          VARCHAR(255) NOT NULL DEFAULT '',
    "file_sha256"        CHAR(64)     NOT NULL,
    "mode"               VARCHAR(20)  NOT NULL DEFAULT 'delta',
    "status"             VARCHAR(20)  NOT NULL DEFAULT 'staged',
    "organization_codes" JSONB        NOT NULL DEFAULT '[]'::jsonb,
    "total_rows"         INTEGER      NOT NULL DEFAULT 0,
    "valid_rows"         INTEGER      NOT NULL DEFAULT 0,
    "error_rows"         INTEGER      NOT NULL DEFAULT 0,
    "created_count"      INTEGER      NOT NULL DEFAULT 0,
    "updated_count"      INTEGER      NOT NULL DEFAULT 0,
    "skipped_count"      INTEGER      NOT NULL DEFAULT 0,
    "summary"            JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "created_by_user_id" INTEGER      NULL REFERENCES "users" ("id") ON DELETE SET NULL,
    "applied_by_user_id" INTEGER      NULL REFERENCES "users" ("id") ON DELETE SET NULL,
    "applied_at"         TIMESTAMPTZ  NULL,
    "created_at"         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"         TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_import_batches_source_check"
        CHECK ("source_system" ~ '^[a-z0-9][a-z0-9._-]*$'),
    CONSTRAINT "prl_300_import_batches_mode_check" CHECK ("mode" = 'delta'),
    CONSTRAINT "prl_300_import_batches_status_check"
        CHECK ("status" IN ('staged', 'validated', 'rejected', 'applied', 'failed')),
    CONSTRAINT "prl_300_import_batches_file_uq" UNIQUE ("source_system", "file_sha256")
);
CREATE INDEX IF NOT EXISTS "prl_300_import_batches_recent_idx"
    ON "prl_300_import_batches" ("created_at" DESC);
DROP TRIGGER IF EXISTS "prl_300_import_batches_touch" ON "prl_300_import_batches";
CREATE TRIGGER "prl_300_import_batches_touch"
    BEFORE UPDATE ON "prl_300_import_batches"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

CREATE TABLE IF NOT EXISTS "prl_300_import_rows" (
    "id"                 BIGSERIAL PRIMARY KEY,
    "batch_id"           BIGINT      NOT NULL REFERENCES "prl_300_import_batches" ("id") ON DELETE CASCADE,
    "row_number"         INTEGER     NOT NULL,
    "payload"            JSONB       NOT NULL DEFAULT '{}'::jsonb,
    "status"             VARCHAR(20) NOT NULL DEFAULT 'pending',
    "error"              TEXT        NOT NULL DEFAULT '',
    "result_employee_id" BIGINT      NULL REFERENCES "prl_300_employees" ("id") ON DELETE SET NULL,
    "created_at"         TIMESTAMPTZ NOT NULL DEFAULT now(),
    "updated_at"         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT "prl_300_import_rows_status_check"
        CHECK ("status" IN ('pending', 'valid', 'error', 'applied', 'skipped')),
    CONSTRAINT "prl_300_import_rows_batch_row_uq" UNIQUE ("batch_id", "row_number")
);
CREATE INDEX IF NOT EXISTS "prl_300_import_rows_batch_idx"
    ON "prl_300_import_rows" ("batch_id", "row_number");
DROP TRIGGER IF EXISTS "prl_300_import_rows_touch" ON "prl_300_import_rows";
CREATE TRIGGER "prl_300_import_rows_touch"
    BEFORE UPDATE ON "prl_300_import_rows"
    FOR EACH ROW EXECUTE FUNCTION prl_300_touch_updated_at();

CREATE TABLE IF NOT EXISTS "prl_300_audit_log" (
    "id"              BIGSERIAL PRIMARY KEY,
    "actor_user_id"   INTEGER      NULL REFERENCES "users" ("id") ON DELETE SET NULL,
    "organization_id" BIGINT       NULL REFERENCES "prl_300_organizations" ("id") ON DELETE SET NULL,
    "entity_type"     VARCHAR(40)  NOT NULL,
    "entity_id"       BIGINT       NULL,
    "action"          VARCHAR(80)  NOT NULL,
    "details"         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    "created_at"      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "prl_300_audit_log_recent_idx"
    ON "prl_300_audit_log" ("created_at" DESC);
CREATE INDEX IF NOT EXISTS "prl_300_audit_log_entity_idx"
    ON "prl_300_audit_log" ("entity_type", "entity_id", "created_at" DESC);

COMMENT ON TABLE "prl_300_employees" IS 'PRL-300: рабочий профиль сотрудника без личных и зарплатных данных';
COMMENT ON TABLE "prl_300_user_links" IS 'PRL-300: отдельный контракт сопоставления Auth-пользователя и сотрудника';
COMMENT ON TABLE "prl_300_external_identities" IS 'PRL-300: внешние идентификаторы сотрудника по системам-источникам';
COMMENT ON TABLE "prl_300_import_rows" IS 'PRL-300: промежуточные строки кадрового импорта; подлежат очистке по retention';
SQL;

        $this->run_query($sql);
    }

    public function down()
    {
        $sql = <<<SQL
DROP TABLE IF EXISTS "prl_300_audit_log" CASCADE;
DROP TABLE IF EXISTS "prl_300_import_rows" CASCADE;
DROP TABLE IF EXISTS "prl_300_import_batches" CASCADE;
DROP TABLE IF EXISTS "prl_300_user_links" CASCADE;
DROP TABLE IF EXISTS "prl_300_external_identities" CASCADE;
DROP TABLE IF EXISTS "prl_300_employees" CASCADE;
DROP TABLE IF EXISTS "prl_300_departments" CASCADE;
DROP TABLE IF EXISTS "prl_300_sites" CASCADE;
DROP TABLE IF EXISTS "prl_300_organizations" CASCADE;
DROP FUNCTION IF EXISTS prl_300_touch_updated_at() CASCADE;
DELETE FROM roles r
WHERE r.name IN ('hr_viewer', 'hr_admin')
  AND NOT EXISTS (SELECT 1 FROM roles_users ru WHERE ru.role_id = r.id);
SQL;

        $this->run_query($sql);
    }
}
