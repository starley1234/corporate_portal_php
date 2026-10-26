<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция nj160_mcp 002: настройки хаба (KV).
 * allowed_categories — какие категории инструментов видны агентам;
 * disabled_tools — точечно выключенные инструменты.
 */
class pgMigration_Nj_160_002_Settings extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
CREATE TABLE IF NOT EXISTS "nj_160_settings" (
    "key"        VARCHAR(60) PRIMARY KEY,
    "value"      JSONB NULL,
    "updated_at" TIMESTAMPTZ NOT NULL DEFAULT now()
);
COMMENT ON TABLE "nj_160_settings" IS 'nj160_mcp: конфигурация хаба (allowed_categories, disabled_tools)';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, 'DROP TABLE IF EXISTS "nj_160_settings"')->execute();
    }
}
