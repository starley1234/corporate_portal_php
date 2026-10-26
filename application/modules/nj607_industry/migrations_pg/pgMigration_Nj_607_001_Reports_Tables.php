<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj607_industry 001: отчёты «Карта отрасли» и заказы разведки.
 *
 * kind = self   — самостоятельный сбор (воркер + LLM, списание кредитов);
 * kind = order  — заказ «Разведки под запрос» (ручной сервис владельца:
 *                 глубокая выверка 30–50 производителей + 20 писем);
 * admin видит orders в /nj607/admin и закрывает их вручную.
 */
class pgMigration_Nj_607_001_Reports_Tables extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_607_reports" (
    "id"          BIGSERIAL PRIMARY KEY,
    "code"        VARCHAR(12)  NOT NULL UNIQUE,
    "user_id"     BIGINT       NULL,
    "visitor_id"  VARCHAR(64)  NOT NULL DEFAULT '',
    "kind"        VARCHAR(10)  NOT NULL DEFAULT 'self',
    "industry"    VARCHAR(300) NOT NULL DEFAULT '',
    "region"      VARCHAR(120) NOT NULL DEFAULT '',
    "contact"     VARCHAR(300) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "error"       TEXT         NULL,
    "progress"    VARCHAR(300) NOT NULL DEFAULT '',
    "report_md"   TEXT         NULL,
    "job_id"      BIGINT       NULL,
    "model_used"  VARCHAR(120) NOT NULL DEFAULT '',
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "updated_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "finished_at" TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_607_reports_owner_idx" ON "nj_607_reports" ("user_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_607_reports_kind_idx" ON "nj_607_reports" ("kind", "status", "id" DESC);
COMMENT ON TABLE "nj_607_reports" IS 'nj607_industry: self=авто-карта отрасли, order=заказ ручной разведки; status new|running|done|error|closed';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_607_reports"')->execute();
    }
}
