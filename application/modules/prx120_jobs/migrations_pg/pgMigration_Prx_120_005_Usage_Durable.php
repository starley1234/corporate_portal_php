<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Миграция prx120/005: prx_120_usage живёт дольше задачи.
 *
 * Раньше job_id ссылался на prx_120_jobs ON DELETE CASCADE: уборка монитора
 * («Очистить всё», удаление задачи, правило 60 дней) стирала и учёт токенов —
 * на /graph3d/usage и /prx120/usage оставались только последние значения,
 * история пропадала (жалоба владельца 25.10.2026). Теперь:
 *  - внешний ключ снят: строка учёта переживает удаление задачи;
 *  - payload_brief — скалярный срез payload (prompt, project_id…), чтобы
 *    карточка генерации читалась и без самой задачи;
 *  - status — итог задачи (done/failed) на момент отчёта.
 */
class pgMigration_Prx_120_005_Usage_Durable extends pgMigration
{
    public function up()
    {
        DB::query(NULL, <<<SQL
ALTER TABLE "prx_120_usage" DROP CONSTRAINT IF EXISTS "prx_120_usage_job_id_fkey";
ALTER TABLE "prx_120_usage" ADD COLUMN IF NOT EXISTS "payload_brief" JSONB NOT NULL DEFAULT '{}'::jsonb;
ALTER TABLE "prx_120_usage" ADD COLUMN IF NOT EXISTS "status" VARCHAR(20) NOT NULL DEFAULT '';
CREATE INDEX IF NOT EXISTS "prx_120_usage_created_idx" ON "prx_120_usage" ("created_at");
COMMENT ON COLUMN "prx_120_usage"."payload_brief" IS 'скалярные поля payload задачи (prompt, project_id…), строки обрезаны до 1000 симв.';
SQL
        )->execute();
    }

    public function down()
    {
        DB::query(NULL, <<<SQL
ALTER TABLE "prx_120_usage" DROP COLUMN IF EXISTS "payload_brief";
ALTER TABLE "prx_120_usage" DROP COLUMN IF EXISTS "status";
DROP INDEX IF EXISTS "prx_120_usage_created_idx";
SQL
        )->execute();
    }
}
