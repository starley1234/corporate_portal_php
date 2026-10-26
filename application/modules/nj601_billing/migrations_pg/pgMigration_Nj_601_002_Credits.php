<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj601_billing 002: кредиты AI-операций.
 *
 * nj_601_credits — баланс субъекта (субъект = 'u:<user_id>' или 'g:<ubid>');
 * free_month/free_left — бесплатная норма на календарный месяц (config free_per_month);
 * nj_601_ledger  — журнал движения: списание за операцию, пополнение пакетом,
 *                  ручное начисление админом. Баланс не уходит в минус.
 */
class pgMigration_Nj_601_002_Credits extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_601_credits" (
    "subject"    VARCHAR(70)  PRIMARY KEY,
    "balance"    INTEGER      NOT NULL DEFAULT 0,
    "free_month" VARCHAR(7)   NOT NULL DEFAULT '',
    "free_left"  INTEGER      NOT NULL DEFAULT 0,
    "updated_at" TIMESTAMPTZ  NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_601_credits" IS 'nj601_billing: кредиты AI-операций; subject = u:<user_id> | g:<ubid>';
COMMENT ON COLUMN "nj_601_credits"."free_month" IS 'месяц, на который начислена бесплатная норма (YYYY-MM)';

CREATE TABLE IF NOT EXISTS "nj_601_ledger" (
    "id"            BIGSERIAL PRIMARY KEY,
    "subject"       VARCHAR(70) NOT NULL,
    "delta"         INTEGER     NOT NULL DEFAULT 0,
    "balance_after" INTEGER     NOT NULL DEFAULT 0,
    "module"        VARCHAR(40) NOT NULL DEFAULT '',
    "note"          VARCHAR(200) NOT NULL DEFAULT '',
    "invoice_id"    BIGINT      NULL,
    "created_at"    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS "nj_601_ledger_subject_idx" ON "nj_601_ledger" ("subject", "id" DESC);
COMMENT ON TABLE "nj_601_ledger" IS 'nj601_billing: движение кредитов; delta<0 — списание, >0 — пополнение';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_601_ledger"')->execute();
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_601_credits"')->execute();
    }
}
