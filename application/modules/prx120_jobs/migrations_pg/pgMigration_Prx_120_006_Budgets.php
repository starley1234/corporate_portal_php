<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция prx120/006: бюджеты токенов.
 *
 * prx_120_budgets — индивидуальные лимиты (день/месяц) на владельца
 * `visitor_id` (`u:<id>` или кука гостя); умолчания по ролям — в
 * config/prx120_budget.php. prx_120_budget_alerts — отметки «предупредили»:
 * одно уведомление на владельца, день и порог (90 %, 100 %), а не на каждую задачу.
 */
class pgMigration_Prx_120_006_Budgets extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "prx_120_budgets" (
    "visitor_id"     VARCHAR(64) PRIMARY KEY,
    "daily_tokens"   BIGINT      NOT NULL DEFAULT 0,
    "monthly_tokens" BIGINT      NOT NULL DEFAULT 0,
    "note"           VARCHAR(200) NOT NULL DEFAULT '',
    "updated_by"     BIGINT      NULL,
    "updated_at"     TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "prx_120_budgets" IS 'PRX-120 индивидуальные бюджеты токенов (0 = по умолчанию роли, -1 = без лимита)';
CREATE TABLE IF NOT EXISTS "prx_120_budget_alerts" (
    "visitor_id" VARCHAR(64) NOT NULL,
    "period"     VARCHAR(10) NOT NULL,
    "level"      SMALLINT    NOT NULL,
    "created_at" TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY ("visitor_id", "period", "level")
);
COMMENT ON TABLE "prx_120_budget_alerts" IS 'PRX-120 отметки предупреждений о бюджете: владелец × период (YYYY-MM-DD / YYYY-MM) × порог';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "prx_120_budget_alerts"; DROP TABLE IF EXISTS "prx_120_budgets"')->execute();
    }
}
