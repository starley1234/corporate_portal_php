<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj601_billing 001: nj_601_invoices — счета Robokassa.
 * id = InvId в Robokassa (1..2147483647 — BIGSERIAL подходит).
 */
class pgMigration_Nj_601_001_Invoices_Table extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_601_invoices" (
    "id"          BIGSERIAL PRIMARY KEY,
    "visitor_id"  VARCHAR(64)  NOT NULL DEFAULT '',
    "user_id"     BIGINT       NULL,
    "service"     VARCHAR(60)  NOT NULL DEFAULT '',
    "description" VARCHAR(200) NOT NULL DEFAULT '',
    "amount_kop"  INTEGER      NOT NULL DEFAULT 0,
    "email"       VARCHAR(120) NOT NULL DEFAULT '',
    "status"      VARCHAR(12)  NOT NULL DEFAULT 'new',
    "out_sum"     VARCHAR(20)  NOT NULL DEFAULT '',
    "fee"         VARCHAR(20)  NOT NULL DEFAULT '',
    "raw"         JSONB        NULL,
    "created_at"  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    "paid_at"     TIMESTAMPTZ  NULL
);
CREATE INDEX IF NOT EXISTS "nj_601_invoices_visitor_idx" ON "nj_601_invoices" ("visitor_id", "id" DESC);
CREATE INDEX IF NOT EXISTS "nj_601_invoices_status_idx" ON "nj_601_invoices" ("status", "id" DESC);
COMMENT ON TABLE "nj_601_invoices" IS 'NJ-601 счета Robokassa: new|paid|failed; id = InvIdRobokassa; raw — последний колбэк';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_601_invoices"')->execute();
    }
}
