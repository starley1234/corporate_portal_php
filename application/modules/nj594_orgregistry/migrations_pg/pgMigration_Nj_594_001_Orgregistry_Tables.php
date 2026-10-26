<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-594/001: «Справочник организаций» (nj594_orgregistry).
 *
 * nj_594_orgs — карточки организаций/ИП (ЕГРЮЛ/ЕГРИП, данные ФНС);
 * nj_594_links — связи «организация → артефакты модулей» (nj580 пакеты и др.).
 * DDL полностью повторяет Service_Nj594_Orgs::ensure_tables().
 */
class pgMigration_Nj_594_001_Orgregistry_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_594_orgs" (
    "id"         BIGSERIAL PRIMARY KEY,
    "ogrn"       VARCHAR(15)  NOT NULL UNIQUE,
    "inn"        VARCHAR(12)  NOT NULL DEFAULT '',
    "kpp"        VARCHAR(9)   NOT NULL DEFAULT '',
    "form"       VARCHAR(8)   NOT NULL DEFAULT 'ЮЛ',
    "short_name" VARCHAR(300) NOT NULL DEFAULT '',
    "full_name"  VARCHAR(500) NOT NULL DEFAULT '',
    "address"    VARCHAR(500) NOT NULL DEFAULT '',
    "okved"      VARCHAR(16)  NOT NULL DEFAULT '',
    "okved_name" VARCHAR(300) NOT NULL DEFAULT '',
    "status"     VARCHAR(120) NOT NULL DEFAULT '',
    "reg_date"   VARCHAR(20)  NOT NULL DEFAULT '',
    "director"   VARCHAR(300) NOT NULL DEFAULT '',
    "source"     VARCHAR(40)  NOT NULL DEFAULT 'seed',
    "raw"        JSONB        NULL,
    "fetched_at" TIMESTAMPTZ  NULL,
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_594_orgs_inn_idx"  ON "nj_594_orgs" ("inn");
CREATE INDEX IF NOT EXISTS "nj_594_orgs_name_idx" ON "nj_594_orgs" (lower("short_name"));
COMMENT ON TABLE  "nj_594_orgs" IS 'NJ-594 справочник организаций (ЕГРЮЛ/ЕГРИП): карточки по данным ФНС';
COMMENT ON COLUMN "nj_594_orgs"."form"   IS 'ЮЛ | ИП';
COMMENT ON COLUMN "nj_594_orgs"."source" IS 'seed | fns | nj580 | import:<метка>';
COMMENT ON COLUMN "nj_594_orgs"."raw"    IS 'Полный ответ источника (JSON)';

CREATE TABLE IF NOT EXISTS "nj_594_links" (
    "id"         BIGSERIAL PRIMARY KEY,
    "org_id"     BIGINT       NOT NULL REFERENCES "nj_594_orgs"("id") ON DELETE CASCADE,
    "module"     VARCHAR(24)  NOT NULL,
    "item_code"  VARCHAR(24)  NOT NULL,
    "item_url"   VARCHAR(200) NOT NULL DEFAULT '',
    "title"      VARCHAR(300) NOT NULL DEFAULT '',
    "kind"       VARCHAR(40)  NOT NULL DEFAULT '',
    "created_at" TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE ("org_id", "module", "item_code")
);
CREATE INDEX IF NOT EXISTS "nj_594_links_item_idx" ON "nj_594_links" ("module", "item_code");
COMMENT ON TABLE "nj_594_links" IS 'NJ-594 связи организация -> артефакты модулей';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_594_links"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_594_orgs"')->execute();
    }
}
