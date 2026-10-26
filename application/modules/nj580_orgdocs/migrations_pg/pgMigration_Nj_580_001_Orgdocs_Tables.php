<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-580/001: «Документы организации» (nj580_orgdocs).
 *
 * Пакеты → документы (структурированный контент LLM) → файлы (DOCX/PDF/XLSX/ZIP).
 * Оформление — по ГОСТ Р 7.0.97-2016 (поля, шрифты, нумерация) — делает воркер.
 */
class pgMigration_Nj_580_001_Orgdocs_Tables extends pgMigration
{
    public function up()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS "nj_580_packages" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NOT NULL,
    "title"       VARCHAR(200) NOT NULL DEFAULT '',
    "org_name"    VARCHAR(300) NOT NULL DEFAULT '',
    "org_info"    JSONB        NULL,
    "doc_types"   JSONB        NULL,
    "use_search"  BOOLEAN      NOT NULL DEFAULT FALSE,
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "summary"     TEXT         NOT NULL DEFAULT '',
    "job_id"      BIGINT       NULL,
    "usage"       JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_580_packages_user_idx" ON "nj_580_packages" ("user_id", "id" DESC);
COMMENT ON TABLE  "nj_580_packages" IS 'NJ-580 пакеты документов: new|running|done|error';
COMMENT ON COLUMN "nj_580_packages"."org_info" IS 'Сведения об организации: отрасль, размер, структура, требования';
COMMENT ON COLUMN "nj_580_packages"."doc_types" IS 'Ключи типов документов (config nj580_orgdocs.php) или ["auto"]';

CREATE TABLE IF NOT EXISTS "nj_580_documents" (
    "id"          BIGSERIAL PRIMARY KEY,
    "package_id"  BIGINT      NOT NULL REFERENCES "nj_580_packages"("id") ON DELETE CASCADE,
    "doc_key"     VARCHAR(64) NOT NULL DEFAULT '',
    "ord"         INT         NOT NULL DEFAULT 0,
    "title"       VARCHAR(300) NOT NULL DEFAULT '',
    "content"     JSONB       NULL,
    "status"      VARCHAR(12) NOT NULL DEFAULT 'new',
    "error"       TEXT        NULL,
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ NULL
);
CREATE INDEX IF NOT EXISTS "nj_580_documents_pkg_idx" ON "nj_580_documents" ("package_id", "ord");
COMMENT ON TABLE  "nj_580_documents" IS 'NJ-580 документы пакета: new|writing|done|error; content = секции JSON';
COMMENT ON COLUMN "nj_580_documents"."content" IS '{title, subtitle, needs_title_page, approve_line, sections:[{heading, blocks:[{type:p|ul|table|note,...}]}]}';

CREATE TABLE IF NOT EXISTS "nj_580_files" (
    "id"          BIGSERIAL PRIMARY KEY,
    "package_id"  BIGINT      NOT NULL REFERENCES "nj_580_packages"("id") ON DELETE CASCADE,
    "document_id" BIGINT      NULL REFERENCES "nj_580_documents"("id") ON DELETE CASCADE,
    "kind"        VARCHAR(8)  NOT NULL,
    "path"        VARCHAR(255) NOT NULL,
    "size"        BIGINT      NOT NULL DEFAULT 0,
    "created_at"  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_580_files_pkg_idx" ON "nj_580_files" ("package_id", "kind");
COMMENT ON COLUMN "nj_580_files"."kind" IS 'docx|pdf|xlsx|zip';

CREATE TABLE IF NOT EXISTS "nj_580_usage" (
    "id"                BIGSERIAL PRIMARY KEY,
    "package_id"        BIGINT      NOT NULL,
    "document_id"       BIGINT      NULL,
    "job_id"            BIGINT      NULL,
    "phase"             VARCHAR(40) NOT NULL DEFAULT '',
    "model"             VARCHAR(120) NOT NULL DEFAULT '',
    "prompt_tokens"     INT         NOT NULL DEFAULT 0,
    "completion_tokens" INT         NOT NULL DEFAULT 0,
    "duration_ms"       INT         NOT NULL DEFAULT 0,
    "created_at"        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_580_usage_pkg_idx" ON "nj_580_usage" ("package_id", "id");
COMMENT ON TABLE "nj_580_usage" IS 'NJ-580 затраты LLM построчно (план/черновик/проверка)';
SQL;

        DB::query(NULL, $sql)->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_580_usage", "nj_580_files", "nj_580_documents", "nj_580_packages" CASCADE')->execute();
    }
}
