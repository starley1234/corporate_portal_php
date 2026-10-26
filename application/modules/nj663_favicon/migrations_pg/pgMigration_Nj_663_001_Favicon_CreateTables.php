<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj663_favicon 001: журнал наборов иконок.
 *
 * nj_663_packs — код набора (он же имя каталога в storage/nj663), что именно
 * рисовали, список файлов и готовый HTML-сниппет. По коду набор открывается
 * повторно: /favicon-gen/p/<code>. Срок жизни — ttl_days из конфига, выметает
 * Service_Nj663_Favicon::sweep() (и nj100/cleanup по контракту манифеста).
 */
class pgMigration_Nj_663_001_Favicon_CreateTables extends pgMigration
{
	public function up()
	{
		DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_663_packs" (
    "id"             BIGSERIAL    PRIMARY KEY,
    "code"           VARCHAR(24)  NOT NULL UNIQUE,
    "visitor_uid"    VARCHAR(40)  NOT NULL DEFAULT '',
    "client_ip"      VARCHAR(64)  NOT NULL DEFAULT '',
    "kind"           VARCHAR(16)  NOT NULL DEFAULT 'upload',
    "source_json"    JSONB        NULL,
    "options_json"   JSONB        NULL,
    "files_json"     JSONB        NULL,
    "bytes_total"    INTEGER      NOT NULL DEFAULT 0,
    "html"           TEXT         NOT NULL DEFAULT '',
    "base_path"      VARCHAR(120) NOT NULL DEFAULT '/',
    "downloads"      INTEGER      NOT NULL DEFAULT 0,
    "created_at"     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "last_access_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_663_packs_code_idx"    ON "nj_663_packs" ("code");
CREATE INDEX IF NOT EXISTS "nj_663_packs_uid_idx"     ON "nj_663_packs" ("visitor_uid", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_663_packs_ip_idx"      ON "nj_663_packs" ("client_ip", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_663_packs_created_idx" ON "nj_663_packs" ("created_at");
COMMENT ON TABLE "nj_663_packs" IS 'NJ-663 комплекты favicon: код = каталог в storage/nj663, files_json — состав, html — сниппет для <head>';
SQL
		)->execute();
	}

	public function down()
	{
		DB::query(NULL, 'DROP TABLE IF EXISTS "nj_663_packs"')->execute();
	}
}
